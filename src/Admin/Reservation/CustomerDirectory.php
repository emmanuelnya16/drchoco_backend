<?php

namespace App\Admin\Reservation;

use App\Admin\AdminNormalizer;
use App\Entity\Admin;
use App\Entity\Reservation;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Repository\ReservationRepository;
use App\Repository\UserRepository;
use App\Reservation\ReservationLifecycle;
use Doctrine\DBAL\Connection;

/**
 * Répertoire des clients de l'administration (GET /api/admin/customers), avec leurs réservations (P6) :
 *  - super admin : tous les clients inscrits, les plus récemment actifs d'abord (compte ou réservation) ;
 *  - vendeur : seulement les clients qui ont réservé dans son périmètre, réservations limitées à ce périmètre, total
 *    payé limité à ses propres lignes, sans leurs adresses enregistrées.
 */
final class CustomerDirectory
{
    public const LIMIT = 100;
    public const RESERVATIONS_PER_CUSTOMER = 20;

    public function __construct(
        private readonly Connection $db,
        private readonly UserRepository $users,
        private readonly AddressRepository $addresses,
        private readonly ReservationRepository $reservations,
        private readonly AdminNormalizer $normalizer,
        private readonly AdminReservationNormalizer $reservationNormalizer,
        private readonly ReservationLifecycle $lifecycle,
    ) {}

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function list(Admin $admin, string $query): array
    {
        $query = trim(mb_substr($query, 0, 80));
        ['ids' => $ids, 'total' => $total] = $admin->isSuperAdmin() ? $this->allCustomers($query) : $this->sellerCustomers($admin, $query);
        if ($ids === []) {
            return ['items' => [], 'total' => $total];
        }

        $byId = [];
        foreach ($this->users->findBy(['id' => $ids]) as $user) {
            $byId[(int) $user->getId()] = $user;
        }

        return [
            'items' => $this->items($admin, array_values(array_filter(array_map(fn (int $id) => $byId[$id] ?? null, $ids)))),
            'total' => $total,
        ];
    }

    /** Fiche d'un client, comme dans la liste. @return array<string, mixed> */
    public function one(Admin $admin, User $user): array
    {
        return $this->items($admin, [$user])[0];
    }

    /**
     * @param list<User> $users
     *
     * @return list<array<string, mixed>>
     */
    private function items(Admin $admin, array $users): array
    {
        $ids = array_map(fn (User $user) => (int) $user->getId(), $users);
        $addresses = $admin->isSuperAdmin() ? $this->addresses->findForUsers($users) : [];
        $stats = $this->stats($admin, $ids);
        $rows = $this->rows($admin, $ids);

        return array_map(fn (User $user) => $this->normalizer->customer(
            $user,
            $addresses[(int) $user->getId()] ?? [],
            $stats[(int) $user->getId()] ?? null,
            $rows[(int) $user->getId()] ?? [],
            $admin->isSuperAdmin(),
        ), $users);
    }

    /** @return array{ids: list<int>, total: int} */
    private function allCustomers(string $query): array
    {
        [$where, $params] = $this->userFilter($query);
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM users u WHERE 1 = 1' . $where, $params);
        $ids = $this->db->fetchFirstColumn(
            'SELECT u.id FROM users u
             LEFT JOIN (SELECT user_id, MAX(updated_at) AS last_reservation FROM reservations GROUP BY user_id) lr ON lr.user_id = u.id
             WHERE 1 = 1' . $where . '
             ORDER BY GREATEST(COALESCE(u.updated_at, u.created_at), COALESCE(lr.last_reservation, u.created_at)) DESC, u.id DESC
             LIMIT ' . self::LIMIT,
            $params,
        );

        return ['ids' => array_map('intval', $ids), 'total' => $total];
    }

    /** @return array{ids: list<int>, total: int} */
    private function sellerCustomers(Admin $seller, string $query): array
    {
        [$scope, $scopeParams] = ReservationScope::sql('r', $seller);
        [$where, $params] = $this->userFilter($query);
        $from = ' FROM reservations r INNER JOIN users u ON u.id = r.user_id WHERE 1 = 1' . $scope . $where;
        $params = [...$scopeParams, ...$params];

        $total = (int) $this->db->fetchOne('SELECT COUNT(DISTINCT r.user_id)' . $from, $params);
        $ids = $this->db->fetchFirstColumn(
            'SELECT r.user_id' . $from . ' GROUP BY r.user_id ORDER BY MAX(r.updated_at) DESC, r.user_id DESC LIMIT ' . self::LIMIT,
            $params,
        );

        return ['ids' => array_map('intval', $ids), 'total' => $total];
    }

