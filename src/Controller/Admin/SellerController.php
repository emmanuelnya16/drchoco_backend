<?php

namespace App\Controller\Admin;

use App\Admin\AdminNormalizer;
use App\Admin\SalesFigures;
use App\Admin\SellerManager;
use App\Api\ApiException;
use App\Api\ApiJson;
use App\Api\JsonBody;
use App\Entity\Admin;
use App\Repository\AdminRepository;
use App\Repository\ProductRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Comptes vendeurs (écran « Vendeurs (Villes) ») : réservé aux super admins (owner, manager).
 * Double contrôle : règle d'accès de config/packages/security.yaml et vérification ici.
 */
final class SellerController
{
    public function __construct(
        private readonly SellerManager $manager,
        private readonly AdminRepository $admins,
        private readonly ProductRepository $products,
        private readonly AdminNormalizer $normalizer,
        private readonly SalesFigures $sales,
    ) {}

    /** GET /api/admin/sellers */
    #[Route('/admin/sellers', name: 'api_admin_sellers', methods: ['GET'])]
    public function list(#[CurrentUser] Admin $admin): JsonResponse
    {
        $this->assertSuperAdmin($admin);
        $sellers = $this->admins->findSellers();
        $counts = $this->products->countForSellers($sellers);
        $figures = $this->sales->sellers($sellers);

        return ApiJson::noStore([
            'sellers' => array_map(fn (Admin $seller) => $this->normalizer->seller($seller, $counts[(int) $seller->getId()] ?? 0, $figures[(int) $seller->getId()] ?? null), $sellers),
        ]);
    }

    /** POST /api/admin/sellers — `{ name, email, phone, password, city }` → 201 `{ seller }`. */
    #[Route('/admin/sellers', name: 'api_admin_sellers_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $this->assertSuperAdmin($admin);
        $seller = $this->manager->create(JsonBody::object($request), $admin);

        return ApiJson::noStore(['seller' => $this->normalizer->admin($seller)], 201);
    }

    /** PATCH /api/admin/sellers/{id} — nom, e-mail, téléphone, mot de passe, ville, actif. */
    #[Route('/admin/sellers/{id}', name: 'api_admin_sellers_update', methods: ['PATCH'])]
    public function update(string $id, Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $this->assertSuperAdmin($admin);
        $seller = $this->manager->update($this->find($id), JsonBody::object($request), $admin);

        return ApiJson::noStore(['seller' => $this->normalizer->admin($seller)]);
    }

    /** DELETE /api/admin/sellers/{id} — 204 ; refusé (409) si le vendeur a encore des articles. */
    #[Route('/admin/sellers/{id}', name: 'api_admin_sellers_delete', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] Admin $admin): Response
    {
        $this->assertSuperAdmin($admin);
        $this->manager->delete($this->find($id), $admin);

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function find(string $id): Admin
    {
        return $this->admins->findSeller($id)
            ?? throw ApiException::notFound('SELLER_NOT_FOUND', 'Ce vendeur est introuvable.');
    }

    private function assertSuperAdmin(Admin $admin): void
    {
        if (!$admin->isSuperAdmin()) {
            throw ApiException::forbidden('Seul le Super Admin peut gérer les comptes vendeurs.');
        }
    }
}
