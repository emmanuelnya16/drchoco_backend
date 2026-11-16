<?php

namespace App\Tests;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Fake\RecordingOtpSender;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Base des tests des comptes clients (P2) : catalogue et villes de livraison chargés (CatalogTestCase),
 * horloge figée (le temps avance avec wait()), aucun SMS réel (les codes sont lus dans le faux envoi).
 */
abstract class AccountTestCase extends CatalogTestCase
{
    use ClockSensitiveTrait;

    protected const PHONE = '+237690000001';

    protected MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        // Horloge figée à la seconde réelle : les composants qui lisent l'heure réelle (cookie Max-Age, JWT,
        // limites de débit) restent cohérents avec elle, quel que soit le jour où les tests tournent.
        $this->clock = self::mockTime(new \DateTimeImmutable('@' . time()));
        $this->sms()->reset();
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function sms(): RecordingOtpSender
    {
        return static::getContainer()->get('test.otp_sender');
    }

    protected function wait(int $seconds): void
    {
        $this->clock->sleep($seconds);
    }

    /** @return array<string, mixed> */
    protected function requestCode(string $phone = self::PHONE, array $server = []): array
    {
        return $this->postJson('/api/auth/otp/request', ['phone' => $phone], $server);
    }

    /** @return array<string, mixed> */
    protected function verifyCode(string $code, string $phone = self::PHONE): array
    {
        return $this->postJson('/api/auth/otp/verify', ['phone' => $phone, 'code' => $code]);
    }

    /**
     * Connexion complète (code demandé puis saisi). Le cookie de session reste dans le navigateur de test.
     *
     * @return array{token: string, user: array<string, mixed>, isNewUser: bool}
     */
    protected function login(string $phone = self::PHONE): array
    {
        $this->requestCode($phone);
        self::assertSame(200, $this->httpStatus(), 'demande de code');
        $code = $this->sms()->lastCodeFor($phone);
        self::assertNotNull($code);

        $data = $this->verifyCode($code, $phone);
        self::assertSame(200, $this->httpStatus(), 'vérification du code');

        return ['token' => $data['accessToken'], 'user' => $data['user'], 'isNewUser' => $data['isNewUser']];
    }

    /** Connexion d'un client qui a déjà donné son prénom. */
    protected function loginNamed(string $phone = self::PHONE, string $firstName = 'Junior', ?string $lastName = 'Mbarga'): string
    {
        $token = $this->login($phone)['token'];
        $user = static::getContainer()->get(UserRepository::class)->findByPhone($phone);
        $user->setFirstName($firstName)->setLastName($lastName);
        $this->em()->flush();

        return $token;
    }

    /** @return array<string, mixed>|list<mixed> */
    protected function api(string $method, string $uri, ?string $token, mixed $body = null): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $content = null;
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR);
        }
        $this->client->request($method, $uri, [], [], $server, $content);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    protected function refreshCookie(): ?Cookie
    {
        return $this->client->getCookieJar()->get('drc_refresh', '/api');
    }

    protected function setRefreshCookie(?string $value): void
    {
        $jar = $this->client->getCookieJar();
        $jar->expire('drc_refresh', '/api');
        if ($value !== null) {
            $jar->set(new Cookie('drc_refresh', $value, null, '/api'));
        }
    }

    protected function userByPhone(string $phone = self::PHONE): ?User
    {
        $this->em()->clear();

        return static::getContainer()->get(UserRepository::class)->findByPhone($phone);
    }
}
