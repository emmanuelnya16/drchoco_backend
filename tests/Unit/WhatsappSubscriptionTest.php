<?php

namespace App\Tests\Unit;

use App\Auth\Whatsapp\WhatsappConfig;
use App\Auth\Whatsapp\WhatsappSubscription;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Abonnement de l'app Meta au compte WhatsApp Business (app:diagnostic, app:whatsapp:abonner), contre un faux Meta.
 */
final class WhatsappSubscriptionTest extends TestCase
{
    private const WABA = '1193091309714036';

    public function testReadsWhichAppReceivesTheMessages(): void
    {
        $calls = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = [$method, $url, $options['normalized_headers']['authorization'][0] ?? ''];

            return new MockResponse(json_encode(['data' => [['whatsapp_business_api_data' => ['name' => 'Dr Choco OTP', 'id' => '42', 'link' => 'https://x']]]]));
        });

        $state = WhatsappSubscription::read($http, $this->config());
        self::assertSame('subscribed', $state['status']);
        self::assertSame(['« Dr Choco OTP » n° 42'], $state['apps']);
        self::assertSame([['GET', 'https://graph.facebook.com/v21.0/' . self::WABA . '/subscribed_apps', 'Authorization: Bearer jeton']], $calls);
    }

    public function testNoAppMeansNoMessageIsForwarded(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['data' => []])));
        self::assertSame('not_subscribed', WhatsappSubscription::read($http, $this->config())['status']);
    }

    public function testMetaErrorsAreExplained(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']]), ['http_code' => 401]));
        $state = WhatsappSubscription::read($http, $this->config());
        self::assertSame('error', $state['status']);
        self::assertStringContainsString('Meta répond 401, erreur 190 « Invalid OAuth access token »', $state['detail']);

        $down = new MockHttpClient(static fn () => throw new TransportException('connexion refusée'));
        self::assertStringStartsWith('Meta injoignable', WhatsappSubscription::read($down, $this->config())['detail']);

        $none = new MockHttpClient(static fn () => throw new \LogicException('aucun appel attendu'));
        self::assertSame('no_account', WhatsappSubscription::read($none, $this->config(''))['status']);
    }

    public function testSubscribesTheAppOfTheToken(): void
    {
        $calls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = $method . ' ' . $url;

            return new MockResponse(json_encode(['success' => true]));
        });
        self::assertSame(['ok' => true, 'detail' => 'abonnement enregistré par Meta'], WhatsappSubscription::subscribe($http, $this->config()));
        self::assertSame(['POST https://graph.facebook.com/v21.0/' . self::WABA . '/subscribed_apps'], $calls);

        $refused = new MockHttpClient(new MockResponse(json_encode(['error' => ['code' => 200, 'message' => 'Permissions error']]), ['http_code' => 403]));
        $result = WhatsappSubscription::subscribe($refused, $this->config());
        self::assertFalse($result['ok']);
        self::assertStringContainsString('erreur 200', $result['detail']);
    }

    public function testReadsWhereMetaSendsTheMessagesOfTheNumber(): void
    {
        $urls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $method . ' ' . $url;

            return new MockResponse(json_encode(['webhook_configuration' => ['application' => 'https://api.drchoco.bibnumcam.com/api/webhooks/whatsapp'], 'id' => '1566791356509169']));
        });
        self::assertSame(['url' => 'https://api.drchoco.bibnumcam.com/api/webhooks/whatsapp', 'detail' => ''], WhatsappSubscription::callbackUrl($http, $this->config()));
        self::assertSame(['GET https://graph.facebook.com/v21.0/1566791356509169?fields=webhook_configuration'], $urls);

        // Adresse propre au numéro : prioritaire sur celle de l'app
        $override = new MockHttpClient(new MockResponse(json_encode(['webhook_configuration' => ['application' => 'https://a.test/x', 'phone_number' => 'https://b.test/api/webhooks/whatsapp']])));
        self::assertSame('https://b.test/api/webhooks/whatsapp', WhatsappSubscription::callbackUrl($override, $this->config())['url']);

        $none = new MockHttpClient(new MockResponse(json_encode(['id' => '1566791356509169'])));
        self::assertStringContainsString('aucune app', WhatsappSubscription::callbackUrl($none, $this->config())['detail']);
    }

    private function config(string $waba = self::WABA): WhatsappConfig
    {
        return new WhatsappConfig('237659881683', '1566791356509169', 'verif', 'cle', 'jeton', 'v21.0', WhatsappConfig::DEFAULT_GRAPH_URL, $waba);
    }
}
