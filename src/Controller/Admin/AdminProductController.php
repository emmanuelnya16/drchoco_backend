<?php

namespace App\Controller\Admin;

use App\Admin\AdminCatalogList;
use App\Admin\AdminNormalizer;
use App\Admin\ProductEditor;
use App\Api\ApiException;
use App\Api\ApiJson;
use App\Api\JsonBody;
use App\Entity\Admin;
use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Catalogue de l'administration : articles, variantes et stocks (règles dans App\Admin\ProductEditor).
 * Un article hors du périmètre du compte (ou supprimé) répond 404, comme s'il n'existait pas.
 */
final class AdminProductController
{
    public function __construct(
        private readonly AdminCatalogList $list,
        private readonly ProductEditor $editor,
        private readonly ProductRepository $products,
        private readonly AdminNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
    ) {}

    /** GET /api/admin/products — filtres, tris, pages et résumé des stocks. */
    #[Route('/admin/products', name: 'api_admin_products', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        return ApiJson::noStore($this->list->list($request, $admin));
    }

    /** GET /api/admin/products/{id} — par identifiant ou par adresse (slug). */
    #[Route('/admin/products/{id}', name: 'api_admin_products_show', methods: ['GET'])]
    public function show(string $id, #[CurrentUser] Admin $admin): JsonResponse
    {
        return ApiJson::noStore(['product' => $this->normalizer->adminProduct($this->find($id, $admin))]);
    }

    /** POST /api/admin/products — 201 `{ product }`. */
    #[Route('/admin/products', name: 'api_admin_products_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $product = $this->editor->create(JsonBody::object($request, maxBytes: JsonBody::MAX_BYTES_EDITOR), $admin);

        return ApiJson::noStore(['product' => $this->normalizer->adminProduct($this->reload($product))], 201);
    }

    /** PATCH /api/admin/products/{id} — champs présents seulement. */
    #[Route('/admin/products/{id}', name: 'api_admin_products_update', methods: ['PATCH'])]
    public function update(string $id, Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $product = $this->editor->update($this->find($id, $admin), JsonBody::object($request, maxBytes: JsonBody::MAX_BYTES_EDITOR), $admin);

        return ApiJson::noStore(['product' => $this->normalizer->adminProduct($this->reload($product))]);
    }

    /** PATCH /api/admin/products/{id}/stock — stock rapide `{ variants: [{ variantId, stock }] }`. */
    #[Route('/admin/products/{id}/stock', name: 'api_admin_products_stock', methods: ['PATCH'])]
    public function stock(string $id, Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $product = $this->editor->updateStock($this->find($id, $admin), JsonBody::object($request, maxBytes: JsonBody::MAX_BYTES_EDITOR), $admin);

        return ApiJson::noStore(['product' => $this->normalizer->adminProduct($product)]);
    }

    /** DELETE /api/admin/products/{id} — 204 ; l'article est retiré de la vente (historique gardé). */
    #[Route('/admin/products/{id}', name: 'api_admin_products_delete', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] Admin $admin): Response
    {
        $this->editor->delete($this->find($id, $admin), $admin);

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function find(string $id, Admin $admin): Product
    {
        $product = \strlen($id) <= 160
            ? ($this->products->find($id) ?? $this->products->findOneBy(['slug' => $id]))
            : null;
        if ($product === null || !ProductEditor::canManage($admin, $product)) {
            throw ApiException::notFound('PRODUCT_NOT_FOUND', 'Cet article est introuvable ou ne fait pas partie de votre catalogue.');
        }

        return $product;
    }

    /** Variantes relues dans l'ordre d'affichage après l'enregistrement. */
    private function reload(Product $product): Product
    {
        $id = $product->getId();
        $this->em->clear();

        return $this->products->findWithDetails([$id], withSeller: true)[0] ?? $product;
    }
}
