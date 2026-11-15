<?php

namespace App\Controller\Catalog;

use App\Api\ApiException;
use App\Api\ApiJson;
use App\Catalog\CatalogNormalizer;
use App\Entity\ContentPage;
use App\Repository\ContentPageRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/pages/{slug} — page légale, d'aide ou questions fréquentes, en Markdown (contrat §12.1).
 */
final class PageController
{
    #[Route('/pages/{slug}', name: 'api_page', methods: ['GET'], requirements: ['slug' => '[^/]{1,200}'])]
    public function __invoke(Request $request, string $slug, ContentPageRepository $pages, CatalogNormalizer $normalizer): JsonResponse
    {
        $page = preg_match(ContentPage::SLUG_PATTERN, $slug) === 1 ? $pages->find($slug) : null;

        if ($page === null || !$page->isPublished()) {
            throw new ApiException(404, 'PAGE_NOT_FOUND', 'Cette page est introuvable.', ['slug' => mb_substr($slug, 0, 80)]);
        }

        return ApiJson::publicCached($request, $normalizer->page($page), 300, null);
    }
}
