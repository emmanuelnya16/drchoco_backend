<?php

namespace App\Controller\Admin;

use App\Account\TextInput;
use App\Admin\AdminNormalizer;
use App\Admin\CustomerManager;
use App\Admin\Reservation\AdminReservationList;
use App\Admin\Reservation\CustomerDirectory;
use App\Api\ApiException;
use App\Api\ApiJson;
use App\Api\JsonBody;
use App\Catalog\TextFolder;
use App\Entity\Admin;
use App\Entity\User;
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
        private readonly CustomerManager $manager,
        private readonly UserRepository $users,
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

    /** POST /api/admin/customers/{id}/block — `{reason?}` (super admin) : sessions et notifications coupées aussitôt. */
    #[Route('/admin/customers/{id}/block', name: 'api_admin_customers_block', requirements: ['id' => '\d{1,10}'], methods: ['POST'])]
    public function block(int $id, Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $customer = $this->customer($admin, $id);
        $reason = TextInput::clean(JsonBody::object($request, allowEmpty: true)['reason'] ?? null) ?? '';
        if (TextInput::length($reason) > 200) {
            throw ApiException::validation(['reason' => 'Le motif ne doit pas dépasser 200 caractères.'], 'Le motif ne doit pas dépasser 200 caractères.');
        }
        $this->manager->block($customer, $admin, $reason !== '' ? $reason : null);

        return ApiJson::noStore(['customer' => $this->customers->one($admin, $customer)]);
    }

    /** POST /api/admin/customers/{id}/unblock (super admin). */
    #[Route('/admin/customers/{id}/unblock', name: 'api_admin_customers_unblock', requirements: ['id' => '\d{1,10}'], methods: ['POST'])]
    public function unblock(int $id, #[CurrentUser] Admin $admin): JsonResponse
    {
        $customer = $this->customer($admin, $id);
        $this->manager->unblock($customer, $admin);

        return ApiJson::noStore(['customer' => $this->customers->one($admin, $customer)]);
    }

    private function customer(Admin $admin, int $id): User
    {
        if (!$admin->isSuperAdmin()) {
            throw ApiException::forbidden('Réservé au super admin (propriétaire ou gérant).');
        }

        return $this->users->find($id) ?? throw ApiException::notFound('CUSTOMER_NOT_FOUND', 'Ce client est introuvable.');
    }
}
