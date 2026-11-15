<?php

namespace App\Tests\Api\Catalog;

use App\Entity\Product;
use App\Tests\CatalogTestCase;

/**
 * GET /api/products et GET /api/products/{slug} (contrat §6.2, §6.3).
 */
final class ProductsTest extends CatalogTestCase
{
    public function testDefaultListIsPopularFirstAndPaginated(): void
    {
        $data = $this->getJson('/api/products');

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['items', 'page', 'pageSize', 'total', 'totalPages', 'facets'], array_keys($data));
        self::assertSame([1, 12, 24, 2], [$data['page'], $data['pageSize'], $data['total'], $data['totalPages']]);
        self::assertCount(12, $data['items']);

        $counts = array_column($data['items'], 'reservationsCount');
        $sorted = $counts;
        rsort($sorted);
        self::assertSame($sorted, $counts, 'tri « popular » : les plus réservés en premier');
        self::assertSame('hoodie-barbele-noir', $data['items'][0]['slug']);
    }

    public function testPublicCacheHeadersAndEtag(): void
    {
        $this->getJson('/api/products?category=babouches');

        $cacheControl = (string) $this->header('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=60', $cacheControl);
        self::assertStringContainsString('stale-while-revalidate=600', $cacheControl);
        $etag = (string) $this->header('ETag');
        self::assertNotSame('', $etag);

        // Même réponse : 304 sans corps, y compris avec le suffixe ajouté par la compression d'Apache.
        $this->client->request('GET', '/api/products?category=babouches', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertSame(304, $this->httpStatus());
        self::assertSame('', (string) $this->client->getResponse()->getContent());

        $gzipEtag = preg_replace('/"$/', '-gzip"', $etag);
        $this->client->request('GET', '/api/products?category=babouches', [], [], ['HTTP_IF_NONE_MATCH' => $gzipEtag]);
        self::assertSame(304, $this->httpStatus());
    }

    public function testCityFilterIgnoresAccentsAndCase(): void
    {
        foreach (['Douala', 'douala', 'DOUALA'] as $city) {
            $data = $this->getJson('/api/products?pageSize=50&city=' . rawurlencode($city));
            self::assertSame(12, $data['total'], $city);
            self::assertSame(['Douala'], array_values(array_unique(array_column($data['items'], 'city'))));
        }

        foreach (['Yaoundé', 'yaounde', 'YAOUNDE'] as $city) {
            $data = $this->getJson('/api/products?pageSize=50&city=' . rawurlencode($city));
            self::assertSame(12, $data['total'], $city);
            self::assertSame(['Yaoundé'], array_values(array_unique(array_column($data['items'], 'city'))));
        }

        self::assertSame(24, $this->getJson('/api/products?city=all')['total']);
        // Ville sans boutique : aucun article (et pas d'erreur).
        self::assertSame(0, $this->getJson('/api/products?city=Bafoussam')['total']);
        self::assertSame(200, $this->httpStatus());
    }

    public function testCityFilterAlsoScopesFacets(): void
    {
        $data = $this->getJson('/api/products?city=Douala&category=vetements');

        self::assertSame(
            ['t-shirts' => 3, 'hoodies' => 1, 'pantalons' => 2],
            array_column($data['facets']['subCategories'], 'count', 'slug'),
        );
    }

    public function testRepeatedSizeParameterKeepsOnlyProductsWithStockInThoseSizes(): void
    {
        $data = $this->getJson('/api/products?pageSize=50&size=L&size=XL');

        self::assertGreaterThan(0, $data['total']);
        foreach ($data['items'] as $item) {
            $inStock = array_filter($item['variants'], fn ($v) => \in_array($v['size'], ['L', 'XL'], true) && $v['stock'] > 0);
            self::assertNotEmpty($inStock, $item['slug']);
        }
        self::assertSame($data['total'], $this->getJson('/api/products?pageSize=50&size=L,XL')['total']);
    }

    public function testPriceRanges(): void
    {
        $data = $this->getJson('/api/products?pageSize=50&price=50000%2B');
        self::assertSame(4, $data['total']);
        foreach ($data['items'] as $item) {
            self::assertGreaterThan(50000, $item['price']);
        }

        // « + » non encodé : arrive comme une espace, même résultat.
        self::assertSame(4, $this->getJson('/api/products?price=50000+')['total']);

        $data = $this->getJson('/api/products?pageSize=50&price=15000-30000');
        foreach ($data['items'] as $item) {
            self::assertGreaterThanOrEqual(15000, $item['price']);
            self::assertLessThanOrEqual(30000, $item['price']);
        }

        $ranges = array_column($data['facets']['priceRanges'], 'count', 'value');
        self::assertSame(['0-15000' => 2, '15000-30000' => 7, '30000-50000' => 11, '50000+' => 4], $ranges);
        self::assertSame("Plus de 50\u{00A0}000\u{00A0}FCFA", $data['facets']['priceRanges'][3]['label']);
    }

    public function testSortsByPrice(): void
    {
        $asc = array_column($this->getJson('/api/products?pageSize=50&sort=price-asc')['items'], 'price');
        $sorted = $asc;
        sort($sorted);
        self::assertSame($sorted, $asc);

        // Ancien alias des liens partagés.
        $desc = array_column($this->getJson('/api/products?pageSize=50&sort=price_desc')['items'], 'price');
        self::assertSame(array_reverse($sorted), $desc);
    }

    public function testSearchIgnoresAccentsAndMatchesEveryWord(): void
    {
        // « barbele » sans accent : le Hoodie Barbelé Noir (nom) d'abord, puis les articles qui le citent.
        $data = $this->getJson('/api/products?q=barbele');
        self::assertSame(['hoodie-barbele-noir', 'jogger-lourd-incompris', 'bandana-pack-signature'], array_column($data['items'], 'slug'));

        // Plusieurs mots : tous doivent être présents ; le nom qui les contient passe en tête.
        $data = $this->getJson('/api/products?q=' . rawurlencode('HOODIE noir'));
        self::assertSame('hoodie-barbele-noir', $data['items'][0]['slug']);
        foreach ($data['items'] as $item) {
            $text = mb_strtolower($item['name'] . ' ' . $item['description']);
            self::assertStringContainsString('hoodie', $text, $item['slug']);
            self::assertStringContainsString('noir', $text, $item['slug']);
        }
        self::assertSame('hoodie-zip-armure', $this->getJson('/api/products?q=' . rawurlencode('zippé hoodie'))['items'][0]['slug']);

        // Les jokers SQL tapés par le client sont du texte, pas des jokers.
        self::assertSame(0, $this->getJson('/api/products?q=%25')['total']);
        self::assertSame(0, $this->getJson('/api/products?q=_')['total']);
    }

    public function testLookFilter(): void
    {
        $data = $this->getJson('/api/products?look=look-1');
        self::assertEqualsCanonicalizing(['hoodie-barbele-noir', 'cargo-tactique-bitume'], array_column($data['items'], 'slug'));

        // Look inconnu : filtre ignoré.
        self::assertSame(24, $this->getJson('/api/products?look=inconnu')['total']);
    }

    public function testTolerantParameters(): void
    {
        $data = $this->getJson('/api/products?page=99');
        self::assertSame(200, $this->httpStatus());
        self::assertSame([], $data['items']);
        self::assertSame(24, $data['total']);

        self::assertSame(50, $this->getJson('/api/products?pageSize=500')['pageSize']);
        self::assertSame(12, $this->getJson('/api/products?pageSize=-3&page=abc')['pageSize']);

        $data = $this->getJson('/api/products?sort=n-importe-quoi&inconnu=1&size=');
        self::assertSame(200, $this->httpStatus());
        self::assertSame(24, $data['total']);
    }

    public function testFacetSizesFollowTheCategory(): void
    {
        $sizes = array_column($this->getJson('/api/products?category=vetements')['facets']['sizes'], 'value');
        self::assertSame(['S', 'M', 'L', 'XL', 'XXL', 'Unique'], $sizes);

        $sizes = array_column($this->getJson('/api/products?category=babouches')['facets']['sizes'], 'value');
        self::assertSame(['39', '40', '41', '42', '43', '44', '45', '46'], $sizes);

        $sizes = array_column($this->getJson('/api/products')['facets']['sizes'], 'value');
        self::assertSame($sizes, array_values(array_unique($sizes)), 'aucune taille en double');
        self::assertCount(14, $sizes);
    }

    public function testBadgesAreComputedFromStock(): void
    {
        $items = array_column($this->getJson('/api/products?pageSize=50')['items'], null, 'slug');

        self::assertSame(['low-stock', 'new'], $items['hoodie-zip-armure']['badges']);
        self::assertSame(['sold-out'], $items['tshirt-archive-01']['badges']);
        self::assertSame(['new'], $items['hoodie-barbele-noir']['badges']);
        self::assertSame([], $items['tshirt-essentiel-lourd']['badges']);
    }

    public function testProductDetail(): void
    {
        $data = $this->getJson('/api/products/hoodie-barbele-noir');

        self::assertSame(200, $this->httpStatus());
        self::assertSame('prod-01', $data['id']);
        self::assertSame('vetements', $data['categorySlug']);
        self::assertSame('hoodies', $data['subCategorySlug']);
        self::assertSame('Yaoundé', $data['city']);
        self::assertSame(45000, $data['price']);
        self::assertSame('clothing', $data['sizeGuide']['type']);
        self::assertSame(array_column($data['mediaGallery'], 'url'), $data['images']);
        self::assertSame('2026-10-28T10:00:00.000Z', $data['createdAt']);
        self::assertContains(['id' => 'prod-01-noir-l', 'size' => 'L', 'colorCode' => 'noir', 'stock' => 2], $data['variants']);

        // Aucune information interne.
        foreach (['seller', 'sellerId', 'sellerName', 'isActive', 'position'] as $internal) {
            self::assertArrayNotHasKey($internal, $data);
        }

        // Associés : 6 au plus, jamais l'article lui-même, même sous-catégorie d'abord.
        self::assertCount(6, $data['related']);
        self::assertNotContains('hoodie-barbele-noir', array_column($data['related'], 'slug'));
        self::assertSame(['hoodies', 'hoodies'], array_column(\array_slice($data['related'], 0, 2), 'subCategorySlug'));
    }

    public function testUnknownProductIsNotFoundWithTheExpectedCode(): void
    {
        $data = $this->getJson('/api/products/article-inconnu');

        self::assertSame(404, $this->httpStatus());
        self::assertSame('NOT_FOUND', $data['code']);
        self::assertSame('Cet article n’existe plus.', $data['message']);
    }

    public function testRetiredProductDisappearsEverywhere(): void
    {
        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setIsActive(false));

        $this->getJson('/api/products/hoodie-barbele-noir');
        self::assertSame(404, $this->httpStatus());

        $data = $this->getJson('/api/products?pageSize=50');
        self::assertSame(23, $data['total']);
        self::assertNotContains('hoodie-barbele-noir', array_column($data['items'], 'slug'));
    }

    public function testInactiveCategoryHidesItsProducts(): void
    {
        $this->mutateProduct('babouche-royale-akwa-cuir', fn (Product $p) => $p->getCategory()->setIsActive(false));

        self::assertSame(18, $this->getJson('/api/products')['total']);
        $this->getJson('/api/products/babouche-royale-akwa-cuir');
        self::assertSame(404, $this->httpStatus());
        self::assertNotContains('babouches', array_column($this->getJson('/api/categories'), 'slug'));
    }

    public function testInvalidTokenIsIgnoredOnPublicRoutes(): void
    {
        $this->getJson('/api/products', 'jeton.invalide.xyz');

        self::assertSame(200, $this->httpStatus());
    }
}
