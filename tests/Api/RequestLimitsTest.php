<?php

namespace App\Tests\Api;

use App\Account\TextInput;
use App\Entity\Admin;
use App\Tests\AdminTestCase;
use Monolog\Handler\TestHandler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Demandes démesurées ou malformées (P9) : taille des corps JSON, rapports d'erreur, textes invisibles, écritures de
 * l'équipe à la chaîne, identifiants géants, recherche illisible.
 */
final class RequestLimitsTest extends AdminTestCase
{
    public function testJsonBodiesAreLimitedBeforeBeingRead(): void
    {
        $this->account();
        $token = $this->adminToken('owner@drchoco.cm');
        $big = str_repeat('x', 70_000);

        $data = $this->postJson('/api/auth/otp/request', ['phone' => '690000001', 'x' => $big]);
        self::assertSame([413, 'PAYLOAD_TOO_LARGE'], [$this->httpStatus(), $data['code']]);
        $data = $this->postJson('/api/cart/validate', ['items' => [], 'x' => $big]);
        self::assertSame([413, 'PAYLOAD_TOO_LARGE'], [$this->httpStatus(), $data['code']]);
        // Taille annoncée énorme : refusée sans lire le corps.
        $this->client->request('POST', '/api/auth/otp/request', [], [], ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '99999999', 'HTTP_ACCEPT' => 'application/json'], '{}');
        self::assertSame(413, $this->httpStatus());

        // Formulaires longs de l'administration : 1 Mo.
        $this->api('PATCH', '/api/admin/pages/mentions-legales', $token, ['title' => 'Mentions légales', 'body' => str_repeat('a', 90_000)]);
        self::assertSame(200, $this->httpStatus());
        $data = $this->api('PATCH', '/api/admin/pages/mentions-legales', $token, ['title' => 'x', 'body' => str_repeat('a', 1_100_000)]);
        self::assertSame([413, 'PAYLOAD_TOO_LARGE'], [$this->httpStatus(), $data['code']]);

        // JSON trop imbriqué ou liste : 422, jamais 500.
        foreach ([str_repeat('[', 40) . str_repeat(']', 40), '[1,2]', '"texte"', '{"a":' . str_repeat('{"b":', 30) . '1' . str_repeat('}', 31), "{\"phone\":\"\xB1\x31\"}"] as $body) {
            $this->postJson('/api/auth/otp/request', $body);
            self::assertSame(422, $this->httpStatus(), $body);
        }
    }

    public function testErrorReportsAreBounded(): void
    {
        $report = ['message' => 'Erreur', 'page' => '/reservations/RES-000001?jeton=secret#x', 'appVersion' => '1', 'userAgent' => 'UA', 'occurredAt' => '2026-11-24T10:00:00Z'];
        $this->client->request('POST', '/api/client-errors', [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($report));
        self::assertSame(415, $this->httpStatus(), 'JSON exigé (pas d’envoi depuis un formulaire d’un autre site)');

        $this->postJson('/api/client-errors', $report + ['extra' => array_fill_keys(array_map(fn (int $i) => "cle$i", range(1, 15)), str_repeat('é', 190)), 'stack' => str_repeat("ligne\n", 500)]);
        self::assertSame(204, $this->httpStatus());

        for ($i = 0; $i < 30 && $this->httpStatus() === 204; ++$i) {
            $this->postJson('/api/client-errors', $report);
        }
        self::assertSame(429, $this->httpStatus());
    }

    public function testTeamWritesAndUploadsAreLimited(): void
    {
        $owner = $this->account();
        $token = $this->adminToken('owner@drchoco.cm');
        $limiter = static::getContainer()->get('limiter.admin_writes')->create('admin-' . $owner->getId());
        $limiter->consume(120 - 1);
        $this->api('PATCH', '/api/admin/settings', $token, []);
        self::assertSame(200, $this->httpStatus());
        $data = $this->api('PATCH', '/api/admin/settings', $token, []);
        self::assertSame([429, 'RATE_LIMITED'], [$this->httpStatus(), $data['code']]);
        self::assertGreaterThan(0, $data['details']['retryAfter']);
        $this->api('GET', '/api/admin/settings', $token);
        self::assertSame(200, $this->httpStatus(), 'la lecture reste possible');
        $limiter->reset();

        static::getContainer()->get('limiter.admin_media')->create('admin-' . $owner->getId())->consume(60);
        $path = (string) tempnam(sys_get_temp_dir(), 'drc');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $this->client->request('POST', '/api/admin/media', ['kind' => 'product'], ['file' => new UploadedFile($path, 'photo.png', 'image/png', null, true)], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json']);
        self::assertSame(429, $this->httpStatus());
        @unlink($path);
    }

    public function testInvisibleCharactersNeverMakeAName(): void
    {
        self::assertSame('', TextInput::clean("\u{200B}\u{2060}\u{FEFF}"));
        self::assertSame('Junior 41 09 96', TextInput::clean("Junior\u{202E} 41\u{00A0}09\u{202F}96\u{2066}"));
        self::assertSame('Awa Ndzié', TextInput::clean("  Awa\n\tNdzi\u{00AD}é  "));
        self::assertNull(TextInput::clean("\xC3\x28"));

        $this->account();
        $data = $this->api('POST', '/api/admin/sellers', $this->adminToken('owner@drchoco.cm'), ['name' => "\u{200B}\u{3164}", 'email' => 'vendeur@drchoco.cm', 'password' => 'Mot-de-passe-long', 'city' => 'Yaoundé']);
        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('name', $data['details']);
    }

    public function testUnreadableIdentifiersAndSearchesNeverBreakTheServer(): void
    {
        $handler = new TestHandler();
        static::getContainer()->get('logger')->pushHandler($handler);
        foreach (['/api/media/private/proofs/99999999999999999999?expires=1&signature=x', '/api/media/private/package-photos/99999999999999999999?expires=1&signature=x'] as $uri) {
            $this->client->request('GET', $uri);
            self::assertContains($this->httpStatus(), [403, 404], $uri);
        }
        $this->getJson('/api/media/private/proofs/4294967296?expires=1&signature=x');
        self::assertSame(403, $this->httpStatus());

        foreach (['%C3%28', '%00', '%F0%9F', str_repeat('a', 2000), '%25%27%22%5C'] as $query) {
            $this->getJson('/api/search/suggest?q=' . $query);
            self::assertContains($this->httpStatus(), [200, 422], $query);
            $this->getJson('/api/products?q=' . $query);
            self::assertContains($this->httpStatus(), [200, 422], $query);
        }
        self::assertFalse($handler->hasCriticalRecords());
        self::assertFalse($handler->hasErrorRecords());
    }

    public function testSellersAreNotBlockedByTheWritesOfOthers(): void
    {
        $owner = $this->account();
        $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé');
        static::getContainer()->get('limiter.admin_writes')->create('admin-' . $owner->getId())->consume(120);
        $this->api('PATCH', '/api/admin/products/prod-01/stock', $this->adminToken('vendeur@drchoco.cm'), ['variants' => []]);
        self::assertSame(200, $this->httpStatus());
    }
}
