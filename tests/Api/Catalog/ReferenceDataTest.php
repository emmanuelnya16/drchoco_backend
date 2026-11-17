<?php

namespace App\Tests\Api\Catalog;

use App\Entity\AppSetting;
use App\Entity\ContentPage;
use App\Entity\Product;
use App\Tests\CatalogTestCase;

/**
 * Catégories, recherche, looks, réglages, livraison et pages (contrat §6.1, §6.4 à §8, §12).
 */
final class ReferenceDataTest extends CatalogTestCase
{
    public function testCategoriesWithLiveProductCounts(): void
    {
        $data = $this->getJson('/api/categories');

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['vetements', 'chaussures', 'babouches'], array_column($data, 'slug'));
        self::assertSame([13, 5, 6], array_column($data, 'productCount'));
        self::assertSame(
            ['t-shirts' => 6, 'hoodies' => 3, 'pantalons' => 4],
            array_column($data[0]['subCategories'], 'productCount', 'slug'),
        );
        self::assertSame(
            ['id', 'slug', 'name', 'description', 'image', 'coverImage', 'fallbackImage', 'productCount', 'subCategories'],
            array_keys($data[0]),
        );

        // Un article retiré n'est plus compté.
        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setIsActive(false));
        $data = $this->getJson('/api/categories');
        self::assertSame(12, $data[0]['productCount']);
        self::assertSame(2, array_column($data[0]['subCategories'], 'productCount', 'slug')['hoodies']);
    }

    public function testSearchSuggestions(): void
    {
        $data = $this->getJson('/api/search/suggest?q=babouche');

        self::assertSame('babouche', $data['query']);
        self::assertSame([['slug' => 'babouches', 'name' => 'Babouches', 'productCount' => 6]], $data['categories']);
        self::assertSame(6, $data['totalProducts']);
        self::assertLessThanOrEqual(6, \count($data['products']));
        self::assertSame(
            ['id', 'slug', 'name', 'price', 'image', 'fallbackImage', 'categorySlug', 'totalStock', 'city'],
            array_keys($data['products'][0]),
        );

        // Sous-catégorie : avec sa catégorie parente.
        $data = $this->getJson('/api/search/suggest?q=hood');
        self::assertSame('vetements', $data['categories'][0]['parentSlug']);

        // Filtre de ville.
        $data = $this->getJson('/api/search/suggest?q=babouche&city=Douala');
        self::assertSame(['Douala'], array_values(array_unique(array_column($data['products'], 'city'))));

        // Moins de 2 caractères : listes vides.
        self::assertSame(
            ['query' => ' a ', 'categories' => [], 'products' => [], 'totalProducts' => 0],
            $this->getJson('/api/search/suggest?q=%20a%20'),
        );
    }

    public function testLooks(): void
    {
        $data = $this->getJson('/api/looks');
        self::assertSame(['look-1', 'look-2', 'look-3', 'look-4'], array_column($data, 'id'));
        self::assertSame(['hoodie-barbele-noir', 'cargo-tactique-bitume'], $data[0]['productSlugs']);

        $data = $this->getJson('/api/looks/look-2');
        self::assertSame('Héritage Akwa', $data['title']);
        self::assertSame(['tshirt-lincompris-oversize', 'babouche-royale-akwa-cuir'], array_column($data['products'], 'slug'));

        // Article retiré : ignoré dans le look.
        $this->mutateProduct('tshirt-lincompris-oversize', fn (Product $p) => $p->setIsActive(false));
        self::assertSame(['babouche-royale-akwa-cuir'], $this->getJson('/api/looks/look-2')['productSlugs']);
        self::assertSame(['babouche-royale-akwa-cuir'], $this->getJson('/api/looks')[1]['productSlugs']);

        $error = $this->getJson('/api/looks/look-99');
        self::assertSame(404, $this->httpStatus());
        self::assertSame(['code' => 'NOT_FOUND', 'message' => 'Ce look est introuvable.'], $error);
    }

    public function testPublicSettings(): void
    {
        $data = $this->getJson('/api/settings/public');

        self::assertSame(200, $this->httpStatus());
        self::assertSame('Yaoundé', $data['shop']['city']);
        self::assertSame('Yaoundé', $data['pickupAddress']['city']);
        self::assertSame('upfront', $data['deliveryFeePayment']);

        $operators = array_column($data['payment']['operators'], null, 'id');
        self::assertSame('#150*14*{code}*640073363*{amount}#', $operators['orange']['ussdTemplate']);
        self::assertSame('909935', $operators['orange']['merchantCode']);
        self::assertSame('*126*16*{code}*{amount}#', $operators['mtn']['ussdTemplate']);
        self::assertSame('00267749', $operators['mtn']['merchantCode']);
        self::assertTrue($data['payment']['exactAmountRequired']);
        self::assertSame(10, $data['payment']['proofMaxSizeMb']);
        self::assertSame(['orange', 'mtn'], array_column($data['operators'], 'code'));
    }

    public function testPublicSettingsNeverFailEvenWhenNotSavedYet(): void
    {
        $expected = $this->getJson('/api/settings/public');

        $this->em()->remove($this->em()->find(AppSetting::class, AppSetting::PUBLIC));
        $this->em()->flush();
        $this->em()->clear();

        self::assertSame($expected, $this->getJson('/api/settings/public'));
        self::assertSame(200, $this->httpStatus());
    }

    public function testDeliveryCitiesAndAgencies(): void
    {
        $cities = $this->getJson('/api/delivery/cities');
        self::assertCount(15, $cities);
        self::assertSame(
            ['id' => 'city-yde', 'name' => 'Yaoundé', 'fee' => 1500, 'isLocal' => true, 'hasAgencies' => false, 'estimatedDelay' => 'Aujourd’hui ou demain'],
            $cities[0],
        );

        // Le front envoie le nom ; sans tenir compte des accents ni de la casse ; l'identifiant marche aussi.
        foreach (['Douala', 'douala', 'city-dla'] as $value) {
            $agencies = $this->getJson('/api/delivery/agencies?city=' . rawurlencode($value));
            self::assertSame(['ag-dla-travel-lines', 'ag-dla-united-express', 'ag-dla-touristique'], array_column($agencies, 'id'), $value);
        }
        self::assertSame(['id', 'cityId', 'city', 'name', 'address', 'phone', 'openingHours', 'fee', 'departureCity'], array_keys($agencies[0]));
        self::assertSame(['Travel Lines Cameroun', '+237 640 02 02 02', 1000, null], [$agencies[0]['name'], $agencies[0]['phone'], $agencies[0]['fee'], $agencies[0]['departureCity']], 'prix publié par l’agence');
        self::assertCount(3, $this->getJson('/api/delivery/agencies?city=yaounde'));
        self::assertSame(['ag-baf-touristique'], array_column($this->getJson('/api/delivery/agencies?city=Bafoussam'), 'id'), 'agences sans prix confirmé : cachées');

        self::assertSame([], $this->getJson('/api/delivery/agencies?city=Paris'));
        self::assertSame(200, $this->httpStatus());
    }

    public function testContentPages(): void
    {
        $data = $this->getJson('/api/pages/mentions-legales');

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['slug', 'title', 'updatedAt', 'body'], array_keys($data));
        self::assertSame('Mentions légales', $data['title']);
        self::assertSame('2026-10-31T08:00:00.000Z', $data['updatedAt']);
        self::assertStringContainsString('max-age=300', (string) $this->header('Cache-Control'));

        foreach (['politique-cookies', 'Majuscules', str_repeat('a', 81)] as $slug) {
            $error = $this->getJson('/api/pages/' . $slug);
            self::assertSame(404, $this->httpStatus(), $slug);
            self::assertSame('PAGE_NOT_FOUND', $error['code']);
            self::assertSame('Cette page est introuvable.', $error['message']);
        }
        self::assertSame(['slug' => 'politique-cookies'], $this->getJson('/api/pages/politique-cookies')['details']);

        // Page dépubliée : introuvable.
        $this->em()->find(ContentPage::class, 'mentions-legales')->setIsPublished(false);
        $this->em()->flush();
        $this->em()->clear();
        $this->getJson('/api/pages/mentions-legales');
        self::assertSame(404, $this->httpStatus());
    }
}
