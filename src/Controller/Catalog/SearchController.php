<?php

namespace App\Controller\Catalog;

use App\Api\ApiJson;
use App\Catalog\CatalogNormalizer;
use App\Catalog\ProductFilters;
use App\Catalog\TextFolder;
use App\Entity\Product;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/search/suggest?q=…&city=… — suggestions pendant la saisie (contrat §6.4) :
 * 4 catégories et 6 articles au plus, et le nombre total d'articles trouvés.
 * La recherche est la même que GET /products?q= : « Voir les N résultats » tombe juste.
 */
final class SearchController
{
    private const MAX_CATEGORIES = 4;
    private const MAX_PRODUCTS = 6;

    #[Route('/search/suggest', name: 'api_search_suggest', methods: ['GET'])]
    public function __invoke(
        Request $request,
        CategoryRepository $categories,
        ProductRepository $products,
        CatalogNormalizer $normalizer,
    ): JsonResponse {
        $raw = mb_substr((string) $request->query->get('q', ''), 0, 120);
        $needle = TextFolder::fold($raw);

        if (mb_strlen($needle) < 2) {
            return ApiJson::publicCached($request, ['query' => $raw, 'categories' => [], 'products' => [], 'totalProducts' => 0]);
        }

        $counts = $products->countVisibleByCategory();
        $matches = [];
        foreach ($categories->findVisible() as $category) {
            if (TextFolder::contains($category->getName(), $needle) || TextFolder::contains($category->getSlug(), $needle)) {
                $matches[] = [
                    'slug'         => $category->getSlug(),
                    'name'         => $category->getName(),
                    'productCount' => $counts['categories'][$category->getId()] ?? 0,
                ];
            }
            foreach ($category->getSubCategories() as $sub) {
                if (TextFolder::contains($sub->getName(), $needle) || TextFolder::contains($sub->getSlug(), $needle)) {
                    $matches[] = [
                        'slug'         => $sub->getSlug(),
                        'name'         => $sub->getName(),
                        'parentSlug'   => $category->getSlug(),
                        'productCount' => $counts['subCategories'][$sub->getId()] ?? 0,
                    ];
                }
            }
        }

        $filters = new ProductFilters();
        $filters->words = ProductFilters::words($raw);
        [$filters->city, $filters->unknownCity] = ProductFilters::parseCity((string) $request->query->get('city', ''));
        $filters->pageSize = self::MAX_PRODUCTS;
        $result = $products->search($filters);

        return ApiJson::publicCached($request, [
            'query'         => $raw,
            'categories'    => \array_slice($matches, 0, self::MAX_CATEGORIES),
            'products'      => array_map(fn (Product $p) => $normalizer->suggestion($p), $result['items']),
            'totalProducts' => $result['total'],
        ]);
    }
}
