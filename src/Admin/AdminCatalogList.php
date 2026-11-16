<?php

namespace App\Admin;

use App\Catalog\ShopCity;
use App\Catalog\TextFolder;
use App\Entity\Admin;
use App\Entity\Product;
use App\Repository\ProductRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * GET /api/admin/products — liste du catalogue de l'administration (`AdminProductsListResponse`).
 *
 * Même logique que les mocks du front : filtres (catégorie, ville, vendeur, stock, recherche), tris, pages,
 * et un résumé calculé sur tout le périmètre du compte, avant filtres (en-tête de la page).
 * Le catalogue d'une boutique tient en mémoire : le tri et les filtres se font ici, sur les articles du périmètre.
 */
final class AdminCatalogList
{
    public const DEFAULT_PAGE_SIZE = 24;
    public const MAX_PAGE_SIZE = 50;
    private const SORTS = ['newest', 'name', 'stock_asc', 'price_asc', 'price_desc'];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly AdminNormalizer $normalizer,
    ) {}

    /** @return array<string, mixed> */
    public function list(Request $request, Admin $admin): array
    {
        $query = $request->query;
        $pool = $this->products->findActiveForAdmin($admin->isSuperAdmin() ? null : $admin, withSeller: true);

        $summary = ['totalProducts' => \count($pool), 'inStockProducts' => 0, 'lowStockProducts' => 0, 'outOfStockProducts' => 0];
        foreach ($pool as $product) {
            $stock = self::stockInfo($product);
            if ($stock['total'] === 0) {
                ++$summary['outOfStockProducts'];
            } elseif ($stock['total'] <= 3 || $stock['hasZero']) {
                ++$summary['lowStockProducts'];
            } else {
                ++$summary['inStockProducts'];
            }
        }

        $items = array_values(array_filter($pool, fn (Product $p) => $this->matches($p, $query->all(), $admin)));
        $this->sort($items, (string) $query->get('sort', 'newest'));

        $pageSize = self::intParam($query->get('pageSize'), self::DEFAULT_PAGE_SIZE);
        $pageSize = $pageSize < 1 ? self::DEFAULT_PAGE_SIZE : min(self::MAX_PAGE_SIZE, $pageSize);
        $total = \count($items);
        $totalPages = max(1, (int) ceil($total / $pageSize));
        // Page au-delà de la fin (après une suppression) : la dernière page, comme les mocks.
        $page = min($totalPages, max(1, self::intParam($query->get('page'), 1)));

        return [
            'items'      => array_map($this->normalizer->adminProduct(...), \array_slice($items, ($page - 1) * $pageSize, $pageSize)),
            'page'       => $page,
            'pageSize'   => $pageSize,
            'total'      => $total,
            'totalPages' => $totalPages,
            'summary'    => $summary,
        ];
    }

    /** @return array{total: int, hasZero: bool, hasLow: bool} */
    public static function stockInfo(Product $product): array
    {
        $total = 0;
        $hasZero = false;
        $hasLow = false;
        foreach ($product->getVariants() as $variant) {
            $stock = $variant->getStock();
            $total += $stock;
            $hasZero = $hasZero || $stock === 0;
            $hasLow = $hasLow || ($stock >= 1 && $stock <= 2);
        }

        return ['total' => $total, 'hasZero' => $hasZero, 'hasLow' => $hasLow];
    }

    /** @param array<string, mixed> $params */
    private function matches(Product $product, array $params, Admin $admin): bool
    {
        $category = \is_string($params['category'] ?? null) ? trim($params['category']) : '';
        if ($category !== '' && $product->getCategory()->getSlug() !== $category) {
            return false;
        }

        // Ville et vendeur : filtres du super admin (le périmètre d'un vendeur vient de son compte).
        if ($admin->isSuperAdmin()) {
            $city = \is_string($params['city'] ?? null) ? trim($params['city']) : '';
            if ($city !== '' && $city !== 'all' && $product->getCity() !== ShopCity::normalize($city)) {
                return false;
            }
            $sellerId = \is_string($params['sellerId'] ?? null) ? trim($params['sellerId']) : '';
            if ($sellerId !== '' && (string) $product->getSeller()?->getId() !== $sellerId) {
                return false;
            }
        }

        $stock = self::stockInfo($product);
        $ok = match ((string) ($params['stockStatus'] ?? 'all')) {
            'in_stock'     => $stock['total'] > 0,
            'low_stock'    => $stock['total'] > 0 && ($stock['total'] <= 3 || $stock['hasLow']),
            'out_of_stock' => $stock['total'] === 0 || $stock['hasZero'],
            default        => true,
        };
        if (!$ok) {
            return false;
        }

        $q = \is_string($params['q'] ?? null) ? TextFolder::fold(mb_substr($params['q'], 0, 80)) : '';
        if ($q === '') {
            return true;
        }
        $haystack = TextFolder::fold(implode(' ', [
            $product->getName(), $product->getSlug(), $product->getCategory()->getSlug(), $product->getCategory()->getName(),
            $product->getSubCategory()?->getSlug() ?? '', $product->getSubCategory()?->getName() ?? '',
            $product->getCity(), $product->getSeller()?->getName() ?? '',
        ]));

        return str_contains($haystack, $q);
    }

    /** @param list<Product> $items */
    private function sort(array &$items, string $sort): void
    {
        $sort = \in_array($sort, self::SORTS, true) ? $sort : 'newest';
        $name = fn (Product $p) => TextFolder::fold($p->getName());
        usort($items, fn (Product $a, Product $b) => match ($sort) {
            'name'       => [$name($a), $a->getId()] <=> [$name($b), $b->getId()],
            'stock_asc'  => [self::stockInfo($a)['total'], $name($a)] <=> [self::stockInfo($b)['total'], $name($b)],
            'price_asc'  => [$a->getPrice(), $name($a)] <=> [$b->getPrice(), $name($b)],
            'price_desc' => [$b->getPrice(), $name($a)] <=> [$a->getPrice(), $name($b)],
            default      => [$b->getCreatedAt(), $a->getId()] <=> [$a->getCreatedAt(), $b->getId()],
        });
    }

    private static function intParam(mixed $raw, int $default): int
    {
        return \is_string($raw) && preg_match('/^\d{1,6}\z/', $raw) === 1 ? (int) $raw : $default;
    }
}
