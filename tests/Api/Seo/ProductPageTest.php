<?php

namespace App\Tests\Api\Seo;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Seo\FrontIndex;
use App\Tests\CatalogTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fiche produit avec ses balises de partage (contrat §2.12) : la page de l'app (tests/Fixtures/front/index.html),
 * lue par WhatsApp, Facebook et les moteurs de recherche.
 */
final class ProductPageTest extends CatalogTestCase
{
    private const SLUG = 'hoodie-barbele-noir';
    private const CLOUDINARY = 'https://res.cloudinary.com/compte/image/upload/';

    public function testProductTagsReplaceTheGeneralOnes(): void
    {
        $this->mutateProduct(self::SLUG, fn (Product $p) => $p->setMediaGallery([
            ['url' => self::CLOUDINARY . 'v17/drchoco/products/hoodie.webp', 'fallbackUrl' => '/mock/product-hoodie.svg'],
        ]));

        $html = $this->page('/produit/' . self::SLUG);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('text/html; charset=UTF-8', $this->header('Content-Type'));
        self::assertStringContainsString('no-cache', (string) $this->header('Cache-Control'));

        $head = $this->head($html);
        $title = "Hoodie Barbelé Noir — 45\u{202F}000\u{00A0}FCFA";
        self::assertSame([$title . ' | DR Choco L’Incompris'], $head['titles']);
        self::assertSame(['https://drchoco.com/produit/' . self::SLUG], $head['canonical']);
        self::assertSame(
            [
                'og:site_name'           => ['DR Choco L’Incompris'],
                'og:locale'              => ['fr_FR'],
                'og:type'                => ['product'],
                'og:title'               => [$title],
                'og:description'         => $head['meta']['description'],
                'og:url'                 => ['https://drchoco.com/produit/' . self::SLUG],
                'og:image'               => [self::CLOUDINARY . 'c_pad,b_auto,w_1200,h_630,f_jpg,q_auto/v17/drchoco/products/hoodie.webp'],
                'og:image:alt'           => ['Hoodie Barbelé Noir'],
                'og:image:width'         => ['1200'],
                'og:image:height'        => ['630'],
                'product:price:amount'   => ['45000'],
                'product:price:currency' => ['XAF'],
                'product:availability'   => ['in stock'],
                'twitter:card'           => ['summary_large_image'],
            ],
            array_filter($head['meta'], fn (string $name) => preg_match('/^(og|product|twitter):/', $name) === 1, \ARRAY_FILTER_USE_KEY),
        );

        $json = json_decode($head['jsonLd']['product-jsonld-schema'], true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame('Product', $json['@type']);
        self::assertSame('prod-01', $json['sku']);
        self::assertSame([self::CLOUDINARY . 'v17/drchoco/products/hoodie.webp'], $json['image']);
        self::assertSame('45000', $json['offers']['price']);
        self::assertSame('https://schema.org/InStock', $json['offers']['availability']);

        // Le reste de la page est intact : l'app démarre normalement, l'encodage reste au début.
        self::assertStringContainsString('<script type="module" crossorigin src="/assets/index-abcd1234.js"></script>', $html);
        self::assertStringContainsString('<div id="root"></div>', $html);
        self::assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $html);
        self::assertStringContainsString('<meta name="format-detection" content="telephone=no" />', $html);
        self::assertLessThan(200, strpos($html, 'charset="UTF-8"'));
        self::assertLessThan(strpos($html, 'apple-touch-startup-image'), strpos($html, 'og:title'));
    }

    public function testDescriptionIsShortAndOnOneLine(): void
    {
        $this->mutateProduct(self::SLUG, fn (Product $p) => $p->setDescription(str_repeat("Coton épais,\n  coupe large ; ", 20)));
        $description = $this->head($this->page('/produit/' . self::SLUG))['meta']['description'][0];
        self::assertLessThanOrEqual(160, mb_strlen($description));
        self::assertStringEndsWith('…', $description);
        self::assertStringNotContainsString("\n", $description);

        // Sans description : celle de la boutique.
        $this->mutateProduct(self::SLUG, fn (Product $p) => $p->setDescription('  '));
        self::assertStringStartsWith('Vêtements, chaussures et babouches', $this->head($this->page('/produit/' . self::SLUG))['meta']['description'][0]);
    }

