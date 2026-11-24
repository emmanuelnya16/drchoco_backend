<?php

namespace App\Tests\Api\Seo;

use App\Entity\Product;
use App\Seo\Sitemap;
use App\Seo\SiteUrl;
use App\Tests\CatalogTestCase;

/**
 * Plans du site (contrat §2.13) : index, catégories et fiches produit.
 */
final class SitemapTest extends CatalogTestCase
{
    public function testIndexListsTheThreeSitemaps(): void
    {
        $xml = $this->xml('/sitemap-index.xml');

        self::assertSame('application/xml; charset=UTF-8', $this->header('Content-Type'));
        self::assertStringContainsString('max-age=3600', (string) $this->header('Cache-Control'));
        self::assertStringContainsString('public', (string) $this->header('Cache-Control'));
        self::assertSame(
            ['https://drchoco.com/sitemap.xml', 'https://drchoco.com/sitemap-categories.xml', 'https://drchoco.com/sitemap-produits.xml'],
            $this->locations($xml),
        );
        self::assertSame('sitemapindex', $xml->documentElement?->localName);
    }

    public function testProductsAreListedWithDateAndPhoto(): void
    {
        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setMediaGallery([
            ['url' => 'https://res.cloudinary.com/compte/image/upload/v1/hoodie.webp?a=1&b=2', 'fallbackUrl' => ''],
        ]));
        // Article épuisé : il reste dans le plan. Article retiré : il n'y est plus.
        $this->mutateProduct('tshirt-lincompris-oversize', function (Product $p): void {
            foreach ($p->getVariants() as $variant) {
                $variant->setStock(0);
            }
        });
        $total = \count($this->locations($this->xml('/sitemap-produits.xml')));
        self::assertSame(24, $total);

        $xml = $this->xml('/sitemap-produits.xml');
        $locations = $this->locations($xml);
        self::assertContains('https://drchoco.com/produit/hoodie-barbele-noir', $locations);
        self::assertContains('https://drchoco.com/produit/tshirt-lincompris-oversize', $locations);
        self::assertSame(\count($locations), \count(array_unique($locations)));
        self::assertSame(['https://res.cloudinary.com/compte/image/upload/v1/hoodie.webp?a=1&b=2'], $this->texts($xml, 'loc', 'http://www.google.com/schemas/sitemap-image/1.1'));
        $dates = $this->texts($xml, 'lastmod');
        self::assertCount($total, $dates);
        foreach ($dates as $date) {
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $date);
        }

        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setIsActive(false));
        $locations = $this->locations($this->xml('/sitemap-produits.xml'));
        self::assertCount(23, $locations);
        self::assertNotContains('https://drchoco.com/produit/hoodie-barbele-noir', $locations);
    }

    public function testCategoriesWithVisibleProductsOnly(): void
    {
        $expected = array_map(fn (string $slug) => 'https://drchoco.com/explorer/' . $slug, [
            'vetements', 'chaussures', 'babouches',
            't-shirts', 'sneakers', 'cuir-lisse', 'hoodies', 'derbies', 'croco-embosse', 'pantalons',
        ]);
        self::assertEqualsCanonicalizing($expected, $this->locations($this->xml('/sitemap-categories.xml')));

        // Sous-catégorie sans article visible, puis catégorie masquée : retirées du plan.
        $em = $this->em();
        foreach ($em->getRepository(Product::class)->findAll() as $product) {
            if ($product->getSubCategory()?->getSlug() === 'derbies') {
                $product->setIsActive(false);
            }
            if ($product->getCategory()->getSlug() === 'babouches') {
                $product->getCategory()->setIsActive(false);
            }
        }
        $em->flush();
        $em->clear();

        $locations = $this->locations($this->xml('/sitemap-categories.xml'));
        self::assertContains('https://drchoco.com/explorer/chaussures', $locations);
        foreach (['derbies', 'babouches', 'cuir-lisse', 'croco-embosse'] as $slug) {
            self::assertNotContains('https://drchoco.com/explorer/' . $slug, $locations);
        }
    }

    public function testUnchangedSitemapIsNotSentAgain(): void
    {
        $this->xml('/sitemap-produits.xml');
        $etag = $this->header('ETag');
        self::assertNotNull($etag);

        $this->client->request('GET', '/sitemap-produits.xml', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertSame(304, $this->httpStatus());
        self::assertSame('', (string) $this->client->getResponse()->getContent());
    }

    public function testEmptyCatalogueStillGivesValidSitemaps(): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE products SET is_active = 0');

        self::assertSame([], $this->locations($this->xml('/sitemap-produits.xml')));
        self::assertSame([], $this->locations($this->xml('/sitemap-categories.xml')));
        self::assertCount(3, $this->locations($this->xml('/sitemap-index.xml')));
    }

    public function testHostHeaderNeverChangesTheAddresses(): void
    {
        $this->client->request('GET', '/sitemap-index.xml', [], [], ['HTTP_HOST' => 'pirate.example']);
        self::assertSame(200, $this->httpStatus());
        self::assertStringNotContainsString('pirate.example', (string) $this->client->getResponse()->getContent());
    }

    public function testTextIsEscaped(): void
    {
        $xml = Sitemap::urls([['loc' => 'https://drchoco.com/?a=1&b=<x>', 'lastmod' => null, 'image' => 'https://x/"\'&']]);
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        self::assertSame(['https://drchoco.com/?a=1&b=<x>'], $this->locations($document));
    }

    public function testSiteUrlIsCheckedAndCleaned(): void
    {
        self::assertSame('https://drchoco.com/produit/x', (new SiteUrl(' https://DrChoco.com/boutique/ '))->to('/produit/x'));
        self::assertSame('http://127.0.0.1:8090/x', (new SiteUrl('http://127.0.0.1:8090'))->to('x'));

        foreach (['', 'drchoco.com', 'ftp://drchoco.com', 'https://'] as $invalid) {
            try {
                new SiteUrl($invalid);
                self::fail('Adresse acceptée : ' . $invalid);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('SITE_URL', $e->getMessage());
            }
        }
    }

    private function xml(string $uri): \DOMDocument
    {
        $this->client->request('GET', $uri);
        self::assertSame(200, $this->httpStatus(), $uri);
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML((string) $this->client->getResponse()->getContent()));

        return $document;
    }

    /** @return list<string> */
    private function locations(\DOMDocument $xml): array
    {
        return $this->texts($xml, 'loc');
    }

    /** @return list<string> */
    private function texts(\DOMDocument $xml, string $tag, string $namespace = 'http://www.sitemaps.org/schemas/sitemap/0.9'): array
    {
        $texts = [];
        foreach ($xml->getElementsByTagNameNS($namespace, $tag) as $node) {
            $texts[] = $node->textContent;
        }

        return $texts;
    }
}
