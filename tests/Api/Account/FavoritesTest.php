<?php

namespace App\Tests\Api\Account;

use App\Entity\Product;
use App\Tests\AccountTestCase;

/**
 * Favoris : POST /api/favorites (contrat §5.7), GET et DELETE (§16).
 */
final class FavoritesTest extends AccountTestCase
{
    public function testMergeReturnsEveryFavoriteWithoutDuplicates(): void
    {
        $token = $this->login()['token'];

        $data = $this->api('POST', '/api/favorites', $token, ['productIds' => ['prod-01', 'prod-04']]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['prod-01', 'prod-04'], $data);
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));

        // Second téléphone : ses favoris s'ajoutent, sans doublon.
        self::assertSame(['prod-01', 'prod-04', 'prod-14'], $this->api('POST', '/api/favorites', $token, ['productIds' => ['prod-04', 'prod-14', 'prod-14']]));

        // Ancienne forme acceptée par les mocks.
        self::assertSame(['prod-01', 'prod-04', 'prod-14', 'prod-02'], $this->api('POST', '/api/favorites', $token, ['productId' => 'prod-02']));
    }

    public function testUnknownOrRetiredProductsAreIgnoredSilently(): void
    {
        $token = $this->login()['token'];
        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setIsActive(false)); // prod-01

        $data = $this->api('POST', '/api/favorites', $token, ['productIds' => ['prod-01', 'inconnu', 42, null, str_repeat('x', 300), 'prod-04']]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['prod-04'], $data);

        self::assertSame([], $this->api('POST', '/api/favorites', $this->login('+237690000002')['token'], ['productIds' => 'pas-une-liste']));
        self::assertSame(200, $this->httpStatus());
    }

    public function testRetiredFavoriteDisappearsFromTheList(): void
    {
        $token = $this->login()['token'];
        $this->api('POST', '/api/favorites', $token, ['productIds' => ['prod-01', 'prod-04']]);
        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setIsActive(false));

        self::assertSame(['prod-04'], $this->api('GET', '/api/favorites', $token));
        self::assertStringContainsString('no-cache', (string) $this->header('Cache-Control'));
    }

    public function testRemoveAFavorite(): void
    {
        $token = $this->login()['token'];
        $this->api('POST', '/api/favorites', $token, ['productIds' => ['prod-01', 'prod-04']]);

        $this->client->request('DELETE', '/api/favorites/prod-01', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertSame(204, $this->httpStatus());
        $this->client->request('DELETE', '/api/favorites/jamais-favori', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertSame(204, $this->httpStatus());

        self::assertSame(['prod-04'], $this->api('GET', '/api/favorites', $token));
    }

    public function testFavoritesBelongToTheirAccount(): void
    {
        $this->api('POST', '/api/favorites', $this->login()['token'], ['productIds' => ['prod-01']]);
        self::assertSame([], $this->api('GET', '/api/favorites', $this->login('+237690000002')['token']));

        $this->api('POST', '/api/favorites', null, ['productIds' => ['prod-01']]);
        self::assertSame(401, $this->httpStatus());
    }
}
