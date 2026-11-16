<?php

namespace App\Tests\Api\Auth;

use App\Api\ApiDate;
use App\Entity\User;
use App\Tests\AccountTestCase;

/**
 * POST /api/auth/otp/request et POST /api/auth/otp/verify (contrat §4.1, §4.2, §17 question 17).
 */
final class OtpTest extends AccountTestCase
{
    public function testRequestSendsASixDigitCodeAndAnswersLikeTheContract(): void
    {
        $data = $this->requestCode();

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['message' => 'Code envoyé par SMS.', 'expiresIn' => 300, 'resendIn' => 60, 'expiresInSeconds' => 300], $data);
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));
        self::assertCount(1, $this->sms()->sent);
        self::assertSame(self::PHONE, $this->sms()->sent[0]['phone']);
        self::assertMatchesRegularExpression('/^\d{6}$/', $this->sms()->sent[0]['code']);

        // Le code n'est jamais gardé en clair.
        $row = $this->em()->getConnection()->fetchAssociative('SELECT code_hash FROM otp_codes');
        self::assertSame(64, \strlen($row['code_hash']));
        self::assertStringNotContainsString($this->sms()->sent[0]['code'], $row['code_hash']);
    }

    public function testPhoneIsNormalizedLikeTheApp(): void
    {
        foreach (["6 90 00 00 02", '00237690000003', '237690000004', "+237\u{00A0}690\u{00A0}00\u{00A0}00\u{00A0}05", '690-000-006'] as $i => $raw) {
            $this->requestCode($raw);
            self::assertSame(200, $this->httpStatus(), $raw);
            self::assertSame('+23769000000' . ($i + 2), $this->sms()->sent[$i]['phone']);
        }
    }

    public function testInvalidPhoneIsRejected(): void
    {
        foreach (['', '+237290000001', '69000000', '6900000011', 'abc', '+33612345678'] as $raw) {
            $data = $this->requestCode($raw);
            self::assertSame(422, $this->httpStatus(), $raw);
            self::assertSame('INVALID_PHONE', $data['code']);
            self::assertSame('Ce numéro semble incomplet. Entrez les 9 chiffres commençant par 6.', $data['message']);
        }

        self::assertSame('INVALID_PHONE', $this->postJson('/api/auth/otp/request', ['phone' => 690000001])['code']);
        self::assertSame('INVALID_PHONE', $this->postJson('/api/auth/otp/request', [])['code']);
        self::assertSame('VALIDATION_ERROR', $this->postJson('/api/auth/otp/request', 'pas du json')['code']);
        self::assertSame([], $this->sms()->sent);
    }

    public function testFirstVerifyCreatesTheAccountAndOpensASession(): void
    {
        $this->requestCode();
        $data = $this->verifyCode($this->sms()->lastCodeFor(self::PHONE));

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['accessToken', 'user', 'isNewUser'], array_keys($data));
        self::assertNotSame('', $data['accessToken']);
        self::assertTrue($data['isNewUser']);
        self::assertSame(['id', 'phone', 'createdAt'], array_keys($data['user']), 'prénom, nom et ville omis tant qu’inconnus');
        self::assertIsString($data['user']['id']);
        self::assertSame(self::PHONE, $data['user']['phone']);
        self::assertSame(ApiDate::format($this->clock->now()), $data['user']['createdAt']);
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));

        // Cookie de session : httpOnly, Path=/api, SameSite=Lax, 90 jours.
        $setCookie = (string) $this->header('Set-Cookie');
        self::assertStringStartsWith('drc_refresh=', $setCookie);
        self::assertStringContainsString('path=/api', $setCookie);
        self::assertStringContainsString('httponly', strtolower($setCookie));
        self::assertStringContainsString('samesite=lax', strtolower($setCookie));
        $cookie = $this->refreshCookie();
        self::assertNotNull($cookie);
        self::assertEqualsWithDelta($this->clock->now()->getTimestamp() + 90 * 86400, (int) $cookie->getExpiresTime(), 2);

        // Le jeton ouvre les routes du client.
        $me = $this->api('GET', '/api/me', $data['accessToken']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame($data['user']['id'], $me['id']);

        self::assertNotNull($this->userByPhone()?->getLastLoginAt());
    }

    public function testSecondLoginFindsTheSameAccount(): void
    {
        $first = $this->login();
        $this->wait(120);
        $second = $this->login();

        self::assertFalse($second['isNewUser']);
        self::assertSame($first['user']['id'], $second['user']['id']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function testWrongCodesCountDownThenLockTheNumberForTenMinutes(): void
    {
        $this->requestCode();
        $good = $this->sms()->lastCodeFor(self::PHONE);
        $bad = $good === '000000' ? '111111' : '000000';

        foreach ([4, 3, 2, 1] as $left) {
            $data = $this->verifyCode($bad);
            self::assertSame(422, $this->httpStatus());
            self::assertSame('INVALID_OTP_CODE', $data['code']);
            self::assertSame(['attemptsLeft' => $left], $data['details']);
        }
        self::assertSame('Code incorrect. Il vous reste 1 essai.', $data['message']);

        $data = $this->verifyCode($bad);
        self::assertSame(429, $this->httpStatus());
        self::assertSame('OTP_LOCKED', $data['code']);
        self::assertSame(['retryAfter' => 600, 'attemptsLeft' => 0], $data['details']);
        self::assertSame('600', $this->header('Retry-After'));

        // Même le bon code est refusé pendant le verrou, et aucun nouveau code ne part.
        self::assertSame('OTP_LOCKED', $this->verifyCode($good)['code']);
        $data = $this->requestCode();
        self::assertSame(429, $this->httpStatus());
        self::assertSame('TOO_MANY_ATTEMPTS', $data['code']);
        self::assertGreaterThan(0, $data['details']['retryAfter']);
        self::assertCount(1, $this->sms()->sent);

        // 10 minutes plus tard : nouveau code, essais remis à 5.
        $this->wait(601);
        $this->requestCode();
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['attemptsLeft' => 4], $this->verifyCode($bad === $this->sms()->lastCodeFor(self::PHONE) ? '222222' : $bad)['details']);
        $this->verifyCode($this->sms()->lastCodeFor(self::PHONE));
        self::assertSame(200, $this->httpStatus());
    }

    public function testExpiredUsedOrMissingCodeIsRefused(): void
    {
        // Aucun code demandé.
        self::assertSame('OTP_EXPIRED', $this->verifyCode('123456')['code']);
        self::assertSame(410, $this->httpStatus());

        // Code expiré après 5 minutes.
        $this->requestCode();
        $this->wait(301);
        $data = $this->verifyCode($this->sms()->lastCodeFor(self::PHONE));
        self::assertSame(410, $this->httpStatus());
        self::assertSame('OTP_EXPIRED', $data['code']);

        // Code déjà utilisé.
        $this->requestCode();
        $code = $this->sms()->lastCodeFor(self::PHONE);
        $this->verifyCode($code);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('OTP_EXPIRED', $this->verifyCode($code)['code']);
    }

    public function testMalformedCodeIsRejectedWithoutUsingAnAttempt(): void
    {
        $this->requestCode();
        foreach (['12345', 'abcdef', '1234567', ''] as $code) {
            $data = $this->verifyCode($code);
            self::assertSame(422, $this->httpStatus(), $code);
            self::assertSame('INVALID_OTP_CODE', $data['code']);
            self::assertArrayNotHasKey('details', $data);
        }

        $good = $this->sms()->lastCodeFor(self::PHONE);
        self::assertSame(['attemptsLeft' => 4], $this->verifyCode($good === '999999' ? '888888' : '999999')['details']);

        // Espaces tolérés (code collé).
        $this->verifyCode(substr($good, 0, 3) . ' ' . substr($good, 3));
        self::assertSame(200, $this->httpStatus());
    }

    public function testRequestWithinSixtySecondsDoesNotSendAnotherSms(): void
    {
        $this->requestCode();
        $this->wait(20);
        $data = $this->requestCode();

        self::assertSame(200, $this->httpStatus());
        self::assertSame(40, $data['resendIn']);
        self::assertSame(280, $data['expiresIn']);
        self::assertCount(1, $this->sms()->sent);

        $this->verifyCode($this->sms()->lastCodeFor(self::PHONE));
        self::assertSame(200, $this->httpStatus());
    }

    public function testNewCodeCancelsThePreviousOne(): void
    {
        $this->requestCode();
        $old = $this->sms()->lastCodeFor(self::PHONE);
        $this->wait(61);
        $this->requestCode();
        $new = $this->sms()->lastCodeFor(self::PHONE);
        self::assertCount(2, $this->sms()->sent);

        if ($old !== $new) {
            self::assertSame('INVALID_OTP_CODE', $this->verifyCode($old)['code']);
        }
        $this->verifyCode($new);
        self::assertSame(200, $this->httpStatus());
    }

    public function testThreeRequestsPerTenMinutes(): void
    {
        foreach ([0, 61, 61] as $delay) {
            $this->wait($delay);
            $this->requestCode();
            self::assertSame(200, $this->httpStatus());
        }

        $this->wait(61);
        $data = $this->requestCode();
        self::assertSame(429, $this->httpStatus());
        self::assertSame('TOO_MANY_OTP_REQUESTS', $data['code']);
        self::assertSame(600 - 183, $data['details']['retryAfter']);
        self::assertSame((string) (600 - 183), $this->header('Retry-After'));
        self::assertCount(3, $this->sms()->sent);

        // Dix minutes après le premier envoi : de nouveau possible.
        $this->wait(600 - 183 + 1);
        $this->requestCode();
        self::assertSame(200, $this->httpStatus());
    }

    public function testTenCodesPerNumberPerDay(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $this->requestCode();
            self::assertSame(200, $this->httpStatus(), "code n°$i");
            $this->wait($i % 3 === 0 ? 601 : 61);
        }

        $this->wait(601);
        $data = $this->requestCode();
        self::assertSame(429, $this->httpStatus());
        self::assertSame('TOO_MANY_OTP_REQUESTS', $data['code']);
        self::assertGreaterThan(20 * 3600, $data['details']['retryAfter']);
        self::assertCount(10, $this->sms()->sent);
    }

    public function testFailedSmsAnswers503AndDoesNotCount(): void
    {
        $this->sms()->fail = true;
        $data = $this->requestCode();

        self::assertSame(503, $this->httpStatus());
        self::assertSame('SMS_UNAVAILABLE', $data['code']);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM otp_codes'));

        // Les échecs ne consomment pas les 3 demandes autorisées.
        $this->sms()->fail = false;
        foreach ([0, 61, 61] as $delay) {
            $this->wait($delay);
            $this->requestCode();
            self::assertSame(200, $this->httpStatus());
        }
    }

    public function testShopWideDailySmsCap(): void
    {
        $connection = $this->em()->getConnection();
        $values = [];
        $twoHoursAgo = $this->clock->now()->modify('-2 hours')->format('Y-m-d H:i:s');
        for ($i = 0; $i < 300; ++$i) {
            $values[] = sprintf("('+2376%08d', '%s', '%s', '%s', 5)", $i + 1000, str_repeat('a', 64), $twoHoursAgo, $twoHoursAgo);
        }
        $connection->executeStatement('INSERT INTO otp_codes (phone, code_hash, created_at, expires_at, attempts_left) VALUES ' . implode(',', $values));

        $data = $this->requestCode();
        self::assertSame(503, $this->httpStatus());
        self::assertSame('SMS_UNAVAILABLE', $data['code']);
        self::assertSame([], $this->sms()->sent);
    }

    public function testThirtyRequestsPerHourPerIpAddress(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->requestCode(sprintf('+2376900%05d', $i));
            self::assertSame(200, $this->httpStatus(), "demande n°$i");
        }

        $data = $this->requestCode('+237699999999');
        self::assertSame(429, $this->httpStatus());
        self::assertSame('TOO_MANY_OTP_REQUESTS', $data['code']);
        self::assertGreaterThan(0, $data['details']['retryAfter']);

        // Une autre adresse IP n'est pas concernée.
        $this->requestCode('+237699999999', ['REMOTE_ADDR' => '10.0.0.8']);
        self::assertSame(200, $this->httpStatus());
    }

    public function testBlockedAccountCanNeitherReceiveNorUseACode(): void
    {
        $this->createUser(self::PHONE, User::STATUS_BLOCKED);
        $data = $this->requestCode();
        self::assertSame(403, $this->httpStatus());
        self::assertSame('ACCOUNT_BLOCKED', $data['code']);
        self::assertSame([], $this->sms()->sent);

        // Bloqué après l'envoi du code : la connexion est refusée.
        $this->requestCode('+237690000002');
        $this->createUser('+237690000002', User::STATUS_BLOCKED);
        $data = $this->verifyCode($this->sms()->lastCodeFor('+237690000002'), '+237690000002');
        self::assertSame(403, $this->httpStatus());
        self::assertSame('ACCOUNT_BLOCKED', $data['code']);
        self::assertNull($this->header('Set-Cookie'));
    }

    public function testCodeOfAnotherNumberDoesNotWork(): void
    {
        $this->requestCode('+237690000002');
        $this->requestCode();
        $codeOfOther = $this->sms()->lastCodeFor('+237690000002');
        if ($codeOfOther === $this->sms()->lastCodeFor(self::PHONE)) {
            self::markTestSkipped('Les deux codes tirés au hasard sont identiques.');
        }

        self::assertSame('INVALID_OTP_CODE', $this->verifyCode($codeOfOther)['code']);
    }

    public function testRequestsFromAnotherSiteAreRefused(): void
    {
        // Page piégée d'un autre site : refusée, aucun SMS, aucun cookie.
        $data = $this->requestCode(self::PHONE, ['HTTP_ORIGIN' => 'https://site-piege.example']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('FORBIDDEN_ORIGIN', $data['code']);

        $this->postJson('/api/auth/otp/request', ['phone' => self::PHONE], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame([], $this->sms()->sent);

        // Formulaire HTML (text/plain) : pas du JSON, refusé.
        $this->client->request('POST', '/api/auth/otp/verify', [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode(['phone' => self::PHONE, 'code' => '123456']));
        self::assertSame(415, $this->httpStatus());
        self::assertNull($this->header('Set-Cookie'));

        // L'app elle-même (même domaine) et les origines de développement autorisées passent.
        $this->requestCode(self::PHONE, ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertSame(200, $this->httpStatus());
        $this->wait(61);
        $this->requestCode(self::PHONE, ['HTTP_ORIGIN' => 'http://localhost:3000']);
        self::assertSame(200, $this->httpStatus());
    }

    public function testFailedVerificationsAreLimitedPerIpAddress(): void
    {
        // Aucun code demandé : 30 vérifications « expiré » depuis la même adresse, puis refus.
        for ($i = 0; $i < 30; ++$i) {
            $this->verifyCode('123456', sprintf('+2376900%05d', $i));
            self::assertSame(410, $this->httpStatus(), "essai n°$i");
        }
        $data = $this->verifyCode('123456', '+237699999999');
        self::assertSame(429, $this->httpStatus());
        self::assertSame('TOO_MANY_ATTEMPTS', $data['code']);
        self::assertGreaterThan(0, $data['details']['retryAfter']);

        // Une connexion réussie ne compte pas, et une autre adresse IP n'est pas concernée.
        $this->requestCode();
        $this->client->request('POST', '/api/auth/otp/verify', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.0.9'], json_encode(['phone' => self::PHONE, 'code' => $this->sms()->lastCodeFor(self::PHONE)]));
        self::assertSame(200, $this->httpStatus());
    }

    public function testSuccessfulLoginsDoNotUseTheVerificationLimit(): void
    {
        for ($i = 0; $i < 35; ++$i) {
            $phone = sprintf('+2376910%05d', $i);
            $this->requestCode($phone, ['REMOTE_ADDR' => '10.0.' . intdiv($i, 25) . '.1']);
            $this->verifyCode($this->sms()->lastCodeFor($phone), $phone);
            self::assertSame(200, $this->httpStatus(), "connexion n°$i");
        }
    }

    public function testSenderCrashAnswers503AndLeavesNoCode(): void
    {
        $this->sms()->throw = true;
        $data = $this->requestCode();

        self::assertSame(503, $this->httpStatus());
        self::assertSame('SMS_UNAVAILABLE', $data['code']);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM otp_codes'));

        // Nouvelle demande aussitôt : un vrai envoi, pas un faux « Code envoyé ».
        $this->sms()->throw = false;
        $this->requestCode();
        self::assertSame(200, $this->httpStatus());
        self::assertCount(1, $this->sms()->sent);
    }
}
