<?php

namespace App\Admin\Reservation;

use App\Catalog\ShopCity;
use App\Entity\Admin;
use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use App\Reservation\ReservationLifecycle;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lectures de l'administration sur les réservations (P6), toujours limitées au périmètre du membre de l'équipe
 * (ReservationScope) : liste avec onglets, recherche rapide, file d'action et compteurs du tableau de bord.
 */
final class AdminReservationList
{
    public const PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE = 50;
    public const EXPIRING_HOURS = 3;

    /** Onglets de l'écran (AdminReservationsPage) : statuts et clé du compteur. */
    public const TABS = [
        'to_validate' => ['key' => 'toValidate', 'statuses' => [Reservation::STATUS_PENDING_VALIDATION]],
        'to_prepare'  => ['key' => 'toPrepare', 'statuses' => [Reservation::STATUS_CONFIRMED, Reservation::STATUS_PREPARING]],
        'ready'       => ['key' => 'ready', 'statuses' => [Reservation::STATUS_READY_FOR_PICKUP, Reservation::STATUS_HANDED_TO_AGENCY]],
        'in_delivery' => ['key' => 'inDelivery', 'statuses' => [Reservation::STATUS_IN_DELIVERY]],
        'attention'   => ['key' => 'attention', 'statuses' => Reservation::AWAITING_PAYMENT],
        'done'        => ['key' => 'done', 'statuses' => Reservation::FINAL_STATUSES],
    ];

    private const STATUSES = [
        Reservation::STATUS_RESERVED, Reservation::STATUS_PENDING_VALIDATION, Reservation::STATUS_PAYMENT_REJECTED,
        Reservation::STATUS_CONFIRMED, Reservation::STATUS_PREPARING, Reservation::STATUS_HANDED_TO_AGENCY,
        Reservation::STATUS_READY_FOR_PICKUP, Reservation::STATUS_IN_DELIVERY, Reservation::STATUS_DELIVERED,
        Reservation::STATUS_PICKED_UP, Reservation::STATUS_EXPIRED, Reservation::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ReservationLifecycle $lifecycle,
        private readonly AdminReservationNormalizer $normalizer,
    ) {}

    /** GET /api/admin/reservations — `AdminReservationsListResponse`. @return array<string, mixed> */
    public function page(Admin $admin, Request $request): array
    {
        // Réservations échues passées à « expirée » avant l'affichage (sans attendre la tâche planifiée).
        $this->lifecycle->expireDue(null, 100);
        $now = $this->lifecycle->now();
        $query = $request->query;

        $status = (string) $query->get('status', '');
        $tab = (string) $query->get('tab', 'all');
        $statuses = \in_array($status, self::STATUSES, true) ? [$status] : (self::TABS[$tab]['statuses'] ?? null);
        $mode = (string) $query->get('receptionMode', '');
        $operator = (string) $query->get('operator', '');
        $filters = [
            'statuses'      => $statuses,
            'receptionMode' => \in_array($mode, [Reservation::MODE_DELIVERY, Reservation::MODE_PICKUP], true) ? $mode : null,
            'city'          => trim(mb_substr((string) $query->get('city', ''), 0, 100)) ?: null,
            'shopCity'      => ShopCity::normalize((string) $query->get('shopCity', '')),
            'operator'      => \in_array($operator, ['orange', 'mtn'], true) ? $operator : null,
            'q'             => trim(mb_substr((string) $query->get('q', ''), 0, 80)) ?: null,
        ];

        $pageSize = max(1, min(self::MAX_PAGE_SIZE, (int) $query->get('pageSize', (string) self::PAGE_SIZE) ?: self::PAGE_SIZE));
        $page = max(1, (int) $query->get('page', '1'));
        $result = $this->reservations->adminPage($admin, $filters, ($page - 1) * $pageSize, $pageSize);
        $totalPages = max(1, (int) ceil($result['total'] / $pageSize));
        if ($page > $totalPages) {
            // Page au-delà de la fin (filtre changé, réservations sorties de l'onglet) : dernière page.
            $page = $totalPages;
            $result = $this->reservations->adminPage($admin, $filters, ($page - 1) * $pageSize, $pageSize);
        }

        return [
            'items'      => $this->rows($result['ids'], $now),
            'page'       => $page,
            'pageSize'   => $pageSize,
            'total'      => $result['total'],
            'totalPages' => $totalPages,
            'counts'     => $this->tabCounts($admin),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function search(Admin $admin, string $query, int $limit): array
    {
        return $this->rows($this->reservations->adminSearchIds($admin, $query, $limit), $this->lifecycle->now());
    }

    /**
     * Compteurs et file d'action du tableau de bord.
     *
     * @return array{counts: array<string, int>, urgent: list<array<string, mixed>>}
     */
    public function dashboard(Admin $admin, int $urgentLimit = 5): array
    {
        $this->lifecycle->expireDue(null, 100);
        $now = $this->lifecycle->now();
        $byStatus = $this->reservations->adminStatusCounts($admin);
        $sum = fn (array $statuses) => array_sum(array_map(fn (string $s) => $byStatus[$s] ?? 0, $statuses));

        return [
            'counts' => [
                'pendingValidation' => $sum([Reservation::STATUS_PENDING_VALIDATION]),
                'toPrepare'         => $sum(self::TABS['to_prepare']['statuses']),
                'readyForHandover'  => $sum(self::TABS['ready']['statuses']),
                'expiringSoon'      => $this->reservations->adminExpiringCount($admin, $now, $now->modify(sprintf('+%d hours', self::EXPIRING_HOURS))),
                'activeTotal'       => array_sum($byStatus) - $sum(Reservation::FINAL_STATUSES),
            ],
            'urgent' => $this->rows($this->reservations->adminUrgentIds($admin, $urgentLimit), $now),
        ];
    }

    /**
     * Lignes `AdminReservationRow`, dans l'ordre des identifiants.
     *
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $ids, \DateTimeImmutable $now): array
    {
        return array_map(fn (Reservation $r) => $this->normalizer->row($r, $now), $this->reservations->loadForRows($ids));
    }

    /** @return array<string, int> compteurs des onglets, sur tout le périmètre (sans les autres filtres) */
    private function tabCounts(Admin $admin): array
    {
        $byStatus = $this->reservations->adminStatusCounts($admin);
        $counts = ['all' => array_sum($byStatus)];
        foreach (self::TABS as $tab) {
            $counts[$tab['key']] = array_sum(array_map(fn (string $s) => $byStatus[$s] ?? 0, $tab['statuses']));
        }

        return $counts;
    }
}