    public function testHostileTextCannotLeaveItsTag(): void
    {
        $evil = '</title><script>alert(1)</script><!-- "\'> $1 \\0 &amp;';
        $this->mutateProduct(self::SLUG, fn (Product $p) => $p->setName($evil)->setDescription('</script><script>alert(2)</script> ' . $evil));

        $html = $this->page('/produit/' . self::SLUG);

        self::assertSame(200, $this->httpStatus());
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertSame(1, substr_count($html, '</title>'));
        // Deux scripts seulement : l'app et les données structurées.
        self::assertSame(2, substr_count($html, '<script'));
        self::assertSame(2, substr_count($html, '</script>'));
        $head = $this->head($html);
        self::assertStringStartsWith($evil . ' — ', $head['titles'][0]);
        self::assertStringStartsWith($evil . ' — ', $head['meta']['og:title'][0]);
        $json = json_decode($head['jsonLd']['product-jsonld-schema'], true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame($evil, $json['name']);
        self::assertSame('</script><script>alert(2)</script> ' . $evil, $json['description']);
    }

    public function testShareImageIsAlwaysARealPicture(): void
    {
        $image = function (array $gallery): string {
            $this->mutateProduct(self::SLUG, fn (Product $p) => $p->setMediaGallery($gallery));

            return $this->head($this->page('/produit/' . self::SLUG))['meta']['og:image'][0];
        };

        // Photo absente du site, SVG, galerie vide : image de la marque.
        self::assertSame('https://drchoco.com/brand/partage.jpg', $image([['url' => '/images/products/absente.webp', 'fallbackUrl' => '/mock/product-tee.svg']]));
        self::assertSame('https://drchoco.com/brand/partage.jpg', $image([['url' => 'https://exemple.com/dessin.svg?v=2', 'fallbackUrl' => '']]));
        self::assertSame('https://drchoco.com/brand/partage.jpg', $image([]));
        // Jamais un chemin hors du site.
        self::assertSame('https://drchoco.com/brand/partage.jpg', $image([['url' => '/images/../../../composer.json', 'fallbackUrl' => '']]));
        // Photo livrée avec le front : adresse absolue ; la première vraie photo est prise.
        self::assertSame('https://drchoco.com/images/products/photo-test.webp', $image([
            ['url' => '/images/products/absente.webp', 'fallbackUrl' => ''],
            ['url' => '/images/products/photo-test.webp', 'fallbackUrl' => ''],
        ]));
        // Adresse Cloudinary déjà transformée, ou autre hébergement : gardée telle quelle.
        self::assertSame(self::CLOUDINARY . 'f_auto,w_600/x.jpg', $image([['url' => self::CLOUDINARY . 'f_auto,w_600/x.jpg', 'fallbackUrl' => '']]));
        self::assertSame('https://exemple.com/photo.jpg', $image([['url' => 'https://exemple.com/photo.jpg', 'fallbackUrl' => '']]));
    }

    public function testSoldOutProductStaysVisible(): void
    {
        $this->mutateProduct(self::SLUG, function (Product $p): void {
            foreach ($p->getVariants() as $variant) {
                /** @var ProductVariant $variant */
                $variant->setStock(0);
            }
        });

        $head = $this->head($this->page('/produit/' . self::SLUG));
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['out of stock'], $head['meta']['product:availability']);
        self::assertStringContainsString('schema.org/OutOfStock', $head['jsonLd']['product-jsonld-schema']);
    }

    public function testUnknownOrWithdrawnProductIsThePlainPageWithStatus404(): void
    {
        $plain = (string) file_get_contents(\dirname(__DIR__, 2) . '/Fixtures/front/index.html');

        foreach (['/produit/inconnu', '/produit/%3Cscript%3E', '/produit/' . str_repeat('a', 300), '/produit/a%00b', '/produit/..%5c..'] as $uri) {
            self::assertSame($plain, $this->page($uri), $uri);
            self::assertSame(404, $this->httpStatus(), $uri);
            // (Hors production, Symfony pose « noindex » sur toutes les réponses : la vérification de la fiche indexable
            // est faite dans le test de bout en bout, en mode production.)
            self::assertSame('noindex', $this->header('X-Robots-Tag'));
            self::assertStringContainsString('no-cache', (string) $this->header('Cache-Control'));
        }

        // Retiré de la vente, puis catégorie masquée.
        $this->mutateProduct(self::SLUG, fn (Product $p) => $p->setIsActive(false));
        self::assertSame($plain, $this->page('/produit/' . self::SLUG));
        self::assertSame(404, $this->httpStatus());
        $this->mutateProduct(self::SLUG, function (Product $p): void {
            $p->setIsActive(true);
            $p->getCategory()->setIsActive(false);
        });
        self::assertSame($plain, $this->page('/produit/' . self::SLUG));
        self::assertSame(404, $this->httpStatus());
    }

