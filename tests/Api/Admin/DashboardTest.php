<?php

namespace App\Tests\Api\Admin;

use App\Entity\Admin;
use App\Entity\Product;
use App\Tests\AdminTestCase;

/**
 * GET /api/admin/dashboard : alertes de stock réelles, limitées à la ville pour un vendeur ; chiffres de ventes
 * à zéro tant qu'il n'y a pas de réservation (phase P5).
 */
final class DashboardTest extends AdminTestCase
{
    public function testSuperAdminSeesEveryStockAlert(): void
    {
        $this->account();
        $data = $this->api('GET', '/api/admin/dashboard', $this->adminToken('owner@drchoco.cm'));

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['serverTime', 'scope', 'counts', 'urgentReservations', 'kpis', 'lowStockItems'], array_keys($data));
        self::assertSame(['role' => 'owner'], $data['scope']);
        self::assertSame([], $data['urgentReservations']);
        self::assertSame(['today', '7d', '30d', 'year'], array_keys($data['kpis']));
        self::assertSame(0, $data['kpis']['30d']['revenueConfirmed']);
        self::assertGreaterThan(0, $data['counts']['outOfStockVariants'] + $data['counts']['lowStockVariants']);
        self::assertLessThanOrEqual(10, \count($data['lowStockItems']));
        self::assertSame(['productId', 'slug', 'name', 'image', 'variantId', 'size', 'colorName', 'colorCode', 'colorHex', 'stock'], array_keys($data['lowStockItems'][0]));
        foreach ($data['lowStockItems'] as $item) {
            self::assertLessThanOrEqual(2, $item['stock']);
        }

        // Ordre du front : 1 unité, puis ruptures, puis 2 unités.
        $rank = fn (int $s) => $s === 1 ? 0 : ($s === 0 ? 1 : 2);
        $ranks = array_map($rank, array_column($data['lowStockItems'], 'stock'));
        $sorted = $ranks;
        sort($sorted);
        self::assertSame($sorted, $ranks);
    }

    public function testSellerOnlySeesTheirCity(): void
    {
        $seller = $this->account(Admin::ROLE_SELLER, 'vendeur.douala@drchoco.cm', 'Douala');
        $data = $this->api('GET', '/api/admin/dashboard', $this->adminToken('vendeur.douala@drchoco.cm'));

        self::assertSame(['role' => 'seller', 'sellerId' => (string) $seller->getId(), 'sellerName' => 'Seller Test', 'city' => 'Douala'], $data['scope']);
        foreach (array_unique(array_column($data['lowStockItems'], 'slug')) as $slug) {
            self::assertSame('Douala', $this->getJson('/api/products/' . $slug)['city'], $slug);
        }
    }

    public function testRetiredProductsRaiseNoAlert(): void
    {
        $this->account();
        $token = $this->adminToken('owner@drchoco.cm');
        $before = $this->api('GET', '/api/admin/dashboard', $token)['counts'];

        // tshirt-archive-01 : épuisé dans les données de départ.
        $this->mutateProduct('tshirt-archive-01', fn (Product $p) => $p->setIsActive(false));
        $after = $this->api('GET', '/api/admin/dashboard', $token)['counts'];

        self::assertLessThan($before['outOfStockVariants'], $after['outOfStockVariants']);
    }
}
