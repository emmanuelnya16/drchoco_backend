<?php

namespace App\Controller\Admin;

use App\Admin\AdminNormalizer;
use App\Api\ApiJson;
use App\Catalog\TextFolder;
use App\Entity\Admin;
use App\Repository\AddressRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Recherche rapide et répertoire des clients de l'administration.
 *
 * Chacun ne voit que ce qui le concerne :
 *  - super admin (owner, manager) : tout ;
 *  - vendeur : ses articles et ceux de sa ville sans vendeur ; les clients qui ont réservé chez lui
 *    (à partir des réservations, phase P5 : aucun pour l'instant).
 */
final class DirectoryController
{
    public const CUSTOMERS_LIMIT = 100;
    public const SEARCH_LIMIT = 6;

    public function __construct(
        private readonly UserRepository $users,
        private readonly AddressRepository $addresses,
        private readonly ProductRepository $products,
        private readonly AdminNormalizer $normalizer,
    ) {}

    /** GET /api/admin/search?q= — 2 caractères au moins ; réservations (P5) et articles. */
    #[Route('/admin/search', name: 'api_admin_search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $query = trim(mb_substr((string) $request->query->get('q', ''), 0, 80));
        if (mb_strlen(TextFolder::fold($query)) < 2) {
            return ApiJson::noStore(['reservations' => [], 'products' => []]);
        }

        $products = $this->products->adminSearch($query, $admin->isSuperAdmin() ? null : $admin, self::SEARCH_LIMIT);

        return ApiJson::noStore([
            'reservations' => [],
            'products'     => array_map($this->normalizer->searchProduct(...), $products),
        ]);
    }

    /** GET /api/admin/customers?q= — clients inscrits (connexion par SMS), les plus récemment actifs d'abord. */
    #[Route('/admin/customers', name: 'api_admin_customers', methods: ['GET'])]
    public function customers(Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        if (!$admin->isSuperAdmin()) {
            return ApiJson::noStore(['items' => [], 'total' => 0]);
        }

        $result = $this->users->adminList(mb_substr((string) $request->query->get('q', ''), 0, 80), self::CUSTOMERS_LIMIT);
        $addresses = $this->addresses->findForUsers($result['items']);

        return ApiJson::noStore([
            'items' => array_map(
                fn ($user) => $this->normalizer->customer($user, $addresses[(int) $user->getId()] ?? []),
                $result['items'],
            ),
            'total' => $result['total'],
        ]);
    }
}
