<?php

namespace App\Tests\Api;

use App\Tests\ApiTestCase;

/**
 * P0 — l'API répond, et toute erreur suit le format unique du contrat (§2.2).
 */
final class HealthAndErrorsTest extends ApiTestCase
{
    public function testHealthIsOkAndNeverCached(): void
    {
        $body = $this->getJson('/api/health');

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $body['status']);
        self::assertSame('ok', $body['database']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testUnknownRouteReturnsJsonNotFound(): void
    {
        $body = $this->getJson('/api/route-inconnue');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame('NOT_FOUND', $body['code']);
        self::assertSame('La ressource demandée est introuvable.', $body['message']);
        self::assertArrayNotHasKey('details', $body);
    }

    public function testWrongMethodReturnsJsonMethodNotAllowed(): void
    {
        $this->client->request('POST', '/api/health');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertResponseStatusCodeSame(405);
        self::assertSame('METHOD_NOT_ALLOWED', $body['code']);
        self::assertResponseHasHeader('Allow');
    }

    public function testCorsPreflightAllowsCredentialsAndIdempotencyKey(): void
    {
        $this->client->request('OPTIONS', '/api/health', [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type, authorization, idempotency-key',
        ]);
        $headers = $this->client->getResponse()->headers;

        self::assertSame('http://localhost:3000', $headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $headers->get('Access-Control-Allow-Credentials'));
        self::assertStringContainsStringIgnoringCase('idempotency-key', (string) $headers->get('Access-Control-Allow-Headers'));
    }

    public function testCorsResponsesVaryByOrigin(): void
    {
        $this->getJson('/api/health', null, ['HTTP_ORIGIN' => 'http://localhost:3000']);

        self::assertContains('Origin', $this->client->getResponse()->getVary());
        self::assertSame('http://localhost:3000', $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }
}
