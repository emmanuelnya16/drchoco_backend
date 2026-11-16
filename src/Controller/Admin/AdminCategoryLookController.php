<?php

namespace App\Controller\Admin;

use App\Admin\AdminNormalizer;
use App\Admin\CategoryEditor;
use App\Admin\LookEditor;
use App\Api\ApiException;
use App\Api\ApiJson;
use App\Api\JsonBody;
use App\Entity\Category;
use App\Entity\Look;
use App\Repository\CategoryRepository;
use App\Repository\LookRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Catégories et looks de l'administration (écran « Looks & Catégories »). Chaque écriture renvoie aussi la
 * liste complète, comme l'attend le front (`{ category, categories }`, `{ look, looks }`).
 */
final class AdminCategoryLookController
{
    public function __construct(
        private readonly CategoryEditor $categoryEditor,
        private readonly LookEditor $lookEditor,
        private readonly CategoryRepository $categories,
        private readonly LookRepository $looks,
        private readonly ProductRepository $products,
        private readonly AdminNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
    ) {}

    // ── Catégories ────────────────────────────────────────────────────────

    #[Route('/admin/categories', name: 'api_admin_categories', methods: ['GET'])]
    public function categories(): JsonResponse
    {
        return ApiJson::noStore(['categories' => $this->allCategories()]);
    }

    #[Route('/admin/categories', name: 'api_admin_categories_create', methods: ['POST'])]
    public function createCategory(Request $request): JsonResponse
    {
        $category = $this->categoryEditor->create(JsonBody::object($request));

        return ApiJson::noStore($this->categoryPayload($category), 201);
    }

    #[Route('/admin/categories/{id}', name: 'api_admin_categories_update', methods: ['PATCH'])]
    public function updateCategory(string $id, Request $request): JsonResponse
    {
        $category = $this->categoryEditor->find($id)
            ?? throw ApiException::notFound('CATEGORY_NOT_FOUND', 'Cette catégorie est introuvable.');
        $category = $this->categoryEditor->update($category, JsonBody::object($request));

        return ApiJson::noStore($this->categoryPayload($category));
    }

    // ── Looks ─────────────────────────────────────────────────────────────

    #[Route('/admin/looks', name: 'api_admin_looks', methods: ['GET'])]
    public function looks(): JsonResponse
    {
        return ApiJson::noStore(['looks' => $this->allLooks()]);
    }

    #[Route('/admin/looks', name: 'api_admin_looks_create', methods: ['POST'])]
    public function createLook(Request $request): JsonResponse
    {
        $look = $this->lookEditor->create(JsonBody::object($request));

        return ApiJson::noStore(['look' => $this->normalizer->adminLook($look), 'looks' => $this->allLooks()], 201);
    }

    #[Route('/admin/looks/{id}', name: 'api_admin_looks_update', methods: ['PATCH'])]
    public function updateLook(string $id, Request $request): JsonResponse
    {
        $look = $this->lookEditor->update($this->findLook($id), JsonBody::object($request));

        return ApiJson::noStore(['look' => $this->normalizer->adminLook($look), 'looks' => $this->allLooks()]);
    }

    #[Route('/admin/looks/{id}', name: 'api_admin_looks_delete', methods: ['DELETE'])]
    public function deleteLook(string $id): Response
    {
        $this->lookEditor->delete($this->findLook($id));

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    // ── Interne ───────────────────────────────────────────────────────────

    /** @return array{category: array<string, mixed>, categories: list<array<string, mixed>>} */
    private function categoryPayload(Category $category): array
    {
        $this->em->clear(); // listes relues depuis la base (sous-catégories à jour)
        $counts = $this->products->countActiveByCategory();
        $fresh = $this->categories->find($category->getId());

        return [
            'category'   => $this->normalizer->adminCategory($fresh ?? $category, $counts),
            'categories' => $this->allCategories($counts),
        ];
    }

    /**
     * @param array{categories: array<string, int>, subCategories: array<string, int>}|null $counts
     *
     * @return list<array<string, mixed>>
     */
    private function allCategories(?array $counts = null): array
    {
        $counts ??= $this->products->countActiveByCategory();

        return array_map(
            fn (Category $c) => $this->normalizer->adminCategory($c, $counts),
            $this->categories->findBy([], ['position' => 'ASC', 'name' => 'ASC']),
        );
    }

    /** @return list<array<string, mixed>> */
    private function allLooks(): array
    {
        return array_map($this->normalizer->adminLook(...), $this->looks->findBy([], ['position' => 'ASC', 'id' => 'ASC']));
    }

    private function findLook(string $id): Look
    {
        return (\strlen($id) <= 40 ? $this->looks->find($id) : null)
            ?? throw ApiException::notFound('LOOK_NOT_FOUND', 'Ce look est introuvable.');
    }
}
