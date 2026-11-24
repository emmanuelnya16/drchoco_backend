<?php

namespace App\Tests\Api\Auth;

use App\Entity\User;
use App\Tests\AccountTestCase;

/**
 * POST /api/auth/refresh et POST /api/auth/logout (contrat §2.4, §4.3, §4.4).
 */
final class SessionTest extends AccountTestCase
{
    public function testRefreshGivesANewAccessTokenAndRotatesTheCookie(): void
    {
        $login = $this->login();
        $before = $this->refreshCookie()?->getValue();

        $this->wait(60);
        $data = $this->postJson('/api/auth/refresh', '');

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['accessToken', 'user'], array_keys($data));
        self::assertNotSame('', $data['accessToken']);
        self::assertSame($login['user']['id'], $data['user']['id']);
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));

        $after = $this->refreshCookie()?->getValue();
        self::assertNotNull($after);
        self::assertNotSame($before, $after, 'nouveau cookie à chaque rafraîchissement');

        // Le nouveau jeton ouvre les routes du client.
        $this->api('GET', '/api/me', $data['accessToken']);
        self::assertSame(200, $this->httpStatus());
    }

    public function testTwoTabsRefreshingTogetherStayConnected(): void
    {
        $this->login();
        $old = $this->refreshCookie()->getValue();

        $this->postJson('/api/auth/refresh', '');
        self::assertSame(200, $this->httpStatus());

        // Second onglet, encore avec l'ancien cookie, dans les 30 s : accepté.
        $this->wait(10);
        $this->setRefreshCookie($old);
        $this->postJson('/api/auth/refresh', '');
        self::assertSame(200, $this->httpStatus());
    }

    public function testReusedCookieAfterGraceRevokesTheWholeSession(): void
    {
        $this->login();
        $old = $this->refreshCookie()->getValue();
        $this->postJson('/api/auth/refresh', '');
        $current = $this->refreshCookie()->getValue();

        $this->wait(31);
        $this->setRefreshCookie($old);
        $data = $this->postJson('/api/auth/refresh', '');
        self::assertSame(401, $this->httpStatus());
        self::assertSame(['code' => 'NO_SESSION', 'message' => 'Aucune session active.'], $data);

        // Le cookie le plus récent (peut-être volé) est révoqué lui aussi.
        $this->setRefreshCookie($current);
        $this->postJson('/api/auth/refresh', '');
        self::assertSame(401, $this->httpStatus());
    }

    public function testRefreshLoopIsLimitedWithoutClosingTheSession(): void
    {
        $this->login();
        for ($i = 0; $i < 60; ++$i) {
            $this->postJson('/api/auth/refresh', '');
            self::assertSame(200, $this->httpStatus(), "rafraîchissement n°$i");
        }
        $cookie = $this->refreshCookie()->getValue();
        $data = $this->postJson('/api/auth/refresh', '');
        self::assertSame(429, $this->httpStatus());
        self::assertSame('RATE_LIMITED', $data['code']);
        self::assertGreaterThan(0, $data['details']['retryAfter']);
        self::assertNull($this->header('Set-Cookie'), 'le cookie n’est ni changé ni effacé');

        // La session n'est pas fermée : le même cookie est accepté dès que la limite est passée.
        static::getContainer()->get('cache.rate_limiter')->clear();
        $this->setRefreshCookie($cookie);
        $this->postJson('/api/auth/refresh', '');
        self::assertSame(200, $this->httpStatus());
    }

    public function testNeitherIpAddressNorBrowserIsKeptWithTheSession(): void
    {
        $this->login();
        $this->postJson('/api/auth/refresh', '', ['HTTP_USER_AGENT' => 'Navigateur de test', 'REMOTE_ADDR' => '10.1.2.3']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE created_from_ip IS NOT NULL OR user_agent IS NOT NULL'));
    }

    public function testRefreshWithoutValidCookieIsRefusedAndClearsIt(): void
    {
        $this->postJson('/api/auth/refresh', '');
        self::assertSame(401, $this->httpStatus());
        self::assertSame('NO_SESSION', json_decode((string) $this->client->getResponse()->getContent(), true)['code']);

        $this->setRefreshCookie('cookie-invente');
        $this->postJson('/api/auth/refresh', '');
        self::assertSame(401, $this->httpStatus());
        $setCookie = (string) $this->header('Set-Cookie');
        self::assertStringStartsWith('drc_refresh=deleted', $setCookie);
        self::assertStringContainsString('path=/api', $setCookie);
    }

    public function testSessionLastsNinetyDaysOfInactivity(): void
    {
        $this->login();

        $this->wait(89 * 86400);
        $this->postJson('/api/auth/refresh', '');
        self::assertSame(200, $this->httpStatus(), 'le client revient après 89 jours : toujours connecté');

        // Glissante : 89 jours après le dernier rafraîchissement, toujours connecté.
        $this->wait(89 * 86400);
        $this->postJson('/api/auth/refresh', '');
        self::assertSame(200, $this->httpStatus());

        $this->wait(91 * 86400);
        $this->postJson('/api/auth/refresh', '');
        self::assertSame(401, $this->httpStatus(), 'plus de 90 jours sans visite : reconnexion');
    }

    public function testBlockedClientLosesTheSessionAtTheNextRefresh(): void
    {
        $this->login();
        $user = $this->userByPhone();
        $user->setStatus(User::STATUS_BLOCKED);
        $this->em()->flush();

        $this->postJson('/api/auth/refresh', '');
        self::assertSame(401, $this->httpStatus());
    }

    public function testLogoutRevokesOnlyThisDevice(): void
    {
        $this->login();
        $phoneA = $this->refreshCookie()->getValue();
        $this->wait(61);
        $this->login();
        $phoneB = $this->refreshCookie()->getValue();

        $this->setRefreshCookie($phoneA);
        $this->client->request('POST', '/api/auth/logout');
        self::assertSame(204, $this->httpStatus());
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));
        self::assertStringStartsWith('drc_refresh=deleted', (string) $this->header('Set-Cookie'));

        $this->setRefreshCookie($phoneA);
        $this->postJson('/api/auth/refresh', '');
        self::assertSame(401, $this->httpStatus(), 'appareil déconnecté');

        $this->setRefreshCookie($phoneB);
        $this->postJson('/api/auth/refresh', '');
        self::assertSame(200, $this->httpStatus(), 'l’autre appareil reste connecté');
    }

    public function testLogoutAlwaysAnswers204(): void
    {
        $this->client->request('POST', '/api/auth/logout');
        self::assertSame(204, $this->httpStatus());

        $this->setRefreshCookie('inconnu');
        $this->client->request('POST', '/api/auth/logout', [], [], ['HTTP_AUTHORIZATION' => 'Bearer jeton.expire.xyz']);
        self::assertSame(204, $this->httpStatus());
    }

    public function testAnotherSiteCannotLogTheClientOut(): void
    {
        $this->login();
        $cookie = $this->refreshCookie()->getValue();

        // Formulaires automatiques d'une page piégée : refusés, le cookie n'est ni effacé ni remplacé.
        $this->client->request('POST', '/api/auth/logout', [], [], ['HTTP_ORIGIN' => 'https://site-piege.example']);
        self::assertSame(403, $this->httpStatus());
        self::assertNull($this->header('Set-Cookie'));

        $this->client->request('POST', '/api/auth/refresh', [], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'CONTENT_TYPE' => 'application/json']);
        self::assertSame(403, $this->httpStatus());
        self::assertNull($this->header('Set-Cookie'));

        $this->client->request('POST', '/api/auth/refresh', [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'a=b');
        self::assertSame(415, $this->httpStatus());

        // La session est intacte.
        self::assertSame($cookie, $this->refreshCookie()?->getValue());
        $this->postJson('/api/auth/refresh', '');
        self::assertSame(200, $this->httpStatus());
    }
}
