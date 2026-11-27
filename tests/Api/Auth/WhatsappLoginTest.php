<?php

namespace App\Tests\Api\Auth;

use App\Auth\Whatsapp\WhatsappConfig;
use App\Auth\Whatsapp\WhatsappWebhook;
use App\Entity\User;
use App\Tests\AccountTestCase;
use App\Tests\Fake\RecordingWhatsappMessenger;

/**
 * Connexion par WhatsApp (WHATSAPP_OTP.md) : POST /api/auth/whatsapp/start, GET et POST /api/webhooks/whatsapp, puis le
 * code reçu sur WhatsApp saisi comme un code SMS (POST /api/auth/otp/verify).
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

    public function testStartRecordsTheReferenceWrittenInTheWhatsappMessage(): void
    {
        $data = $this->start('690000001', 'k7f2qx');

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['reference', 'waLink', 'businessNumber', 'expiresIn'], array_keys($data));
        self::assertSame('K7F2QX', $data['reference']);
        self::assertSame('https://wa.me/237659881683?text=Je%20veux%20recevoir%20mon%20code%20de%20connexion%20DR%20Choco.%20R%C3%A9f.%20K7F2QX', $data['waLink']);
        self::assertSame('+237659881683', $data['businessNumber']);
        self::assertSame(600, $data['expiresIn']);
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));

        $row = $this->em()->getConnection()->fetchAssociative('SELECT * FROM whatsapp_login_requests');
        self::assertSame(self::PHONE, $row['phone']);
        self::assertSame('K7F2QX', $row['reference']);
        self::assertSame('pending', $row['status']);
        // Rien n'est envoyé tant que le client n'a pas écrit.
        self::assertSame([], $this->sms()->sent);
        self::assertSame([], $this->messenger()->sent);

        // Sans référence : le serveur en choisit une.
        $data = $this->start('690000002', null);
        self::assertSame(200, $this->httpStatus());
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', $data['reference']);

        // Les paramètres publics donnent le numéro à qui écrire.
        self::assertSame(['number' => '+237659881683'], $this->getJson('/api/settings/public')['whatsappLogin'] ?? null);
    }

    public function testInvalidRequestsAreRefused(): void
    {
        foreach (['', '+237290000001', '69000000', 'abc'] as $raw) {
            self::assertSame('INVALID_PHONE', $this->start($raw)['code'] ?? null, $raw);
            self::assertSame(422, $this->httpStatus());
        }
        foreach (['ABC', 'K7F2QXX', 'K7F2Q0', 'K7F2QI', 12, ''] as $reference) {
            self::assertSame('INVALID_REFERENCE', $this->start('690000001', $reference)['code'] ?? null, (string) $reference);
            self::assertSame(422, $this->httpStatus());
        }

        // Référence déjà prise
        $this->start('690000001', 'K7F2QX');
        self::assertSame('REFERENCE_IN_USE', $this->start('690000002', 'K7F2QX')['code'] ?? null);
        self::assertSame(409, $this->httpStatus());

        // Formulaire envoyé par un autre site : refusé (même protection que la connexion par SMS)
        $this->client->request('POST', '/api/auth/whatsapp/start', ['phone' => '690000001']);
        self::assertSame(415, $this->httpStatus());
        $this->postJson('/api/auth/whatsapp/start', ['phone' => '690000001'], ['HTTP_ORIGIN' => 'https://exemple.com']);
        self::assertSame(403, $this->httpStatus());
    }

    public function testTheCustomerGetsTheCodeOnWhatsappAndEntersItInTheApp(): void
    {
        $start = $this->start();
        $this->receive(self::FROM, WhatsappConfig::messageFor($start['reference']));
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['received' => true], json_decode((string) $this->client->getResponse()->getContent(), true));

        $sent = $this->messenger()->sent;
        self::assertCount(1, $sent);
        self::assertSame(self::FROM, $sent[0]['to']);
        $code = $this->codeIn($sent[0]['text']);
        self::assertSame(WhatsappWebhook::codeMessage($code), $sent[0]['text']);
        self::assertStringContainsString('valable 5 minutes', $sent[0]['text']);
        self::assertSame([], $this->sms()->sent, 'aucun SMS');

        // Code jamais gardé en clair ; il compte comme un code WhatsApp
        $row = $this->em()->getConnection()->fetchAssociative('SELECT code_hash, channel, sent_at FROM otp_codes');
        self::assertNotSame($code, $row['code_hash']);
        self::assertSame('whatsapp', $row['channel']);
        self::assertNotNull($row['sent_at']);
        self::assertSame('code_sent', $this->em()->getConnection()->fetchOne('SELECT status FROM whatsapp_login_requests'));

        // Même vérification que le code SMS
        $data = $this->verifyCode($code);
        self::assertSame(200, $this->httpStatus());
        self::assertTrue($data['isNewUser']);
        self::assertSame(self::PHONE, $data['user']['phone']);
        self::assertNotNull($this->refreshCookie());
        self::assertSame(self::PHONE, $this->getJson('/api/me', $data['accessToken'])['phone'] ?? null);

        // Le code ne sert qu'une fois
        $this->verifyCode($code);
        self::assertSame(410, $this->httpStatus());
    }

    public function testAKnownCustomerKeepsTheAccount(): void
    {
        $this->createUser();
        $start = $this->start();
        $this->receive(self::FROM, strtolower($start['reference'])); // référence seule, recopiée à la main
        $data = $this->verifyCode($this->codeIn($this->messenger()->sent[0]['text'] ?? ''));
        self::assertSame(200, $this->httpStatus());
        self::assertFalse($data['isNewUser']);
        self::assertSame('Junior', $data['user']['firstName'] ?? null);
    }

    public function testTheReferenceIsFoundInTheMessageEvenRewritten(): void
    {
        foreach (['Bonjour, Réf. %s merci', 'ref:%s', 'RÉFÉRENCE %s', "je veux mon code\nref - %s"] as $i => $text) {
            if ($i > 0) {
                $this->wait(601); // 3 codes au plus en 10 min pour un numéro
            }
            $start = $this->start();
            $this->receive(self::FROM, sprintf($text, strtolower($start['reference'])));
            self::assertStringStartsWith('Votre code de connexion DR Choco', $this->messenger()->sent[0]['text'] ?? '', $text);
        }
    }

    public function testAReferenceSentFromAnotherWhatsappGetsNoCode(): void
    {
        $start = $this->start();
        $this->receive('237699999999', WhatsappConfig::messageFor($start['reference']));
        self::assertSame([['to' => '237699999999', 'text' => WhatsappWebhook::wrongSenderMessage(self::PHONE)]], $this->messenger()->sent);
        self::assertStringContainsString('demandé pour le 6 90 00 00 01', $this->messenger()->sent[0]['text']);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM otp_codes'));
        self::assertNotNull($this->em()->getConnection()->fetchOne('SELECT wrong_sender_at FROM whatsapp_login_requests'));

        // Même message renvoyé depuis le même autre WhatsApp : pas de seconde explication dans l'heure
        $this->receive('237699999999', WhatsappConfig::messageFor($start['reference']));
        self::assertSame([], $this->messenger()->sent);

        // Le WhatsApp du numéro saisi peut toujours obtenir le code
        $this->receive(self::FROM, WhatsappConfig::messageFor($start['reference']));
        self::assertStringStartsWith('Votre code de connexion DR Choco', $this->messenger()->sent[0]['text'] ?? '');
    }

    public function testOnlyMessagesSignedByMetaAreRead(): void
    {
        $start = $this->start();
        $this->receive(self::FROM, $start['reference'], signature: 'sha256=' . str_repeat('0', 64));
        self::assertSame(401, $this->httpStatus());
        $this->receive(self::FROM, $start['reference'], signature: '');
        self::assertSame(401, $this->httpStatus());
        self::assertSame([], $this->messenger()->sent);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM otp_codes'));
    }

    public function testOneRequestGivesOneCode(): void
    {
        $start = $this->start();

        // Meta envoie deux fois le même message : un seul code, une seule réponse
        $this->receive(self::FROM, $start['reference'], messageId: 'wamid.double');
        self::assertCount(1, $this->messenger()->sent);
        $this->receive(self::FROM, $start['reference'], messageId: 'wamid.double');
        self::assertSame(200, $this->httpStatus());
        self::assertSame([], $this->messenger()->sent, 'aucune seconde réponse');

        // Le client renvoie le message : le code déjà envoyé reste le bon
        $this->receive(self::FROM, $start['reference']);
        self::assertSame([['to' => self::FROM, 'text' => WhatsappWebhook::REPLY_ALREADY_SENT]], $this->messenger()->sent);
        $this->receive(self::FROM, $start['reference']);
        self::assertSame([], $this->messenger()->sent, 'rappel une fois par heure au plus');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM otp_codes'));
    }

    public function testAnOldOrReplacedReferenceGetsNoCode(): void
    {
        $first = $this->start();
        $second = $this->start();

        // Une nouvelle demande remplace la précédente.
        $this->receive(self::FROM, $first['reference']);
        self::assertSame([['to' => self::FROM, 'text' => WhatsappWebhook::REPLY_UNKNOWN]], $this->messenger()->sent);

        // Au-delà de 10 minutes, la demande expire.
        $this->wait(601);
        $this->receive(self::FROM, $second['reference']);
        self::assertSame([['to' => self::FROM, 'text' => WhatsappWebhook::REPLY_UNKNOWN]], $this->messenger()->sent);
        $this->receive(self::FROM, 'Réf. ZZZZZZ');
        self::assertSame([['to' => self::FROM, 'text' => WhatsappWebhook::REPLY_UNKNOWN]], $this->messenger()->sent);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM otp_codes'));
    }

    public function testTheCodeIsValidForFiveMinutes(): void
    {
        $start = $this->start();
        $this->receive(self::FROM, $start['reference']);
        $code = $this->codeIn($this->messenger()->sent[0]['text'] ?? '');

        $this->wait(301);
        self::assertSame('OTP_EXPIRED', $this->verifyCode($code)['code'] ?? null);
        self::assertSame(410, $this->httpStatus());
    }

    public function testAfterAWhatsappCodeTheSmsLeavesAtOnce(): void
    {
        $start = $this->start();
        $this->receive(self::FROM, $start['reference']);
        $whatsappCode = $this->codeIn($this->messenger()->sent[0]['text'] ?? '');

        // Pas reçu sur WhatsApp : le client choisit le SMS, envoyé sans attendre 60 s ; le nouveau code remplace l'autre.
        $this->requestCode();
        self::assertSame(200, $this->httpStatus());
        self::assertCount(1, $this->sms()->sent);
        $smsCode = (string) $this->sms()->lastCodeFor(self::PHONE);
        if ($whatsappCode !== $smsCode) {
            $this->verifyCode($whatsappCode);
            self::assertSame(422, $this->httpStatus());
        }
        $this->verifyCode($smsCode);
        self::assertSame(200, $this->httpStatus());
    }

    public function testCodeLimitsAreSharedWithTheSms(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $start = $this->start();
            $this->receive(self::FROM, $start['reference']);
            self::assertStringStartsWith('Votre code de connexion DR Choco', $this->messenger()->sent[0]['text'] ?? '');
        }

        // 4e code en 10 min : refusé, avec le délai.
        $start = $this->start();
        $this->receive(self::FROM, $start['reference']);
        self::assertSame([['to' => self::FROM, 'text' => "Trop de demandes de code. Réessayez dans 10\u{00A0}min."]], $this->messenger()->sent);
        self::assertSame(3, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM otp_codes'));
        $this->requestCode();
        self::assertSame(429, $this->httpStatus(), 'le SMS non plus');

        $this->wait(601);
        $start = $this->start();
        $this->receive(self::FROM, $start['reference']);
        self::assertStringStartsWith('Votre code de connexion DR Choco', $this->messenger()->sent[0]['text'] ?? '');
    }

    public function testWhatsappCodesDoNotUseTheDailySmsCap(): void
    {
        $values = [];
        $twoHoursAgo = $this->clock->now()->modify('-2 hours')->format('Y-m-d H:i:s');
        for ($i = 0; $i < 300; ++$i) {
            $values[] = sprintf("('+2376%08d', '%s', '%s', '%s', '%s', 5, 'whatsapp')", $i + 1000, str_repeat('a', 64), $twoHoursAgo, $twoHoursAgo, $twoHoursAgo);
        }
        $this->em()->getConnection()->executeStatement('INSERT INTO otp_codes (phone, code_hash, created_at, expires_at, sent_at, attempts_left, channel) VALUES ' . implode(',', $values));

        $this->requestCode('+237690000003');
        self::assertSame(200, $this->httpStatus());
        self::assertCount(1, $this->sms()->sent);
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
        self::assertSame("Trop de demandes de code. Réessayez dans 10\u{00A0}min.", $data['message']);
        self::assertSame('600', $this->header('Retry-After'));

        $this->start('690000002');
        self::assertSame(200, $this->httpStatus(), 'un autre numéro n’est pas bloqué');
        $this->wait(601);
        $this->start();
        self::assertSame(200, $this->httpStatus());
    }

    public function testABlockedAccountGetsNoCode(): void
    {
        $this->createUser(self::PHONE, User::STATUS_BLOCKED);
        self::assertSame('ACCOUNT_BLOCKED', $this->start()['code'] ?? null);
        self::assertSame(403, $this->httpStatus());

        // Bloqué pendant l'attente du message : pas de code
        $start = $this->start('690000002');
        $user = $this->createUser('+237690000002');
        $user->setStatus(User::STATUS_BLOCKED);
        $this->em()->flush();
        $this->receive('237690000002', $start['reference']);
        self::assertSame([['to' => '237690000002', 'text' => WhatsappWebhook::REPLY_BLOCKED]], $this->messenger()->sent);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM otp_codes'));
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
            ['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '999'], 'messages' => [['from' => self::FROM, 'id' => 'wamid.autre', 'type' => 'text', 'text' => ['body' => $start['reference']]]]]],
        ];
        $this->post(json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => $events]]], \JSON_THROW_ON_ERROR));
        self::assertSame(200, $this->httpStatus());
        $this->post('pas du json');
        self::assertSame(200, $this->httpStatus(), 'signé mais illisible : ignoré, jamais renvoyé par Meta en boucle');
        self::assertSame([], $this->messenger()->sent);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM otp_codes'));

        // Message sans référence : une aide, une fois par heure au plus.
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

    public function testWithoutItsKeysTheAppOffersOnlyTheSms(): void
    {
        // Sans clé secrète ou sans jeton (impossible d'envoyer le code) : pas de connexion par WhatsApp
        self::assertFalse((new WhatsappConfig('237659881683', self::PHONE_NUMBER_ID, 'jeton', '', 'jeton', 'v21.0'))->isLoginReady());
        self::assertFalse((new WhatsappConfig('237659881683', self::PHONE_NUMBER_ID, 'jeton', 'cle', '', 'v21.0'))->isLoginReady());
        self::assertFalse((new WhatsappConfig('237659881683', '', 'jeton', 'cle', 'jeton', 'v21.0'))->isLoginReady());
        self::assertFalse((new WhatsappConfig('', self::PHONE_NUMBER_ID, 'jeton', 'cle', 'jeton', 'v21.0'))->isLoginReady());

        $config = new WhatsappConfig('+237 6 59 88 16 83', self::PHONE_NUMBER_ID, 'jeton', 'cle', 'jeton', 'v21.0');
        self::assertTrue($config->isLoginReady());
        self::assertSame('https://graph.facebook.com/v21.0/' . self::PHONE_NUMBER_ID . '/messages', $config->graphUrl(self::PHONE_NUMBER_ID . '/messages'));
        $local = new WhatsappConfig('237659881683', self::PHONE_NUMBER_ID, 'jeton', 'cle', 'jeton', 'v21.0', 'http://127.0.0.1:8095/');
        self::assertSame('http://127.0.0.1:8095/v21.0/1/messages', $local->graphUrl('1/messages'));
        $wrong = new WhatsappConfig('237659881683', self::PHONE_NUMBER_ID, 'jeton', 'cle', 'jeton', 'v21.0', 'graph.exemple.com');
        self::assertSame('https://graph.facebook.com/v21.0/1/messages', $wrong->graphUrl('1/messages'));
    }

    // ── Aides ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function start(string $phone = '690000001', mixed $reference = null): array
    {
        return $this->postJson('/api/auth/whatsapp/start', $reference === null ? ['phone' => $phone] : ['phone' => $phone, 'reference' => $reference]);
    }

    private function codeIn(string $reply): string
    {
        self::assertSame(1, preg_match('/\*(\d{6})\*/', $reply, $match), $reply);

        return $match[1];
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
