<?php

namespace App\Tests\Unit;

use App\Account\PhoneNumber;
use App\Api\ClientIp;
use App\Auth\SmsOtpSender;
use App\Service\Notification\SmsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Numéros camerounais (mêmes règles que le front) et texte du SMS de connexion.
 */
final class PhoneAndSmsTest extends TestCase
{
    public function testCameroonMobileNormalization(): void
    {
        $valid = [
            '+237690000001'         => '+237690000001',
            '690000001'             => '+237690000001',
            '6 90 00 00 01'         => '+237690000001',
            '00237 690 00 00 01'    => '+237690000001',
            '237690000001'          => '+237690000001',
            '(+237) 690-00-00-01'   => '+237690000001',
            "690\u{00A0}00\u{00A0}00\u{00A0}01" => '+237690000001',
            '677.88.99.00'          => '+237677889900',
        ];
        foreach ($valid as $raw => $expected) {
            self::assertSame($expected, PhoneNumber::normalizeCameroonMobile((string) $raw), (string) $raw);
        }

        foreach (['', '69000000', '6900000011', '290000001', '+237290000001', '+33612345678', 'abc', "\xff\xfe", null, 690000001] as $raw) {
            self::assertNull(PhoneNumber::normalizeCameroonMobile($raw), var_export($raw, true));
        }
    }

    public function testMask(): void
    {
        self::assertSame('+2376****0001', PhoneNumber::mask('+237690000001'));
    }

    public function testSmsTextFitsInOneSmsAndUsesTheBasicAlphabet(): void
    {
        $text = SmsOtpSender::message('482913', 'drchoco.cm');

        self::assertStringContainsString('482913', $text);
        self::assertStringEndsWith("\n\n@drchoco.cm #482913", $text, 'ligne WebOTP (remplissage automatique Android)');
        // Mention ajoutée par un compte Twilio d'essai : « Sent from your Twilio trial account - ».
        self::assertLessThanOrEqual(160 - 38, mb_strlen($text));

        $gsm = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        foreach (mb_str_split($text) as $char) {
            self::assertStringContainsString($char, $gsm, "caractère hors alphabet SMS de base : « $char »");
        }

        // Domaine inconnu ou douteux : pas de ligne WebOTP.
        self::assertStringNotContainsString('@', SmsOtpSender::message('482913'));
        self::assertStringNotContainsString('@', SmsOtpSender::message('482913', 'evil.com #000000'));
    }

    public function testLogTransportNeverSendsInDevelopment(): void
    {
        $sms = $this->createMock(SmsService::class);
        $sms->expects(self::never())->method('send');

        $sender = new SmsOtpSender($sms, new NullLogger(), 'log', '', 'dev');
        self::assertTrue($sender->usesLogTransport());
        self::assertTrue($sender->send('+237690000001', '123456'));
    }

    public function testLogTransportIsIgnoredInProduction(): void
    {
        $sms = $this->createMock(SmsService::class);
        $sms->expects(self::once())->method('send')->with('+237690000001', self::stringContains('123456'))->willReturn(false);

        $sender = new SmsOtpSender($sms, new NullLogger(), 'log', '', 'prod', 'AC-test', 'jeton', '+15550000000');
        self::assertFalse($sender->usesLogTransport());
        self::assertFalse($sender->send('+237690000001', '123456'));
    }

    public function testMissingTwilioCredentialsNeverCallTwilio(): void
    {
        $sms = $this->createMock(SmsService::class);
        $sms->expects(self::never())->method('send');

        $sender = new SmsOtpSender($sms, new NullLogger(), 'twilio', '', 'prod', '', '', '');
        self::assertFalse($sender->isTwilioConfigured());
        self::assertFalse($sender->send('+237690000001', '123456'));
    }

    public function testTwilioCrashIsReportedAsAFailedSend(): void
    {
        $sms = $this->createMock(SmsService::class);
        $sms->method('send')->willThrowException(new \TypeError('identifiant Twilio manquant'));

        $sender = new SmsOtpSender($sms, new NullLogger(), 'twilio', '', 'prod', 'AC-test', 'jeton', '+15550000000');
        self::assertFalse($sender->send('+237690000001', '123456'));
    }

    public function testIpLimiterKeyGroupsIpv6ByNetworkWithoutRevealingTheAddress(): void
    {
        $key = fn (?string $ip) => ClientIp::limiterKey($ip, 'secret-de-test');

        self::assertSame($key('2001:db8:1:2:aaaa::1'), $key('2001:db8:1:2:ffff:ffff:ffff:ffff'), 'même réseau /64');
        self::assertNotSame($key('2001:db8:1:2::1'), $key('2001:db8:1:3::1'));
        self::assertNotSame($key('41.202.219.73'), $key('41.202.219.74'), 'IPv4 : adresse entière');
        self::assertStringNotContainsString('41.202', $key('41.202.219.73'), 'jamais l’adresse en clair');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key('41.202.219.73'));
        self::assertNotSame($key('41.202.219.73'), ClientIp::limiterKey('41.202.219.73', 'autre-secret'));
        self::assertSame('inconnue', $key(null));
        self::assertSame('inconnue', $key('pas-une-ip'));
    }
}
