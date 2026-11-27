<?php

namespace App\Tests\Unit;

use App\Auth\Whatsapp\WhatsappCloudMessenger;
use App\Auth\Whatsapp\WhatsappConfig;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Réponse envoyée au client par l'API WhatsApp Cloud (faux Meta) : succès et refus écrits dans le journal WhatsApp,
 * avec la raison donnée par Meta, sans le texte du message.
 */
final class WhatsappCloudMessengerTest extends TestCase
{
    public function testAcceptedAndRefusedRepliesAreJournaled(): void
    {
        $journal = new TestHandler();
        $logger = new Logger('whatsapp', [$journal], [new PsrLogMessageProcessor(null, true)]);
        $config = new WhatsappConfig('237659881683', '1566791356509169', 'verif', 'cle', 'jeton', 'v21.0');

        $ok = new WhatsappCloudMessenger($config, new MockHttpClient(new MockResponse('{"messages":[{"id":"wamid.1"}]}')), $logger);
        self::assertTrue($ok->send('237690000001', 'Votre code de connexion DR Choco : *123456*'));

        $refused = new WhatsappCloudMessenger($config, new MockHttpClient(new MockResponse(json_encode(['error' => [
            'message'    => '(#131030) Recipient phone number not in allowed list',
            'code'       => 131030,
            'error_data' => ['details' => 'Recipient phone number not in allowed list: Add recipient phone number to recipient list and try again.'],
        ]]), ['http_code' => 400])), $logger);
        self::assertFalse($refused->send('237690000001', 'Votre code de connexion DR Choco : *123456*'));

        $lines = array_map(static fn ($record): string => $record->message, $journal->getRecords());
        self::assertSame('Réponse à +2376****0001 acceptée par Meta (la remise sur le téléphone suit dans le journal).', $lines[0]);
        self::assertSame('Réponse à +2376****0001 REFUSÉE par Meta (HTTP 400, erreur 131030 « (#131030) Recipient phone number not in allowed list » : Recipient phone number not in allowed list: Add recipient phone number to recipient list and try again.)', $lines[1]);
        self::assertStringNotContainsString('123456', implode("\n", $lines));
    }
}
