<?php

namespace App\Admin;

use App\Admin\Reservation\ReservationScope;
use App\Entity\Admin;
use App\Entity\Product;
use App\Entity\Reservation;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Chiffres de ventes du tableau de bord et de la liste des vendeurs (P7), en quelques requêtes SQL agrégées (le
 * tableau de bord est relu toutes les 30 s).
 *
 *  - Périodes en heure du Cameroun : aujourd'hui depuis minuit, 7, 30 et 365 jours (aujourd'hui compris).
 *  - Vente = réservation au paiement validé (`paid_at`) et toujours dans un statut payé : une réservation annulée
 *    après paiement ne compte plus. Ventes datées par la validation du paiement ; « créées » par la création.
 *  - CA = Σ prix × quantité des articles, frais de livraison exclus.
 *  - Vendeur : réservations de son périmètre pour les nombres, opérateurs et modes ; seulement ses lignes pour le
 *    CA, les pièces et les articles vendus (même règle que ReservationScope::owns).
 */
final class SalesFigures
{
    /** Jours avant aujourd'hui (minuit, heure du Cameroun) où commence chaque période. */
    public const PERIODS = ['today' => 0, '7d' => 6, '30d' => 29, 'year' => 364];
    public const PRODUCT_SALES_LIMIT = 8;
    private const TIMEZONE = 'Africa/Douala';