    public function testAddressVariantsLeadToTheCanonicalOne(): void
    {
        // Majuscules, paramètres de taille et de couleur : même fiche, même adresse canonique.
        $head = $this->head($this->page('/produit/Hoodie-Barbele-NOIR?taille=L&couleur=noir'));
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['https://drchoco.com/produit/' . self::SLUG], $head['canonical']);

        // « / » final : redirection vers l'adresse sans « / ».
        $this->client->request('GET', '/produit/' . self::SLUG . '/');
        self::assertSame(301, $this->httpStatus());
        self::assertStringEndsWith('/produit/' . self::SLUG, (string) $this->header('Location'));
    }

    public function testHostHeaderNeverChangesTheAddresses(): void
    {
        $html = $this->page('/produit/' . self::SLUG, ['HTTP_HOST' => 'pirate.example', 'HTTP_X_FORWARDED_HOST' => 'pirate.example']);

        self::assertSame(200, $this->httpStatus());
        self::assertStringNotContainsString('pirate.example', $html);
        self::assertSame(['https://drchoco.com/produit/' . self::SLUG], $this->head($html)['canonical']);
    }

    public function testHeadAndOtherMethods(): void
    {
        $this->client->request('HEAD', '/produit/' . self::SLUG);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/produit/' . self::SLUG);
        self::assertSame(405, $this->httpStatus());
    }

    public function testClientTokenIsIgnored(): void
    {
        // Pas de pare-feu hors de /api : un jeton périmé ou illisible n'empêche pas d'ouvrir une fiche.
        $this->page('/produit/' . self::SLUG, ['HTTP_AUTHORIZATION' => 'Bearer illisible']);
        self::assertSame(200, $this->httpStatus());
    }

    public function testFrontIndexIsFoundAtTheSiteRootWhenNothingIsConfigured(): void
    {
        $root = \dirname(__DIR__, 2) . '/Fixtures/front';
        $front = new FrontIndex('');

        $atRoot = new Request(server: ['DOCUMENT_ROOT' => $root . '/']);
        self::assertSame($root . '/index.html', $front->path($atRoot));
        self::assertNotNull($front->html($atRoot));
        self::assertNull($front->path(new Request(server: ['DOCUMENT_ROOT' => $root . '/images'])));
        self::assertNull($front->path());
        self::assertNull((new FrontIndex($root . '/absent.html'))->html($atRoot));
        // Un fichier qui n'est pas une page HTML n'est jamais servi.
        self::assertNull((new FrontIndex($root . '/images/products/photo-test.webp'))->html($atRoot));
    }

    /** @param array<string, string> $server */
    private function page(string $uri, array $server = []): string
    {
        $this->client->request('GET', $uri, [], [], $server);

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * Lit la page comme un robot : titres, balises meta, adresse canonique et données structurées.
     *
     * @return array{titles: list<string>, meta: array<string, list<string>>, canonical: list<string>, jsonLd: array<string, string>}
     */
    private function head(string $html): array
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML('<?xml encoding="UTF-8">' . $html, \LIBXML_NOERROR | \LIBXML_NOWARNING));
        $result = ['titles' => [], 'meta' => [], 'canonical' => [], 'jsonLd' => []];
        foreach ($document->getElementsByTagName('title') as $node) {
            $result['titles'][] = $node->textContent;
        }
        foreach ($document->getElementsByTagName('meta') as $node) {
            $name = $node->getAttribute('name') ?: $node->getAttribute('property');
            if ($name !== '') {
                $result['meta'][$name][] = $node->getAttribute('content');
            }
        }
        foreach ($document->getElementsByTagName('link') as $node) {
            if ($node->getAttribute('rel') === 'canonical') {
                $result['canonical'][] = $node->getAttribute('href');
            }
        }
        foreach ($document->getElementsByTagName('script') as $node) {
            if ($node->getAttribute('type') === 'application/ld+json') {
                $result['jsonLd'][$node->getAttribute('id')] = $node->textContent;
            }
        }

        return $result;
    }
}