    /**
     * Recherche sur le nom, la ville ou le téléphone (chiffres, à partir de 2).
     *
     * @return array{0: string, 1: list<string>}
     */
    private function userFilter(string $query): array
    {
        if ($query === '') {
            return ['', []];
        }
        $like = '%' . addcslashes($query, '%_\\') . '%';
        $conditions = ["CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) LIKE ?", 'u.city LIKE ?', 'u.phone LIKE ?'];
        $params = [$like, $like, $like];
        $digits = (string) preg_replace('/\D/', '', $query);
        if (\strlen($digits) >= 2) {
            $conditions[] = 'u.phone LIKE ?';
            $params[] = '%' . $digits . '%';
        }

        return [' AND (' . implode(' OR ', $conditions) . ')', $params];
    }

    /**
     * Nombre de réservations, total payé et dernière réservation de chaque client (dans le périmètre).
     *
     * @param list<int> $ids
     *
     * @return array<int, array{count: int, paid: int, last: ?\DateTimeImmutable}>
     */
    private function stats(Admin $admin, array $ids): array
    {
        $paid = implode(', ', array_map(fn (string $s) => $this->db->quote($s), Reservation::PAID_STATUSES));
        $users = implode(', ', array_map('intval', $ids));
        // Vendeur : ses lignes seulement (une réservation peut contenir aussi les articles d'un collègue).
        $rows = $admin->isSuperAdmin()
            ? $this->db->fetchAllAssociative(
                'SELECT r.user_id, COUNT(*) AS n, SUM(CASE WHEN r.status IN (' . $paid . ') THEN r.total ELSE 0 END) AS paid, MAX(r.updated_at) AS last
                 FROM reservations r WHERE r.user_id IN (' . $users . ') GROUP BY r.user_id',
            )
            : $this->db->fetchAllAssociative(
                'SELECT r.user_id, COUNT(DISTINCT r.id) AS n, SUM(CASE WHEN r.status IN (' . $paid . ') THEN i.unit_price * i.quantity ELSE 0 END) AS paid, MAX(r.updated_at) AS last
                 FROM reservations r INNER JOIN reservation_items i ON i.reservation_id = r.id
                 WHERE r.user_id IN (' . $users . ') AND (i.seller_id = ? OR (i.seller_id IS NULL AND i.city = ?)) GROUP BY r.user_id',
                [(int) $admin->getId(), (string) $admin->getCity()],
            );

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int) $row['user_id']] = [
                'count' => (int) $row['n'],
                'paid'  => (int) $row['paid'],
                'last'  => $row['last'] !== null ? new \DateTimeImmutable((string) $row['last'], new \DateTimeZone('UTC')) : null,
            ];
        }

        return $stats;
    }

    /**
     * Réservations de chaque client (les 20 plus récentes), en lignes `AdminReservationRow`.
     *
     * @param list<int> $ids
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function rows(Admin $admin, array $ids): array
    {
        [$scope, $scopeParams] = ReservationScope::sql('r', $admin);
        // Limite appliquée par la base (fonction de fenêtre) : un client qui a des milliers de réservations ne charge rien de plus.
        $pairs = $this->db->fetchAllAssociative(
            'SELECT ranked.id, ranked.user_id FROM (
                SELECT r.id, r.user_id, r.updated_at, ROW_NUMBER() OVER (PARTITION BY r.user_id ORDER BY r.updated_at DESC, r.id DESC) AS position_rank
                FROM reservations r WHERE r.user_id IN (' . implode(', ', array_map('intval', $ids)) . ')' . $scope . '
             ) ranked WHERE ranked.position_rank <= ' . self::RESERVATIONS_PER_CUSTOMER . ' ORDER BY ranked.updated_at DESC, ranked.id DESC',
            $scopeParams,
        );

        $kept = [];
        $owners = [];
        foreach ($pairs as $pair) {
            $kept[] = (int) $pair['id'];
            $owners[(int) $pair['id']] = (int) $pair['user_id'];
        }

        $now = $this->lifecycle->now();
        $rows = [];
        foreach ($this->reservations->loadForRows($kept) as $reservation) {
            $rows[$owners[(int) $reservation->getId()]][] = $this->reservationNormalizer->row($reservation, $now);
        }

        return $rows;
    }
}
