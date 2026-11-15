<?php

namespace App\Controller\Catalog;

use App\Api\ApiException;
use App\Api\ApiJson;
use App\Catalog\CatalogNormalizer;
use App\Entity\Look;
use App\Entity\Product;
use App\Repository\LookRepository;
use App\Repository\ProductRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Looks de l'accueil (contrat §6.5 et §6.6). Les articles retirés de la vente sont ignorés.
 */
final class LookController
{
    public function __construct(
        private readonly LookRepository $looks,
        private readonly ProductRepository $products,
        private readonly CatalogNormalizer $normalizer,
    ) {}

    #[Route('/looks', name: 'api_looks', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $looks = $this->looks->findVisible();
        $slugs = [];
        foreach ($looks as $look) {
            array_push($slugs, ...$look->getProductSlugs());
        }
        $visible = $this->products->visibleSlugSet($slugs);

        return ApiJson::publicCached($request, array_map(fn (Look $l) => $this->normalizer->look($l, $visible), $looks));
    }

    #[Route('/looks/{id}', name: 'api_look', methods: ['GET'], requirements: ['id' => '[^/]{1,40}'])]
    public function show(Request $request, string $id): JsonResponse
    {
        $look = $this->looks->findVisibleById($id);
        if ($look === null) {
            throw ApiException::notFound('NOT_FOUND', 'Ce look est introuvable.');
        }

        $products = $this->products->findVisibleBySlugs($look->getProductSlugs());
        $visible = array_fill_keys(array_map(fn (Product $p) => $p->getSlug(), $products), true);

        return ApiJson::publicCached($request, $this->normalizer->look($look, $visible) + [
            'products' => array_map(fn (Product $p) => $this->normalizer->product($p), $products),
        ]);
    }
}
