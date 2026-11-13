<?php

namespace App\Tests\Service;

use App\Entity\Admin;
use App\Entity\User;
use App\Repository\AdminRepository;
use App\Repository\RefreshTokenRepository;
use App\Repository\UserRepository;
use App\Security\Exception\RefreshDeniedException;
use App\Service\Auth\RefreshTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * P0 — sessions séparées : client longue durée, admin courte ; rotation, délai de grâce, révocation.
 */
final class RefreshTokenServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private RefreshTokenService $service;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        self::at('2026-11-13 10:00:00');
        $this->service = static::getContainer()->get('test.refresh_token_service');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        foreach (['refresh_tokens', 'users', 'admins'] as $table) {
            $this->em->getConnection()->executeStatement('DELETE FROM ' . $table);
        }
    }

    public function testClientSessionIsLongAndScopedToApi(): void
    {
        $cookie = $this->service->createForUser($this->user(), new Request())['cookie'];

        self::assertSame('drc_refresh', $cookie->getName());
        self::assertSame('/api', $cookie->getPath());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame($this->ts('2026-11-13 10:00:00 +90 days'), $cookie->getExpiresTime());
    }

    public function testAdminSessionIsShortStrictAndSeparate(): void
    {
        $cookie = $this->service->createForAdmin($this->admin(), new Request())['cookie'];

        self::assertSame('drc_admin_refresh', $cookie->getName());
        self::assertSame('/api/admin/auth', $cookie->getPath());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        self::assertSame($this->ts('2026-11-13 10:00:00 +12 hours'), $cookie->getExpiresTime());
    }

    public function testClientRotationIssuesNewJwtAndSlidesExpiry(): void
    {
        $plain = $this->service->createForUser($this->user(), new Request())['cookie']->getValue();

        self::at('2026-12-01 08:00:00');
        $result = $this->service->rotateUser($this->clientRequest($plain));

        self::assertNotEmpty($result['accessToken']);
        self::assertSame('+237690000001', $result['user']->getPhone());
        self::assertNotSame($plain, $result['cookie']->getValue());
        self::assertSame($this->ts('2026-12-01 08:00:00 +90 days'), $result['cookie']->getExpiresTime());
    }

    public function testTwoTabsRefreshingTogetherStayConnected(): void
    {
        $plain = $this->service->createForUser($this->user(), new Request())['cookie']->getValue();

        $this->service->rotateUser($this->clientRequest($plain));
        self::at('2026-11-13 10:00:20'); // 20 s plus tard : délai de grâce
        $second = $this->service->rotateUser($this->clientRequest($plain));

        self::assertNotEmpty($second['accessToken']);
    }

    public function testReuseAfterGraceRevokesTheWholeFamily(): void
    {
        $plain = $this->service->createForUser($this->user(), new Request())['cookie']->getValue();
        $next = $this->service->rotateUser($this->clientRequest($plain))['cookie']->getValue();

        self::at('2026-11-13 10:05:00'); // 5 min plus tard : réutilisation suspecte
        try {
            $this->service->rotateUser($this->clientRequest($plain));
            self::fail('La réutilisation hors délai aurait dû être refusée.');
        } catch (RefreshDeniedException) {
        }

        $this->expectException(RefreshDeniedException::class);
        $this->service->rotateUser($this->clientRequest($next)); // la famille entière est révoquée
    }

    public function testAdminMustLogInAgainAfterTwelveHours(): void
    {
        $plain = $this->service->createForAdmin($this->admin(), new Request())['cookie']->getValue();

        self::at('2026-11-13 18:00:00');
        $plain = $this->service->rotateAdmin($this->adminRequest($plain))['cookie']->getValue();

        self::at('2026-11-13 22:01:00'); // plus de 12 h après la connexion
        $this->expectException(RefreshDeniedException::class);
        $this->service->rotateAdmin($this->adminRequest($plain));
    }

    public function testAdminAccessTokenNeverOutlivesTheSession(): void
    {
        // Connexion à 10:00 => fin de session à 22:00. Rotation à 21:30 : le JWT doit s'arrêter à 22:00,
        // pas à 22:30 (1 h de durée normale).
        $plain = $this->service->createForAdmin($this->admin(), new Request())['cookie']->getValue();
        self::at('2026-11-13 21:30:00');

        $token = $this->service->rotateAdmin($this->adminRequest($plain))['accessToken'];
        $payload = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

        self::assertSame($this->ts('2026-11-13 22:00:00'), $payload['exp']);
    }

    public function testFailureWhileIssuingJwtDoesNotConsumeTheSession(): void
    {
        $user = $this->user();
        $plain = $this->service->createForUser($user, new Request())['cookie']->getValue();

        $failingJwt = $this->createMock(JWTTokenManagerInterface::class);
        $failingJwt->method('create')->willThrowException(new \RuntimeException('clé illisible'));
        $broken = $this->serviceWithJwtManager($failingJwt);

        try {
            $broken->rotateUser($this->clientRequest($plain));
            self::fail('La panne aurait dû remonter (500).');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(RefreshDeniedException::class, $e);
        }

        // Plus tard, la panne réparée : la même session fonctionne toujours (pas de déconnexion).
        self::at('2026-11-13 10:10:00');
        self::assertNotEmpty($this->service->rotateUser($this->clientRequest($plain))['accessToken']);
    }

    public function testClientCookieCannotOpenAnAdminSession(): void
    {
        $adminPlain = $this->service->createForAdmin($this->admin(), new Request())['cookie']->getValue();

        $this->expectException(RefreshDeniedException::class);
        $this->service->rotateUser($this->clientRequest($adminPlain));
    }

    public function testBlockedClientCannotRefresh(): void
    {
        $user = $this->user();
        $plain = $this->service->createForUser($user, new Request())['cookie']->getValue();
        $user->setStatus(User::STATUS_BLOCKED);
        $this->em->flush();

        $this->expectException(RefreshDeniedException::class);
        $this->service->rotateUser($this->clientRequest($plain));
    }

    public function testLogoutRevokesOnlyThisDevice(): void
    {
        $user = $this->user();
        $phone = $this->service->createForUser($user, new Request())['cookie']->getValue();
        $laptop = $this->service->createForUser($user, new Request())['cookie']->getValue();

        $clear = $this->service->revokeCurrentSession('user', $this->clientRequest($phone));
        self::assertSame('', (string) $clear->getValue());

        self::assertNotEmpty($this->service->rotateUser($this->clientRequest($laptop))['accessToken']);

        $this->expectException(RefreshDeniedException::class);
        $this->service->rotateUser($this->clientRequest($phone));
    }

    // ── Aides ─────────────────────────────────────────────────────────────

    /** Horloge simulée, toujours en UTC (indépendant du php.ini). */
    private static function at(string $when): void
    {
        self::mockTime(new \DateTimeImmutable($when, new \DateTimeZone('UTC')));
    }

    private function ts(string $when): int
    {
        return (new \DateTimeImmutable($when, new \DateTimeZone('UTC')))->getTimestamp();
    }

    private function serviceWithJwtManager(JWTTokenManagerInterface $jwt): RefreshTokenService
    {
        $c = static::getContainer();

        return new RefreshTokenService(
            $c->get(RefreshTokenRepository::class),
            $c->get(UserRepository::class),
            $c->get(AdminRepository::class),
            $jwt,
            $this->em,
            $c->get(ClockInterface::class),
            'test',
            90,
            12,
        );
    }

    private function user(): User
    {
        $user = (new User())->setPhone('+237690000001');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function admin(): Admin
    {
        $admin = (new Admin())->setName('Owner')->setEmail('owner@drchoco.cm')->setRole(Admin::ROLE_OWNER)->setPassword('x');
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }

    private function clientRequest(string $plain): Request
    {
        return Request::create('/api/auth/refresh', 'POST', [], [RefreshTokenService::CLIENT_COOKIE => $plain]);
    }

    private function adminRequest(string $plain): Request
    {
        return Request::create('/api/admin/auth/refresh', 'POST', [], [RefreshTokenService::ADMIN_COOKIE => $plain]);
    }
}
