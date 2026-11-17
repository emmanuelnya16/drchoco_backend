<?php

namespace App\Tests\Unit;

use App\Push\PushMessage;
use App\Push\WebPushTransport;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Envoi réel (minishlink/web-push) vers un faux service de push : la notification est chiffrée pour l'appareil
 * (aes128gcm, RFC 8291 — déchiffrée ici avec la clé privée de l'appareil) et signée avec les clés VAPID.
 *
 * Windows/XAMPP : nécessite OPENSSL_CONF (sinon le test est ignoré, comme l'envoi réel échouerait).
 */
final class WebPushTransportTest extends TestCase
{
    public function testTheMessageIsEncryptedForTheDeviceAndSignedWithVapid(): void
    {
        $device = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        if ($device === false) {
            self::markTestSkipped('OpenSSL ne crée pas de clé EC ici (Windows/XAMPP : définir OPENSSL_CONF=C:\xampp\php\extras\ssl\openssl.cnf).');
        }
        $ec = openssl_pkey_get_details($device)['ec'];
        $devicePublic = "\x04" . str_pad($ec['x'], 32, "\0", \STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", \STR_PAD_LEFT);
        $auth = random_bytes(16);
        $vapid = VAPID::createVapidKeys();

        $request = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$request): MockResponse {
            $body = $options['body'] ?? '';
            if (\is_callable($body)) {
                $chunks = '';
                while (($chunk = $body(16384)) !== '') {
                    $chunks .= $chunk;
                }
                $body = $chunks;
            }
            $headers = [];
            foreach ($options['headers'] ?? [] as $line) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
            $request = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => (string) $body];

            return new MockResponse('', ['http_code' => 201]);
        });

        $transport = new WebPushTransport($vapid['publicKey'], $vapid['privateKey'], 'mailto:contact@drchoco.cm', new NullLogger(), $http);
        $payload = (new PushMessage('payment_validated', 'Paiement validé ✓', 'RES-000001 est confirmée. Nous préparons votre colis.', '/reservations/RES-000001', 'RES-000001', tag: 'RES-000001'))->payload();
        $result = $transport->send('https://fcm.googleapis.com/fcm/send/appareil', self::b64($devicePublic), self::b64($auth), 'aes128gcm', $payload, 3600, 'high', 'RES-000001');

        self::assertTrue($result->isSuccess(), (string) $result->error);
        self::assertSame(['POST', 'https://fcm.googleapis.com/fcm/send/appareil'], [$request['method'], $request['url']]);
        self::assertSame(['3600', 'high', 'RES-000001', 'aes128gcm'], [$request['headers']['ttl'], $request['headers']['urgency'], $request['headers']['topic'], $request['headers']['content-encoding']]);
        self::assertStringStartsWith('vapid t=', $request['headers']['authorization']);
        self::assertStringContainsString('k=' . $vapid['publicKey'], $request['headers']['authorization']);
        self::assertSame($payload, self::decrypt($request['body'], $device, $auth, $devicePublic), 'seul l’appareil peut lire la notification');
    }

    public function testServiceAnswersAreReported(): void
    {
        $device = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        if ($device === false) {
            self::markTestSkipped('OpenSSL ne crée pas de clé EC ici (OPENSSL_CONF).');
        }
        $vapid = VAPID::createVapidKeys();
        $ec = openssl_pkey_get_details($device)['ec'];
        $p256dh = self::b64("\x04" . str_pad($ec['x'], 32, "\0", \STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", \STR_PAD_LEFT));
        $send = function (callable|MockResponse $response) use ($vapid, $p256dh) {
            $transport = new WebPushTransport($vapid['publicKey'], $vapid['privateKey'], 'mailto:contact@drchoco.cm', new NullLogger(), new MockHttpClient($response));

            return $transport->send('https://fcm.googleapis.com/fcm/send/a', $p256dh, self::b64(random_bytes(16)), 'aes128gcm', '{"type":"delivered"}', 60, 'normal', null);
        };

        self::assertTrue($send(new MockResponse('', ['http_code' => 410]))->isGone());
        $busy = $send(new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 120']]));
        self::assertSame([true, 120], [$busy->isTemporary(), $busy->retryAfter]);
        self::assertTrue($send(new MockResponse('', ['http_code' => 403]))->isAuthError());
        $down = $send(fn () => throw new TransportException('Connexion impossible'));
        self::assertSame([0, true], [$down->status, $down->isTemporary()]);
    }

    public function testNotConfiguredWithoutVapidKeys(): void
    {
        self::assertFalse((new WebPushTransport('', '', 'mailto:contact@drchoco.cm', new NullLogger(), new MockHttpClient()))->isConfigured());
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Déchiffrement aes128gcm (RFC 8188 / 8291) avec la clé privée de l'appareil. */
    private static function decrypt(string $body, \OpenSSLAsymmetricKey $device, string $auth, string $devicePublic): string
    {
        $salt = substr($body, 0, 16);
        $idLength = \ord($body[20]);
        $serverPublic = substr($body, 21, $idLength);
        $cipher = substr($body, 21 + $idLength);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $serverPublic), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $shared = openssl_pkey_derive(openssl_pkey_get_public($pem), $device, 32);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $devicePublic . $serverPublic, $auth);
        $key = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $key, \OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
        self::assertIsString($plain, 'déchiffrement impossible');

        // Fin du message : délimiteur 0x02 puis remplissage de zéros.
        return substr(rtrim($plain, "\0"), 0, -1);
    }

    public function testUnreadableVapidKeysAreAConfigurationError(): void
    {
        $transport = new WebPushTransport('cle-publique-tronquee', 'cle-privee-tronquee', 'mailto:contact@drchoco.cm', new NullLogger(), new MockHttpClient());
        $result = $transport->send('https://fcm.googleapis.com/fcm/send/a', 'x', 'y', 'aes128gcm', '{}', 60, 'normal', null);

        self::assertSame([401, true, false], [$result->status, $result->isAuthError(), $result->isTemporary()], 'alerte « clés VAPID », pas de nouvel essai');
        self::assertNotNull($transport->keysProblem());
    }
}
