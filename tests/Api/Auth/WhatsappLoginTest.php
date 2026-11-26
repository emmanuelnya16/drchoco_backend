<?php

namespace App\Tests\Api\Auth;

use App\Auth\Whatsapp\WhatsappConfig;
use App\Auth\Whatsapp\WhatsappWebhook;
use App\Entity\User;
use App\Tests\AccountTestCase;
use App\Tests\Fake\RecordingWhatsappMessenger;

/**
 * Connexion par WhatsApp (WHATSAPP_OTP.md) : POST /api/auth/whatsapp/start et /status, GET et POST /api/webhooks/whatsapp.
 * Aucun appel réel à Meta : les envois de Meta sont simulés (signés avec la clé de test de .env.test) et les réponses
 * au client enregistrées.
 */
final class WhatsappLoginTest extends AccountTestCase
{
    private const APP_SECRET = 'cle-secrete-meta-test';
    private const PHONE_NUMBER_ID = '1566791356509169';
    private const FROM = '237690000001';

    protected function setUp(): void
    {
        parent::setUp();
        // Souvenirs des messages déjà traités et des réponses déjà faites : propres à chaque test
        static::getContainer()->get('cache.app')->clear();
    }

    public function testStartGivesACodeAndALinkWithTheMessageAlreadyWritten(): void
    {
        $data = $this->start();

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['requestId', 'code', 'waLink', 'businessNumber', 'expiresIn'], array_keys($data));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $data['requestId']);
        self::assertMatchesRegularExpression('/^DRC-\d{6}$/', $data['code']);
        self::assertSame('https://wa.me/237659881683?text=Mon%20code%20DR%20Choco%20%3A%20' . $data['code'], $data['waLink']);
        self::assertSame('+237659881683', $data['businessNumber']);
        self::assertSame(600, $data['expiresIn']);
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));

        // Le jeton remis à l'app n'est jamais gardé en clair ; rien n'est envoyé au client.
        $row = $this->em()->getConnection()->fetchAssociative('SELECT * FROM whatsapp_login_requests');
        self::assertSame(self::PHONE, $row['phone']);
        self::assertSame(64, \strlen($row['request_token_hash']));
        self::assertNotSame($data['requestId'], $row['request_token_hash']);
        self::assertSame([], $this->sms()->sent);
        self::assertSame([], $this->messenger()->sent);
    }

    public function testInvalidRequestsAreRefused(): void
    {
        foreach (['', '+237290000001', '69000000', 'abc'] as $raw) {
            self::assertSame('INVALID_PHONE', $this->start($raw)['code'] ?? null, $raw);
            self::assertSame(422, $this->httpStatus());
        }

        // Formulaire envoyé par un autre site : refusé (même protection que la connexion par SMS)
        $this->client->request('POST', '/api/auth/whatsapp/start', ['phone' => '690000001']);
        self::assertSame(415, $this->httpStatus());
        $this->postJson('/api/auth/whatsapp/start', ['phone' => '690000001'], ['HTTP_ORIGIN' => 'https://exemple.com']);
        self::assertSame(403, $this->httpStatus());

        foreach ([['requestId' => 'abc'], ['requestId' => str_repeat('a', 64)], [], ['requestId' => 12]] as $body) {
            self::assertSame('WHATSAPP_REQUEST_EXPIRED', $this->postJson('/api/auth/whatsapp/status', $body)['code'] ?? null);
            self::assertSame(410, $this->httpStatus());
        }
    }

    public function testTheMessageFromTheCustomersWhatsappOpensTheSession(): void
    {
        $start = $this->start();
        self::assertSame(['status' => 'pending', 'expiresIn' => 600, 'wrongSender' => false], $this->checkStatus($start['requestId']));

        $this->wait(20);
        $this->receive(self::FROM, 'Mon code DR Choco : ' . $start['code']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['received' => true], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame([['to' => self::FROM, 'text' => WhatsappWebhook::REPLY_CONFIRMED]], $this->messenger()->sent);

        $data = $this->checkStatus($start['requestId']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['status', 'accessToken', 'user', 'isNewUser'], array_keys($data));
        self::assertSame('confirmed', $data['status']);
        self::assertTrue($data['isNewUser']);
        self::assertSame(self::PHONE, $data['user']['phone']);
        self::assertStringStartsWith('drc_refresh=', (string) $this->header('Set-Cookie'));
        self::assertNotNull($this->refreshCookie());
        self::assertSame(self::PHONE, $this->getJson('/api/me', $data['accessToken'])['phone'] ?? null);

        // La demande ne sert qu'une fois.
        $this->checkStatus($start['requestId']);
        self::assertSame(410, $this->httpStatus());
        self::assertNotNull($this->userByPhone()?->getLastLoginAt());
    }

    public function testAKnownCustomerKeepsTheAccount(): void
    {
        $this->createUser();
        $start = $this->start();
        $this->receive(self::FROM, $start['code']); // code seul, sans le texte prérempli
        $data = $this->checkStatus($start['requestId']);
        self::assertSame('confirmed', $data['status'] ?? null);
        self::assertFalse($data['isNewUser']);
        self::assertSame('Junior', $data['user']['firstName'] ?? null);
    }

    public function testTheCodeSentFromAnotherWhatsappConfirmsNothing(): void
    {
        $start = $this->start();
        $this->receive('237699999999', 'Mon code DR Choco : ' . $start['code']);
        self::assertSame([['to' => '237699999999', 'text' => WhatsappWebhook::REPLY_WRONG_SENDER]], $this->messenger()->sent);
        self::assertSame(['status' => 'pending', 'expiresIn' => 600, 'wrongSender' => true], $this->checkStatus($start['requestId']));

        // Le bon numéro peut toujours confirmer.
        $this->receive(self::FROM, 'Mon code DR Choco : ' . $start['code']);
        self::assertSame('confirmed', $this->checkStatus($start['requestId'])['status'] ?? null);
    }

    public function testOnlyMessagesSignedByMetaAreRead(): void
    {
        $start = $this->start();
        $this->receive(self::FROM, $start['code'], signature: 'sha256=' . str_repeat('0', 64));
        self::assertSame(401, $this->httpStatus());
        $this->receive(self::FROM, $start['code'], signature: '');
        self::assertSame(401, $this->httpStatus());
        self::assertSame([], $this->messenger()->sent);
        self::assertSame('pending', $this->checkStatus($start['requestId'])['status'] ?? null);
    }

    public function testAMessageSentTwiceByMetaCountsOnce(): void
    {
        $start = $this->start();
        $this->receive(self::FROM, $start['code'], messageId: 'wamid.double');
        self::assertCount(1, $this->messenger()->sent);
        $this->receive(self::FROM, $start['code'], messageId: 'wamid.double');
        self::assertSame(200, $this->httpStatus());
        self::assertSame([], $this->messenger()->sent, 'aucune seconde réponse');
        self::assertSame('confirmed', $this->checkStatus($start['requestId'])['status'] ?? null);
    }

    public function testAnOldOrReplacedCodeIsRefused(): void
    {
        $first = $this->start();
        $second = $this->start();

        // Une nouvelle demande remplace la précédente.
        $this->checkStatus($first['requestId']);
        self::assertSame(410, $this->httpStatus());
        $this->receive(self::FROM, $first['code']);
        self::assertSame([['to' => self::FROM, 'text' => WhatsappWebhook::REPLY_UNKNOWN_CODE]], $this->messenger()->sent);

        // Au-delà de 10 minutes, la demande expire.
        $this->wait(601);
        $this->receive(self::FROM, $second['code']);
        self::assertSame([], $this->messenger()->sent, 'même refus, déjà envoyé il y a moins d’une heure');
        $this->checkStatus($second['requestId']);
        self::assertSame(410, $this->httpStatus());
        self::assertSame('WHATSAPP_REQUEST_EXPIRED', json_decode((string) $this->client->getResponse()->getContent(), true)['code']);
    }

    public function testTheAppHasTimeToComeBackFromWhatsapp(): void
    {
        $start = $this->start();
        $this->wait(500);
        $this->receive(self::FROM, $start['code']);
        $this->wait(500); // au-delà des 10 minutes de la demande, mais moins de 10 minutes après le message
        self::assertSame('confirmed', $this->checkStatus($start['requestId'])['status'] ?? null);
    }

    public function testRequestsPerNumberAreLimited(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->start();
            self::assertSame(200, $this->httpStatus());
        }
        $data = $this->start();
        self::assertSame(429, $this->httpStatus());
        self::assertSame('TOO_MANY_WHATSAPP_REQUESTS', $data['code']);
        self::assertSame("Trop de demandes de vérification. Réessayez dans 10\u{00A0}min.", $data['message']);
        self::assertSame('600', $this->header('Retry-After'));

        $this->start('690000002');
        self::assertSame(200, $this->httpStatus(), 'un autre numéro n’est pas bloqué');
        $this->wait(601);
        $this->start();
        self::assertSame(200, $this->httpStatus());
    }

    public function testABlockedAccountCannotLogIn(): void
    {
        $this->createUser(self::PHONE, User::STATUS_BLOCKED);
        self::assertSame('ACCOUNT_BLOCKED', $this->start()['code'] ?? null);
        self::assertSame(403, $this->httpStatus());

        // Bloqué pendant l'attente du message : pas de session, la demande ne sert plus.
        $other = '+237690000002';
        $start = $this->start('690000002');
        $user = $this->createUser($other);
        $this->receive('237690000002', $start['code']);
        $user = $this->userByPhone($other);
        $user->setStatus(User::STATUS_BLOCKED);
        $this->em()->flush();
        self::assertSame('ACCOUNT_BLOCKED', $this->checkStatus($start['requestId'])['code'] ?? null);
        self::assertSame(403, $this->httpStatus());
        self::assertNull($this->refreshCookie());
        $this->checkStatus($start['requestId']);
        self::assertSame(410, $this->httpStatus());
    }

    public function testOtherEventsAreIgnoredAndAnsweredQuickly(): void
    {
        $start = $this->start();
        $events = [
            // Accusés de livraison et de lecture
            ['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID], 'statuses' => [['id' => 'wamid.x', 'status' => 'read']]]],
            // Image, audio : pas du texte
            ['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID], 'messages' => [['from' => self::FROM, 'id' => 'wamid.img', 'type' => 'image', 'image' => ['id' => '1']]]]],
            // Autre numéro du même compte Meta
            ['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '999'], 'messages' => [['from' => self::FROM, 'id' => 'wamid.autre', 'type' => 'text', 'text' => ['body' => $start['code']]]]]],
        ];
        $this->post(json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => $events]]], \JSON_THROW_ON_ERROR));
        self::assertSame(200, $this->httpStatus());
        $this->post('pas du json');
        self::assertSame(200, $this->httpStatus(), 'signé mais illisible : ignoré, jamais renvoyé par Meta en boucle');
        self::assertSame([], $this->messenger()->sent);
        self::assertSame('pending', $this->checkStatus($start['requestId'])['status'] ?? null);

        // Message sans code : une aide, une fois par heure au plus.
        $this->receive(self::FROM, 'Bonjour, vous avez des babouches en 42 ?');
        self::assertSame([['to' => self::FROM, 'text' => WhatsappWebhook::REPLY_HELP]], $this->messenger()->sent);
        $this->receive(self::FROM, 'Merci');
        self::assertSame([], $this->messenger()->sent);
    }

    public function testMetaChecksTheCallbackAddress(): void
    {
        $this->client->request('GET', '/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=jeton-de-verification-test&hub.challenge=1158201444');
        self::assertSame(200, $this->httpStatus());
        self::assertSame('1158201444', $this->client->getResponse()->getContent());
        self::assertStringStartsWith('text/plain', (string) $this->header('Content-Type'));

        foreach (['hub.mode=subscribe&hub.verify_token=faux&hub.challenge=1', 'hub.mode=unsubscribe&hub.verify_token=jeton-de-verification-test&hub.challenge=1', ''] as $query) {
            $this->client->request('GET', '/api/webhooks/whatsapp?' . $query);
            self::assertSame(403, $this->httpStatus(), $query);
            self::assertSame('', $this->client->getResponse()->getContent());
        }
    }

    public function testWithoutItsSecretKeyTheAppOffersTheSms(): void
    {
        $config = new WhatsappConfig('237659881683', self::PHONE_NUMBER_ID, 'jeton', '', '', 'v21.0');
        self::assertFalse($config->isLoginReady());
        self::assertFalse($config->canReply());
        self::assertFalse((new WhatsappConfig('', self::PHONE_NUMBER_ID, 'jeton', 'cle', 'jeton', 'v21.0'))->isLoginReady());

        $config = new WhatsappConfig('+237 6 59 88 16 83', self::PHONE_NUMBER_ID, 'jeton', 'cle', 'jeton', 'v21.0');
        self::assertTrue($config->isLoginReady());
        self::assertSame('https://graph.facebook.com/v21.0/' . self::PHONE_NUMBER_ID . '/messages', $config->graphUrl(self::PHONE_NUMBER_ID . '/messages'));
    }

    // ── Aides ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function start(string $phone = '690000001'): array
    {
        return $this->postJson('/api/auth/whatsapp/start', ['phone' => $phone]);
    }

    /** @return array<string, mixed> */
    private function checkStatus(string $requestId): array
    {
        return $this->postJson('/api/auth/whatsapp/status', ['requestId' => $requestId]);
    }

    /** Message texte reçu par la boutique, tel que Meta le transmet. Les réponses enregistrées repartent de zéro. */
    private function receive(string $from, string $text, ?string $messageId = null, ?string $signature = null): void
    {
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'id'      => '1193091309714036',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata'          => ['display_phone_number' => '237659881683', 'phone_number_id' => self::PHONE_NUMBER_ID],
                        'contacts'          => [['profile' => ['name' => 'Client'], 'wa_id' => $from]],
                        'messages'          => [[
                            'from'      => $from,
                            'id'        => $messageId ?? 'wamid.' . bin2hex(random_bytes(8)),
                            'timestamp' => (string) time(),
                            'type'      => 'text',
                            'text'      => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $this->post($body, $signature);
    }

    private function post(string $body, ?string $signature = null): void
    {
        $this->messenger()->reset();
        $server = ['CONTENT_TYPE' => 'application/json'];
        $signature ??= 'sha256=' . hash_hmac('sha256', $body, self::APP_SECRET);
        if ($signature !== '') {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $signature;
        }
        $this->client->request('POST', '/api/webhooks/whatsapp', [], [], $server, $body);
    }

    private function messenger(): RecordingWhatsappMessenger
    {
        return static::getContainer()->get('test.whatsapp_messenger');
    }
}
