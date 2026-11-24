<?php

namespace App\Tests\Api\Push;

use App\Entity\Admin;
use App\Tests\AccountTestCase;

/**
 * Abonnement des appareils aux notifications push (P7) : client (jeton ou cookie de session pour le service
 * worker) et équipe, contrôles de l'abonnement, un appareil = le dernier compte, 10 appareils au plus.
 */
final class PushSubscriptionTest extends AccountTestCase
{
    public static function subscription(string $endpoint, array $override = []): array
    {
        return $override + [
            'subscription' => [
                'endpoint'       => $endpoint,
                'expirationTime' => null,
                'keys'           => ['p256dh' => self::b64("\x04" . random_bytes(64)), 'auth' => self::b64(random_bytes(16))],
            ],
            'platform'  => 'android',
            'userAgent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/130',
        ];
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function post(string $uri, ?string $token, array $body): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request('POST', $uri, [], [], $server, json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->em()->getConnection()->fetchAllAssociative('SELECT owner_kind, user_id, admin_id, endpoint, platform, user_agent FROM push_subscriptions ORDER BY id');
    }

    public function testClientSubscribesWithTokenOrSessionCookie(): void
    {
        $token = $this->loginNamed();
        $first = 'https://fcm.googleapis.com/fcm/send/appareil-1';

        $data = $this->post('/api/push/subscribe', $token, self::subscription($first));
        self::assertSame(201, $this->httpStatus(), json_encode($data));
        self::assertSame(['ok' => true, 'devices' => 1], $data);
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));
        $this->post('/api/push/subscribe', $token, self::subscription($first));
        self::assertSame([201, 1], [$this->httpStatus(), \count($this->rows())], 'même appareil : une seule ligne');

        // Service worker (pushsubscriptionchange) : pas de jeton, le cookie de session suffit ; l'ancien endpoint
        // est remplacé, la plateforme connue (android) n'est pas écrasée par « web ».
        $second = 'https://fcm.googleapis.com/fcm/send/appareil-1-renouvele';
        $data = $this->post('/api/push/subscribe', null, self::subscription($second, ['platform' => 'web', 'oldEndpoint' => $first]));
        self::assertSame([201, 1], [$this->httpStatus(), $data['devices']], json_encode($data));
        $rows = $this->rows();
        self::assertSame([$second, 'android', 'user'], [$rows[0]['endpoint'], $rows[0]['platform'], $rows[0]['owner_kind']]);
        self::assertCount(1, $rows);

