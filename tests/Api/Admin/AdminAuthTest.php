<?php

namespace App\Tests\Api\Admin;

use App\Entity\Admin;
use App\Tests\AdminTestCase;

/**
 * POST /api/admin/auth/login, /refresh, /logout (contrat admin §2) : session admin séparée et courte.
 */
final class AdminAuthTest extends AdminTestCase
{
    public function testLoginWithEmailOrPhone(): void
    {
        $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé', '+237690000020');

        $data = $this->adminLogin('  Vendeur@DRChoco.cm ');
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['accessToken', 'admin'], array_keys($data));
        self::assertNotSame('', $data['accessToken']);
        self::assertSame(['id', 'email', 'phone', 'name', 'role', 'city', 'active', 'createdAt'], array_keys($data['admin']));
        self::assertSame(['vendeur@drchoco.cm', '+237690000020', 'seller', 'Yaoundé', true], [
            $data['admin']['email'], $data['admin']['phone'], $data['admin']['role'], $data['admin']['city'], $data['admin']['active'],
        ]);
        self::assertStringNotContainsString('password', strtolower((string) $this->client->getResponse()->getContent()));

        // Cache interdit sur toute l'administration (contrat admin §1.3).
        foreach (['no-store', 'no-cache', 'must-revalidate', 'private'] as $directive) {
            self::assertStringContainsString($directive, (string) $this->header('Cache-Control'));
        }

        // Cookie de session admin : séparé de celui des clients, strict, 12 h.
        $setCookie = strtolower((string) $this->header('Set-Cookie'));
        self::assertStringStartsWith('drc_admin_refresh=', $setCookie);
        self::assertStringContainsString('path=/api/admin/auth', $setCookie);
        self::assertStringContainsString('samesite=strict', $setCookie);
        self::assertStringContainsString('httponly', $setCookie);
        self::assertEqualsWithDelta($this->clock->now()->getTimestamp() + 12 * 3600, (int) $this->adminRefreshCookie()->getExpiresTime(), 2);

        foreach (['690000020', '+237 6 90 00 00 20', '00237690000020'] as $phone) {
            $this->adminLogin($phone);
            self::assertSame(200, $this->httpStatus(), $phone);
        }

