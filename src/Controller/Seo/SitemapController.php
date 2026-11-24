<?php

namespace App\Controller\Seo;

use App\Repository\ProductRepository;
use App\Seo\ShareImage;
use App\Seo\Sitemap;
use App\Seo\SiteUrl;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Plans du site (contrat §2.13), à la racine du domaine, déclarés dans robots.txt par /sitemap-index.xml :
 *  - /sitemap.xml : pages fixes (accueil, explorer, aide, pages légales), livré avec le front ;
 *  - /sitemap-categories.xml : catégories et sous-catégories qui ont des articles ;
 *  - /sitemap-produits.xml : fiches des articles visibles (épuisés compris, retirés exclus), avec leur photo.
 */
#[AsController]
final class SitemapController
{
    private const MAX_AGE = 3600;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly SiteUrl $site,
        private readonly ShareImage $images,
    ) {}

    public function index(Request $request): Response
    {
        $products = $this->products->sitemapProducts(Sitemap::MAX_URLS);
        $latest = $products !== [] ? max(array_column($products, 'lastmod')) : null;

        return $this->xml($request, Sitemap::index([
            ['loc' => $this->site->to('/sitemap.xml'), 'lastmod' => null],
            ['loc' => $this->site->to('/sitemap-categories.xml'), 'lastmod' => $latest],
            ['loc' => $this->site->to('/sitemap-produits.xml'), 'lastmod' => $latest],
        ]));
    }

    public function categories(Request $request): Response
    {
        return $this->xml($request, Sitemap::urls(array_map(
            fn (array $row): array => ['loc' => $this->site->to('/explorer/' . $row['slug']), 'lastmod' => $row['lastmod']],
            $this->products->sitemapCategories(),
        )));
    }

    public function products(Request $request): Response
    {
        return $this->xml($request, Sitemap::urls(array_map(
            fn (array $row): array => [
                'loc'     => $this->site->to('/produit/' . $row['slug']),
                'lastmod' => $row['lastmod'],
                'image'   => $this->images->firstPhoto($row['gallery'], $request),
            ],
            $this->products->sitemapProducts(Sitemap::MAX_URLS),
        )));
    }

    private function xml(Request $request, string $xml): Response
    {
        $response = new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8']);
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);
        $response->setEtag(hash('xxh128', $xml), true);
        $response->isNotModified($request);

        return $response;
    }
}
