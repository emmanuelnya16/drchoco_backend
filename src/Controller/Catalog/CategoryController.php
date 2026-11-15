<?php

namespace App\Controller\Catalog;

use App\Api\ApiJson;
use App\Catalog\CatalogNormalizer;
use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/categories — catégories et sous-catégories, dans l'ordre d'affichage (contrat §6.1).
 */
final class CategoryController
{
    #[Route('/categories', name: 'api_categories', methods: ['GET'])]
    public function __invoke(
        Request $request,
        CategoryRepository $categories,
        ProductRepository $products,
        CatalogNormalizer $normalizer,
    ): JsonResponse {
        $counts = $products->countVisibleByCategory();

        return ApiJson::publicCached($request, array_map(
            fn (Category $c) => $normalizer->category($c, $counts['categories'], $counts['subCategories']),
            $categories->findVisible(),
        ));
    }
}
