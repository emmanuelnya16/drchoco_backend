<?php

namespace App\Tests\Api\Catalog;

use App\Entity\Product;
use App\Tests\CatalogTestCase;

/**
 * POST /api/cart/validate (contrat §9.1) et POST /api/client-errors (contrat §13.1).
 */
final class CartAndClientErrorsTest extends CatalogTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Compteurs de la limite de débit remis à zéro.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testCartValidationStatuses(): void
    {
        $data = $this->postJson('/api/cart/validate', ['items' => [
            ['variantId' => 'prod-01-noir-s', 'quantity' => 1],   // stock 4
            ['variantId' => 'prod-01-noir-l', 'quantity' => 5],   // stock 2
            ['variantId' => 'prod-01-noir-xxl', 'quantity' => 1], // épuisée
            ['variantId' => 'variante-disparue', 'quantity' => 1],
            ['variantId' => 'prod-01-noir-m', 'quantity' => 1, 'unitPrice' => 40000],
        ]]);

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $data['checkedAt']);
        self::assertSame([
            ['variantId' => 'prod-01-noir-s', 'status' => 'ok', 'availableQuantity' => 4, 'unitPrice' => 45000],
            ['variantId' => 'prod-01-noir-l', 'status' => 'reduced', 'availableQuantity' => 2, 'unitPrice' => 45000],
            ['variantId' => 'prod-01-noir-xxl', 'status' => 'unavailable', 'availableQuantity' => 0, 'unitPrice' => 45000],
            ['variantId' => 'variante-disparue', 'status' => 'unavailable', 'availableQuantity' => 0, 'unitPrice' => 0],
            ['variantId' => 'prod-01-noir-m', 'status' => 'price_changed', 'availableQuantity' => 6, 'unitPrice' => 45000, 'previousPrice' => 40000],
        ], $data['items']);
    }

    public function testRetiredProductIsUnavailableInTheCart(): void
    {
        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setIsActive(false));

        $data = $this->postJson('/api/cart/validate', ['items' => [['variantId' => 'prod-01-noir-s', 'quantity' => 1]]]);

        self::assertSame('unavailable', $data['items'][0]['status']);
        self::assertSame(0, $data['items'][0]['availableQuantity']);
    }

    public function testCartRejectsAMalformedBody(): void
    {
        foreach (['{"items":"x"}', 'pas du json', '{"items":[{"quantity":1}]}'] as $body) {
            $data = $this->postJson('/api/cart/validate', $body);
            self::assertSame(422, $this->httpStatus(), $body);
            self::assertSame('VALIDATION_ERROR', $data['code']);
        }
    }

    public function testClientErrorIsAcceptedWithoutContent(): void
    {
        $this->postJson('/api/client-errors', $this->clientError());

        self::assertSame(204, $this->httpStatus());
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));
    }

    public function testClientErrorIsPublicEvenWithAnInvalidToken(): void
    {
        $this->postJson('/api/client-errors', $this->clientError(), ['HTTP_AUTHORIZATION' => 'Bearer jeton.invalide.xyz']);

        self::assertSame(204, $this->httpStatus());
    }

    public function testClientErrorValidation(): void
    {
        $data = $this->postJson('/api/client-errors', ['message' => 'x']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(['page', 'appVersion', 'userAgent', 'occurredAt'], array_keys($data['details']));

        $this->postJson('/api/client-errors', ['message' => str_repeat('a', 70000)] + $this->clientError());
        self::assertSame(413, $this->httpStatus());
    }

    public function testClientErrorsAreRateLimitedPerAddress(): void
    {
        for ($i = 1; $i <= 20; ++$i) {
            $this->postJson('/api/client-errors', $this->clientError());
            self::assertSame(204, $this->httpStatus(), "envoi n° $i");
        }

        $data = $this->postJson('/api/client-errors', $this->clientError());
        self::assertSame(429, $this->httpStatus());
        self::assertSame('RATE_LIMITED', $data['code']);
        self::assertGreaterThan(0, (int) $this->header('Retry-After'));
        self::assertSame((int) $this->header('Retry-After'), $data['details']['retryAfter']);
    }

    /** @return array<string, mixed> */
    private function clientError(): array
    {
        return [
            'message'    => "TypeError: Cannot read properties of undefined (reading 'map')",
            'stack'      => "TypeError\n    at TimelineList (https://drchoco.com/assets/x.js:1:2048)",
            'page'       => '/reservations/RES-000124',
            'appVersion' => '0.6.0',
            'userAgent'  => 'Mozilla/5.0 (Linux; Android 14)',
            'zone'       => 'page',
            'route'      => '/reservations/:ref',
            'extra'      => ['endpoint' => 'GET /reservations/:ref', 'issues' => 2],
            'occurredAt' => '2026-10-31T11:42:07.512Z',
        ];
    }
}