        self::assertNotNull($this->reloadAdmin('vendeur@drchoco.cm')?->getLastLoginAt());
    }

    public function testWrongPasswordAndUnknownAccountLookTheSame(): void
    {
        $this->account();

        $wrong = $this->adminLogin('owner@drchoco.cm', 'mauvais-mot-de-passe');
        self::assertSame(401, $this->httpStatus());
        $unknown = $this->adminLogin('personne@drchoco.cm', 'mauvais-mot-de-passe');
        self::assertSame(401, $this->httpStatus());

        self::assertSame(['code' => 'INVALID_CREDENTIALS', 'message' => 'Identifiant ou mot de passe incorrect.'], $wrong);
        self::assertSame($wrong, $unknown);
    }

    public function testFiveWrongPasswordsBlockTheIdentifierForFifteenMinutes(): void
    {
        $this->account();
        $this->account(Admin::ROLE_MANAGER, 'manager@drchoco.cm');

        for ($i = 0; $i < 5; ++$i) {
            $this->adminLogin('owner@drchoco.cm', 'faux-' . $i);
            self::assertSame(401, $this->httpStatus());
        }

        // Même le bon mot de passe est refusé pendant le blocage.
        $data = $this->adminLogin('owner@drchoco.cm');
        self::assertSame(429, $this->httpStatus());
        self::assertSame('TOO_MANY_ATTEMPTS', $data['code']);
        self::assertGreaterThan(0, $data['details']['retryAfter']);
        self::assertNotNull($this->header('Retry-After'));

        // Un autre compte n'est pas concerné.
        $this->adminLogin('manager@drchoco.cm');
        self::assertSame(200, $this->httpStatus());
    }

    public function testSuccessfulLoginResetsTheCounter(): void
    {
        $this->account();
        foreach ([4, 4] as $failures) {
            for ($i = 0; $i < $failures; ++$i) {
                $this->adminLogin('owner@drchoco.cm', 'faux');
                self::assertSame(401, $this->httpStatus());
            }
            $this->adminLogin('owner@drchoco.cm');
            self::assertSame(200, $this->httpStatus());
        }
    }

    public function testTwentyFailuresPerIpAddress(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->adminLogin("inconnu$i@drchoco.cm", 'faux');
            self::assertSame(401, $this->httpStatus());
        }
        $this->account();
        $this->adminLogin('owner@drchoco.cm');
        self::assertSame(429, $this->httpStatus());

        $this->adminLogin('owner@drchoco.cm', self::PASSWORD, ['REMOTE_ADDR' => '10.0.0.7']);
        self::assertSame(200, $this->httpStatus());
    }

    public function testDisabledAccountIsRefusedOnlyWithTheRightPassword(): void
    {
        $this->account(Admin::ROLE_SELLER, 'ancien@drchoco.cm', 'Douala', active: false);

        self::assertSame('INVALID_CREDENTIALS', $this->adminLogin('ancien@drchoco.cm', 'faux')['code']);
        $data = $this->adminLogin('ancien@drchoco.cm');
        self::assertSame(403, $this->httpStatus());
        self::assertSame('ACCOUNT_DISABLED', $data['code']);
        self::assertNull($this->header('Set-Cookie'));
    }

    public function testInvalidRequestsAreRefused(): void
    {
        $this->postJson('/api/admin/auth/login', ['identifier' => '', 'password' => '']);
        self::assertSame(422, $this->httpStatus());
        $this->postJson('/api/admin/auth/login', '[1,2]');
        self::assertSame(422, $this->httpStatus());

        // Formulaire d'un autre site ou corps non JSON.
        $this->client->request('POST', '/api/admin/auth/login', [], [], ['CONTENT_TYPE' => 'text/plain'], '{"identifier":"a","password":"b"}');
        self::assertSame(415, $this->httpStatus());
        $this->adminLogin('owner@drchoco.cm', self::PASSWORD, ['HTTP_ORIGIN' => 'https://site-piege.example']);
        self::assertSame(403, $this->httpStatus());
    }

    public function testRefreshRotatesAndLogoutEndsTheSession(): void
    {
        $this->account();
        $this->adminLogin('owner@drchoco.cm');
        $first = $this->adminRefreshCookie()->getValue();

        $data = $this->adminRefresh();
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['accessToken', 'admin'], array_keys($data));
        self::assertSame('owner', $data['admin']['role']);
        self::assertNotSame($first, $this->adminRefreshCookie()->getValue());

        // Le jeton rafraîchi ouvre l'administration.
        $this->api('GET', '/api/admin/sellers', $data['accessToken']);
        self::assertSame(200, $this->httpStatus());

        $this->client->request('POST', '/api/admin/auth/logout');
        self::assertSame(204, $this->httpStatus());
        self::assertStringStartsWith('drc_admin_refresh=deleted', (string) $this->header('Set-Cookie'));

        $data = $this->adminRefresh();
        self::assertSame(401, $this->httpStatus());
        self::assertSame('ADMIN_UNAUTHORIZED', $data['code']);
    }

    public function testAdminSessionEndsAfterTwelveHours(): void
    {
        $this->account();
        $this->adminLogin('owner@drchoco.cm');
        $cookie = $this->adminRefreshCookie()->getValue();

        $this->clock->sleep(11 * 3600);
        $this->setAdminRefreshCookie($cookie);
        $this->adminRefresh();
        self::assertSame(200, $this->httpStatus(), 'toujours connecté après 11 h');

        $this->clock->sleep(3600 + 60);
        $this->setAdminRefreshCookie($this->adminRefreshCookie()?->getValue() ?? $cookie);
        $this->adminRefresh();
        self::assertSame(401, $this->httpStatus(), 'reconnexion obligatoire après 12 h');
    }

    public function testClientAndAdminSessionsNeverMix(): void
    {
        $this->account();
        $adminToken = $this->adminToken('owner@drchoco.cm');
        $user = $this->createUser();

        // Jeton client sur l'administration, jeton admin sur l'espace client : refusés.
        $this->api('GET', '/api/admin/sellers', $this->tokenFor($user));
        self::assertSame(403, $this->httpStatus());
        $this->api('GET', '/api/me', $adminToken);
        self::assertSame(403, $this->httpStatus());

        // Cookie client sur le rafraîchissement admin : refusé.
        $this->setAdminRefreshCookie(null);
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('drc_refresh', 'jeton-client', null, '/api'));
        $this->adminRefresh();
        self::assertSame(401, $this->httpStatus());

        // Sans jeton : 401 ADMIN_UNAUTHORIZED, jamais mis en cache.
        $data = $this->api('GET', '/api/admin/sellers', null);
        self::assertSame(401, $this->httpStatus());
        self::assertSame('ADMIN_UNAUTHORIZED', $data['code']);
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));
    }
}
