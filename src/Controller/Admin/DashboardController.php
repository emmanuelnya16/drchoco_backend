<?php

namespace App\Controller\Admin;

use App\Admin\SalesFigures;
use App\Admin\Reservation\AdminReservationList;
use App\Api\ApiDate;
use App\Api\ApiJson;
use App\Entity\Admin;
use App\Entity\Product;
use App\Repository\ProductRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * GET /api/admin/dashboard — tableau de bord (`AdminDashboardData`), page d'accueil de l'administration.
 *
 * Réels, limités au périmètre d'un vendeur (ses articles et ceux de sa ville sans vendeur ; les réservations qui
 * en contiennent) : alertes de stock (variantes à 1 ou 2 unités, ruptures), compteurs des réservations et file
 * d'action (P6), chiffres de ventes par période (`kpis`, P7 : App\Admin\SalesFigures).
 */
final class DashboardController
{
    public const LOW_STOCK_MAX = 2;
    public const LOW_STOCK_ITEMS = 10;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly AdminReservationList $reservations,
        private readonly SalesFigures $sales,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/admin/dashboard', name: 'api_admin_dashboard', methods: ['GET'])]
    public function __invoke(#[CurrentUser] Admin $admin): JsonResponse
    {
        $seller = $admin->isSuperAdmin() ? null : $admin;
        // D'abord les réservations (les échues expirent et rendent leur stock), puis les alertes de stock.
        ['counts' => $reservationCounts, 'urgent' => $urgent] = $this->reservations->dashboard($admin);
        ['items' => $lowStockItems, 'low' => $low, 'out' => $out] = $this->stockAlerts($this->products->findActiveForAdmin($seller));

        $scope = ['role' => $admin->getRole()];
        if ($seller !== null) {
            $scope['sellerId'] = (string) $seller->getId();
            $scope['sellerName'] = $seller->getName();
        }
        if ($admin->getCity() !== null) {
            $scope['city'] = $admin->getCity();
        }

        $kpis = $this->sales->dashboard($admin);

        return ApiJson::noStore([
            'serverTime' => ApiDate::format($this->clock->now()),
            'scope'      => $scope,
            'counts'     => $reservationCounts + [
                'lowStockVariants'   => $low,
                'outOfStockVariants' => $out,
            ],
            'urgentReservations' => $urgent,
            'kpis'               => $kpis,
            'lowStockItems'      => $lowStockItems,
        ]);
    }

    /**
     * Variantes à surveiller : 1 unité d'abord, puis les ruptures, puis 2 unités (ordre du front).
     *
     * @param list<Product> $products
     *
     * @return array{items: list<array<string, mixed>>, low: int, out: int}
     */
    private function stockAlerts(array $products): array
    {
        $items = [];
        $low = 0;
        $out = 0;
        foreach ($products as $product) {
            $colors = [];
            foreach ($product->getColors() as $color) {
                $colors[(string) ($color['code'] ?? '')] = $color;
            }
            $gallery = $product->getMediaGallery();
            $image = (string) ($gallery[0]['url'] ?? $gallery[0]['fallbackUrl'] ?? '');

            foreach ($product->getVariants() as $variant) {
                $stock = $variant->getStock();
                if ($stock > self::LOW_STOCK_MAX) {
                    continue;
                }
                $stock === 0 ? ++$out : ++$low;
                $color = $colors[$variant->getColorCode()] ?? ($product->getColors()[0] ?? []);
                $items[] = [
                    'productId' => $product->getId(),
                    'slug'      => $product->getSlug(),
                    'name'      => $product->getName(),
                    'image'     => $image,
                    'variantId' => $variant->getId(),
                    'size'      => $variant->getSize(),
                    'colorName' => (string) ($color['name'] ?? $variant->getColorCode()),
                    'colorCode' => $variant->getColorCode(),
                    'colorHex'  => (string) ($color['hex'] ?? '#1A1A18'),
                    'stock'     => $stock,
                ];
            }
        }

        $rank = fn (int $stock) => $stock === 1 ? 0 : ($stock === 0 ? 1 : 2);
        usort($items, fn (array $a, array $b) => $rank($a['stock']) <=> $rank($b['stock']));

        return ['items' => \array_slice($items, 0, self::LOW_STOCK_ITEMS), 'low' => $low, 'out' => $out];
    }
}