    public function __construct(
        private readonly Connection $db,
        private readonly ProductRepository $products,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * `AdminDashboardData.kpis`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function dashboard(Admin $admin): array
    {
        $starts = $this->starts();
        [$scope, $scopeParams] = ReservationScope::sql('r', $admin);
        [$lineScope, $lineParams] = self::lineScope($admin);
        $paid = $this->paidStatuses();
        $year = $starts['year'];

        // Réservations créées par période.
        $created = $this->db->fetchAssociative(
            'SELECT ' . $this->sums($starts, fn (string $p) => 'SUM(r.created_at >= ?) AS c_' . $p) . '
             FROM reservations r WHERE r.created_at >= ?' . $scope,
            [...array_values($starts), $year, ...$scopeParams],
        ) ?: [];

        // Ventes (réservations payées), par opérateur et par mode de réception.
        $columns = [];
        $params = [];
        foreach ($starts as $period => $start) {
            $columns[] = "SUM(r.paid_at >= ?) AS n_$period";
            $columns[] = "SUM(r.paid_at >= ? AND r.payment_operator = 'orange') AS orange_$period";
            $columns[] = "SUM(r.paid_at >= ? AND r.payment_operator = 'mtn') AS mtn_$period";
            $columns[] = "SUM(r.paid_at >= ? AND r.reception_mode = 'delivery') AS delivery_$period";
            $columns[] = "SUM(r.paid_at >= ? AND r.reception_mode = 'pickup') AS pickup_$period";
            array_push($params, $start, $start, $start, $start, $start);
        }
        $sales = $this->db->fetchAssociative(
            'SELECT ' . implode(', ', $columns) . " FROM reservations r WHERE r.paid_at >= ? AND r.status IN ($paid)" . $scope,
            [...$params, $year, ...$scopeParams],
        ) ?: [];

        // CA et pièces (les lignes du vendeur seulement pour un vendeur).
        $lines = $this->db->fetchAssociative(
            'SELECT ' . $this->sums($starts, fn (string $p) => "SUM(CASE WHEN r.paid_at >= ? THEN i.unit_price * i.quantity ELSE 0 END) AS rev_$p") . ', '
            . $this->sums($starts, fn (string $p) => "SUM(CASE WHEN r.paid_at >= ? THEN i.quantity ELSE 0 END) AS units_$p") . "
             FROM reservation_items i INNER JOIN reservations r ON r.id = i.reservation_id
             WHERE r.paid_at >= ? AND r.status IN ($paid)" . $lineScope,
            [...array_values($starts), ...array_values($starts), $year, ...$lineParams],
        ) ?: [];

        $productSales = $this->productSales($starts, $lineScope, $lineParams);

        $kpis = [];
        foreach (array_keys($starts) as $p) {
            $createdCount = (int) ($created["c_$p"] ?? 0);
            $confirmed = (int) ($sales["n_$p"] ?? 0);
            $revenue = (int) ($lines["rev_$p"] ?? 0);
            $kpis[$p] = [
                'revenueConfirmed'      => $revenue,
                'unitsSold'             => (int) ($lines["units_$p"] ?? 0),
                'reservationsCreated'   => $createdCount,
                'reservationsConfirmed' => $confirmed,
                'conversionRate'        => $createdCount > 0 ? min(100, (int) round(100 * $confirmed / $createdCount)) : 0,
                'averageBasket'         => $confirmed > 0 ? (int) round($revenue / $confirmed) : 0,
                'byOperator'            => ['orange' => (int) ($sales["orange_$p"] ?? 0), 'mtn' => (int) ($sales["mtn_$p"] ?? 0)],
                'byReception'           => ['delivery' => (int) ($sales["delivery_$p"] ?? 0), 'pickup' => (int) ($sales["pickup_$p"] ?? 0)],
                'productSales'          => $productSales[$p],
            ];
        }

        return $kpis;
    }

    /**
     * Chiffres de chaque vendeur (liste des vendeurs) : ses lignes seulement, même règle que son tableau de bord.
     *
     * @param list<Admin> $sellers
     *
     * @return array<int, array{unitsSoldTotal: int, revenueConfirmedTotal: int, kpis: array<string, array{unitsSold: int, revenueConfirmed: int, reservationsConfirmed: int}>}>
     */
    public function sellers(array $sellers): array
    {
        $figures = [];
        foreach ($sellers as $seller) {
            $figures[(int) $seller->getId()] = self::emptySeller();
        }
        $ids = array_keys($figures);
        if ($ids === []) {
            return [];
        }

        $starts = $this->starts();
        $paid = $this->paidStatuses();
        $rows = $this->db->fetchAllAssociative(
            'SELECT a.id AS seller_id, '
            . $this->sums($starts, fn (string $p) => "SUM(CASE WHEN r.paid_at >= ? THEN i.quantity ELSE 0 END) AS units_$p") . ', '
            . $this->sums($starts, fn (string $p) => "SUM(CASE WHEN r.paid_at >= ? THEN i.unit_price * i.quantity ELSE 0 END) AS rev_$p") . ', '
            . $this->sums($starts, fn (string $p) => "COUNT(DISTINCT CASE WHEN r.paid_at >= ? THEN r.id END) AS n_$p") . "
             FROM admins a
             INNER JOIN reservation_items i ON (i.seller_id = a.id OR (i.seller_id IS NULL AND i.city = a.city))
             INNER JOIN reservations r ON r.id = i.reservation_id
             WHERE a.id IN (" . implode(', ', array_map('intval', $ids)) . ") AND r.paid_at >= ? AND r.status IN ($paid)
             GROUP BY a.id",
            [...array_values($starts), ...array_values($starts), ...array_values($starts), $starts['year']],
        );

        foreach ($rows as $row) {
            $kpis = [];
            foreach (array_keys($starts) as $p) {
                $kpis[$p] = ['unitsSold' => (int) $row["units_$p"], 'revenueConfirmed' => (int) $row["rev_$p"], 'reservationsConfirmed' => (int) $row["n_$p"]];
            }
            $figures[(int) $row['seller_id']] = [
                'unitsSoldTotal'        => $kpis['year']['unitsSold'],
                'revenueConfirmedTotal' => $kpis['year']['revenueConfirmed'],
                'kpis'                  => $kpis,
            ];
        }

        return $figures;
    }

    /** @return array{unitsSoldTotal: int, revenueConfirmedTotal: int, kpis: array<string, array{unitsSold: int, revenueConfirmed: int, reservationsConfirmed: int}>} */
    public static function emptySeller(): array
    {
        $kpis = [];
        foreach (array_keys(self::PERIODS) as $p) {
            $kpis[$p] = ['unitsSold' => 0, 'revenueConfirmed' => 0, 'reservationsConfirmed' => 0];
        }

        return ['unitsSoldTotal' => 0, 'revenueConfirmedTotal' => 0, 'kpis' => $kpis];
    }

    /**
     * Articles les plus vendus de chaque période (8 au plus, CA décroissant puis pièces).
     *
     * @param array<string, string> $starts
     * @param list<int|string>      $lineParams
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function productSales(array $starts, string $lineScope, array $lineParams): array
    {
        $paid = $this->paidStatuses();
        $rows = $this->db->fetchAllAssociative(
            'SELECT i.product_id, MAX(i.id) AS last_item, '
            . $this->sums($starts, fn (string $p) => "SUM(CASE WHEN r.paid_at >= ? THEN i.quantity ELSE 0 END) AS units_$p") . ', '
            . $this->sums($starts, fn (string $p) => "SUM(CASE WHEN r.paid_at >= ? THEN i.unit_price * i.quantity ELSE 0 END) AS rev_$p") . "
             FROM reservation_items i INNER JOIN reservations r ON r.id = i.reservation_id
             WHERE r.paid_at >= ? AND r.status IN ($paid)" . $lineScope . '
             GROUP BY i.product_id',
            [...array_values($starts), ...array_values($starts), $starts['year'], ...$lineParams],
        );

        $top = [];
        $needed = [];
        foreach (array_keys($starts) as $p) {
            $list = array_values(array_filter($rows, fn (array $row) => (int) $row["units_$p"] > 0));
            usort($list, fn (array $a, array $b) => [(int) $b["rev_$p"], (int) $b["units_$p"], $a['product_id']] <=> [(int) $a["rev_$p"], (int) $a["units_$p"], $b['product_id']]);
            $top[$p] = \array_slice($list, 0, self::PRODUCT_SALES_LIMIT);
            foreach ($top[$p] as $row) {
                $needed[(string) $row['product_id']] = (int) $row['last_item'];
            }
        }
        if ($needed === []) {
            return array_fill_keys(array_keys($starts), []);
        }

        $products = [];
        foreach ($this->products->findBy(['id' => array_keys($needed)]) as $product) {
            $products[$product->getId()] = $product;
        }
        // Article supprimé depuis : fiche reprise de la dernière réservation.
        $snapshots = [];
        $missing = array_diff_key($needed, $products);
        if ($missing !== []) {
            foreach ($this->db->fetchAllAssociative(
                'SELECT product_id, product_slug, product_name, image, city, seller_id, seller_name, unit_price FROM reservation_items WHERE id IN (' . implode(', ', array_map('intval', $missing)) . ')',
            ) as $snapshot) {
                $snapshots[(string) $snapshot['product_id']] = $snapshot;
            }
        }

        $result = [];
        foreach ($top as $p => $list) {
            $result[$p] = array_map(fn (array $row) => $this->productLine(
                (string) $row['product_id'],
                $products[(string) $row['product_id']] ?? null,
                $snapshots[(string) $row['product_id']] ?? [],
                (int) $row["units_$p"],
                (int) $row["rev_$p"],
            ), $list);
        }

        return $result;
    }

    /**
     * `AdminProductSaleStat`.
     *
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    private function productLine(string $productId, ?Product $product, array $snapshot, int $units, int $revenue): array
    {
        if ($product !== null) {
            $gallery = $product->getMediaGallery();
            $stock = 0;
            foreach ($product->getVariants() as $variant) {
                $stock += $variant->getStock();
            }
            $seller = $product->getSeller();
            $line = [
                'productId' => $productId,
                'slug'      => $product->getSlug(),
                'name'      => $product->getName(),
                'image'     => (string) ($gallery[0]['url'] ?? $gallery[0]['fallbackUrl'] ?? ''),
                'city'      => $product->getCity(),
            ];
            if ($seller !== null) {
                $line['sellerId'] = (string) $seller->getId();
                $line['sellerName'] = $seller->getName();
            }

            return $line + ['unitPrice' => $product->getPrice(), 'unitsSold' => $units, 'revenue' => $revenue, 'stock' => $stock];
        }

        $line = [
            'productId' => $productId,
            'slug'      => (string) ($snapshot['product_slug'] ?? ''),
            'name'      => (string) ($snapshot['product_name'] ?? ''),
            'image'     => (string) ($snapshot['image'] ?? ''),
            'city'      => (string) ($snapshot['city'] ?? ''),
        ];
        if (($snapshot['seller_id'] ?? null) !== null) {
            $line['sellerId'] = (string) $snapshot['seller_id'];
            if (($snapshot['seller_name'] ?? null) !== null) {
                $line['sellerName'] = (string) $snapshot['seller_name'];
            }
        }

        return $line + ['unitPrice' => (int) ($snapshot['unit_price'] ?? 0), 'unitsSold' => $units, 'revenue' => $revenue, 'stock' => 0];
    }

    /** Début de chaque période (minuit, heure du Cameroun), en UTC pour la base. @return array<string, string> */
    private function starts(): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $midnight = (new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp()))->setTimezone($tz)->setTime(0, 0);
        $starts = [];
        foreach (self::PERIODS as $period => $days) {
            $starts[$period] = $midnight->modify("-$days days")->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        return $starts;
    }

    /**
     * Lignes du vendeur (SQL, alias `i`) ; rien à ajouter pour un super admin.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private static function lineScope(Admin $admin): array
    {
        if ($admin->isSuperAdmin()) {
            return ['', []];
        }

        return [' AND (i.seller_id = ? OR (i.seller_id IS NULL AND i.city = ?))', [(int) $admin->getId(), (string) $admin->getCity()]];
    }

    /** @param array<string, string> $starts @param callable(string): string $column */
    private function sums(array $starts, callable $column): string
    {
        return implode(', ', array_map($column, array_keys($starts)));
    }

    private function paidStatuses(): string
    {
        return implode(', ', array_map(fn (string $s) => $this->db->quote($s), Reservation::PAID_STATUSES));
    }
}
