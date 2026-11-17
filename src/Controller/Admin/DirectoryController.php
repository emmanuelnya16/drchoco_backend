<?php

namespace App\Controller\Admin;

use App\Admin\AdminNormalizer;
use App\Admin\Reservation\AdminReservationList;
use App\Admin\Reservation\CustomerDirectory;
use App\Api\ApiJson;
use App\Catalog\TextFolder;
use App\Entity\Admin;
use App\Repository\ProductRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Recherche rapide et répertoire des clients de l'administration.
 *
 * Chacun ne voit que ce qui le concerne :
 *  - super admin (owner, manager) : tout ;
 *  - vendeur : ses articles et ceux de sa ville sans vendeur ; les réservations qui en contiennent
 *    (ReservationScope) et les clients qui les ont faites.
 */
final class DirectoryController
{
    public const SEARCH_LIMIT = 6;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly AdminNormalizer $normalizer,
        private readonly AdminReservationList $reservations,
        private readonly CustomerDirectory $customers,
    ) {}

    /** GET /api/admin/search?q= — 2 caractères au moins ; réservations et articles, 6 de chaque au plus. */
    #[Route('/admin/search', name: 'api_admin_search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $query = trim(mb_substr((string) $request->query->get('q', ''), 0, 80));
        if (mb_strlen(TextFolder::fold($query)) < 2) {
            return ApiJson::noStore(['reservations' => [], 'products' => []]);
        }

        $products = $this->products->adminSearch($query, $admin->isSuperAdmin() ? null : $admin, self::SEARCH_LIMIT);

        return ApiJson::noStore([
            'reservations' => $this->reservations->search($admin, $query, self::SEARCH_LIMIT),
            'products'     => array_map($this->normalizer->searchProduct(...), $products),
        ]);
    }

    /** GET /api/admin/customers?q= — clients (connexion par SMS), les plus récemment actifs d'abord. */
    #[Route('/admin/customers', name: 'api_admin_customers', methods: ['GET'])]
    public function customers(Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        return ApiJson::noStore($this->customers->list($admin, (string) $request->query->get('q', '')));
    }
}