        // Ni jeton ni session : refusé (et le cookie d'une session révoquée ne vaut plus rien).
        $this->client->getCookieJar()->clear();
        $data = $this->post('/api/push/subscribe', null, self::subscription('https://fcm.googleapis.com/fcm/send/x'));
        self::assertSame([401, 'UNAUTHORIZED'], [$this->httpStatus(), $data['code']]);
    }

    public function testInvalidSubscriptionsAreRefused(): void
    {
        $token = $this->loginNamed();
        $valid = self::subscription('https://fcm.googleapis.com/fcm/send/ok');
        $keys = $valid['subscription']['keys'];
        foreach ([
            'sans clés'            => ['subscription' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/a']],
            'http'                 => self::subscription('http://fcm.googleapis.com/fcm/send/a'),
            'service inconnu'      => self::subscription('https://exemple.com/push/a'),
            'adresse interne'      => self::subscription('https://127.0.0.1/push'),
            'identifiants'         => self::subscription('https://user:pass@fcm.googleapis.com/fcm/send/a'),
            'autre port'           => self::subscription('https://fcm.googleapis.com:81/fcm/send/a'),
            'autre service Google' => self::subscription('https://storage.googleapis.com/a'),
            'domaine collé'        => self::subscription('https://evilpush.services.mozilla.com.exemple.com/a'),
            'hôte encodé'          => self::subscription('https://evil.com%2f.push.apple.com/a'),
            'clé publique courte'  => ['subscription' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/a', 'keys' => ['p256dh' => self::b64(random_bytes(32)), 'auth' => $keys['auth']]]],
            'clé auth invalide'    => ['subscription' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/a', 'keys' => ['p256dh' => $keys['p256dh'], 'auth' => 'pas de la base64 !']]],
            'endpoint trop long'   => self::subscription('https://fcm.googleapis.com/' . str_repeat('a', 1000)),
        ] as $case => $body) {
            $data = $this->post('/api/push/subscribe', $token, $body);
            self::assertSame([422, 'INVALID_SUBSCRIPTION'], [$this->httpStatus(), $data['code'] ?? null], $case);
        }
        foreach (['https://updates.push.services.mozilla.com/wpush/v2/a', 'https://web.push.apple.com/QX', 'https://wns2-par02p.notify.windows.com/w/?token=a'] as $endpoint) {
            $this->post('/api/push/subscribe', $token, self::subscription($endpoint));
            self::assertSame(201, $this->httpStatus(), $endpoint);
        }
        self::assertCount(3, $this->rows(), 'les services de push connus sont acceptés');
    }

    public function testADeviceBelongsToTheLastAccountAndOnlyItsOwnerUnsubscribes(): void
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/telephone-partage';
        $junior = $this->loginNamed();
        $this->post('/api/push/subscribe', $junior, self::subscription($endpoint));
        $paul = $this->loginNamed('+237690000002', 'Paul');

        $this->client->request('POST', '/api/push/unsubscribe', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $paul, 'CONTENT_TYPE' => 'application/json'], json_encode(['endpoint' => $endpoint]));
        self::assertSame(204, $this->httpStatus());
        self::assertCount(1, $this->rows(), 'appareil d’un autre client : gardé');

        $this->post('/api/push/subscribe', $paul, self::subscription($endpoint));
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertNotSame($rows[0]['user_id'], $this->em()->getConnection()->fetchOne("SELECT id FROM users WHERE phone = '+237690000001'"), 'appareil passé au dernier compte');

        $this->client->request('POST', '/api/push/unsubscribe', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $paul, 'CONTENT_TYPE' => 'application/json'], json_encode(['endpoint' => $endpoint]));
        self::assertSame([204, []], [$this->httpStatus(), $this->rows()]);
        $this->client->request('POST', '/api/push/unsubscribe', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $paul, 'CONTENT_TYPE' => 'application/json'], '{}');
        self::assertSame(204, $this->httpStatus(), 'toujours 204');
    }

    public function testTeamSubscriptionsAreSeparateFromClients(): void
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/navigateur-de-la-boutique';
        $client = $this->loginNamed();
        $owner = $this->tokenFor($this->createAdmin(Admin::ROLE_OWNER, 'owner@drchoco.cm'));
        $seller = $this->tokenFor($this->createAdmin(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Douala'));

        $this->post('/api/push/subscribe', $client, self::subscription($endpoint));
        $data = $this->post('/api/admin/push/subscribe', $owner, self::subscription($endpoint, ['platform' => 'web']));
        self::assertSame([201, ['ok' => true, 'devices' => 1]], [$this->httpStatus(), $data]);
        self::assertSame(['user', 'admin'], array_column($this->rows(), 'owner_kind'), 'même navigateur, deux abonnements');
        $this->post('/api/admin/push/subscribe', $seller, self::subscription('https://fcm.googleapis.com/fcm/send/vendeur'));
        self::assertSame(201, $this->httpStatus());

        $data = $this->post('/api/admin/push/subscribe', $owner, ['subscription' => ['endpoint' => 'https://exemple.com/x']]);
        self::assertSame([422, 'INVALID_SUBSCRIPTION'], [$this->httpStatus(), $data['code']]);
        $this->post('/api/admin/push/subscribe', $client, self::subscription($endpoint));
        self::assertSame(403, $this->httpStatus(), 'jeton client sur une route admin');

        $this->client->request('POST', '/api/admin/push/unsubscribe', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $owner, 'CONTENT_TYPE' => 'application/json'], json_encode(['endpoint' => $endpoint]));
        self::assertSame(204, $this->httpStatus());
        self::assertSame(['user', 'admin'], array_column($this->rows(), 'owner_kind'), 'seul l’abonnement admin est retiré');
    }

    public function testTenDevicesAtMostPerAccount(): void
    {
        $token = $this->loginNamed();
        for ($i = 1; $i <= 11; ++$i) {
            $data = $this->post('/api/push/subscribe', $token, self::subscription('https://fcm.googleapis.com/fcm/send/appareil-' . $i));
        }
        self::assertSame(10, $data['devices']);
        $endpoints = array_column($this->rows(), 'endpoint');
        self::assertCount(10, $endpoints);
        self::assertNotContains('https://fcm.googleapis.com/fcm/send/appareil-1', $endpoints, 'le plus ancien est retiré');
    }

    public function testTheSessionCookieRouteIsGuardedAgainstOtherSites(): void
    {
        $token = $this->loginNamed();
        $body = json_encode(self::subscription('https://fcm.googleapis.com/fcm/send/impose'));

        $this->client->request('POST', '/api/push/subscribe', [], [], ['HTTP_ORIGIN' => 'https://blog.exemple.com', 'CONTENT_TYPE' => 'application/json'], $body);
        self::assertSame(403, $this->httpStatus(), 'autre site : refusé');
        $this->client->request('POST', '/api/push/subscribe', [], [], ['HTTP_ORIGIN' => 'http://localhost', 'CONTENT_TYPE' => 'text/plain'], $body);
        self::assertSame(415, $this->httpStatus(), 'envoi « simple » sans JSON : refusé');
        self::assertSame([], $this->rows());

        $this->client->request('POST', '/api/push/subscribe', [], [], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'], $body);
        self::assertSame(201, $this->httpStatus(), 'même site : accepté');
    }
}
