<?php

namespace App\Controller\Catalog;

use App\Api\ApiException;
use App\Api\ApiJson;
use App\Catalog\CatalogNormalizer;
use App\Catalog\ProductFacets;
use App\Catalog\ProductFilters;
use App\Entity\Product;
use App\Repository\LookRepository;
use App\Repository\ProductRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Articles du catalogue public (contrat §6.2 et §6.3).
 */
final class ProductController
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly CatalogNormalizer $normalizer,
    ) {}

    /**
     * GET /api/products — liste filtrée (ville, catégorie, tailles, prix, stock, look, recherche),
     * triée et paginée, avec les compteurs des filtres.
     */
    #[Route('/products', name: 'api_products', methods: ['GET'])]
    public function list(Request $request, ProductFacets $facets, LookRepository $looks): JsonResponse
    {
        $filters = ProductFilters::fromRequest($request);

        // Look inconnu : filtre ignoré (comme les mocks).
        $lookSlugs = $filters->look !== null ? $looks->findVisibleById($filters->look)?->getProductSlugs() : null;

        $result = $this->products->search($filters, $lookSlugs);
        $total = $result['total'];

        return ApiJson::publicCached($request, [
            'items'      => array_map(fn (Product $p) => $this->normalizer->product($p), $result['items']),
            'page'       => $filters->page,
            'pageSize'   => $filters->pageSize,
            'total'      => $total,
            'totalPages' => max(1, (int) ceil($total / $filters->pageSize)),
            'facets'     => $facets->compute($filters),
        ]);
    }

    /**
     * GET /api/products/{slug} — fiche complète et articles associés (6 au plus).
     * Article inconnu ou retiré de la vente : 404 NOT_FOUND (le front reconnaît ce code).
     */
    #[Route('/products/{slug}', name: 'api_product', methods: ['GET'], requirements: ['slug' => '[^/]{1,160}'])]
    public function show(Request $request, string $slug): JsonResponse
    {
        $product = $this->products->findVisibleBySlug($slug);
        if ($product === null) {
            throw ApiException::notFound('NOT_FOUND', 'Cet article n’existe plus.');
        }

        return ApiJson::publicCached($request, $this->normalizer->product($product) + [
            'related' => array_map(
                fn (Product $p) => $this->normalizer->product($p),
                $this->products->findRelated($product),
            ),
        ]);
    }
}
