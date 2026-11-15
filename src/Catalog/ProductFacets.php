<?php

namespace App\Catalog;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;

/**
 * Facettes de GET /products (contrat §6.2) : tailles, tranches de prix et sous-catégories,
 * avec le nombre d'articles, calculées sur la ville et la catégorie demandées.
 */
final class ProductFacets
{
    private const CLOTHING_SIZES = ['S', 'M', 'L', 'XL', 'XXL', 'Unique'];
    private const FOOTWEAR_SIZES = ['39', '40', '41', '42', '43', '44', '45', '46'];
    private const ALL_SIZES = ['S', 'M', 'L', 'XL', 'XXL', '39', '40', '41', '42', '43', '44', '45', '46', 'Unique'];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly CategoryRepository $categories,
    ) {}

    /** @return array{sizes: list<array{value: string, count: int}>, priceRanges: list<array{value: string, label: string, count: int}>, subCategories: list<array{slug: string, name: string, count: int}>} */
    public function compute(ProductFilters $filters): array
    {
        $counts = $this->products->facetCounts($filters);
        $visibleCategories = $this->categories->findVisible();
        $category = $this->relevantCategory($visibleCategories, $filters->category);

        // Tailles de la catégorie (comme les mocks), plus toute autre taille réellement en stock.
        $base = match ($category?->getSlug()) {
            'vetements' => self::CLOTHING_SIZES,
            'chaussures', 'babouches' => self::FOOTWEAR_SIZES,
            null => self::ALL_SIZES,
            default => [],
        };
        $known = array_map('mb_strtoupper', $base);
        foreach (array_keys($counts['sizes']) as $upper) {
            $upper = (string) $upper; // PHP range « 39 » en clé entière
            if (!\in_array($upper, $known, true)) {
                $base[] = $this->displaySize($upper);
                $known[] = $upper;
            }
        }
        $sizes = array_map(fn (string $size) => [
            'value' => $size,
            'count' => $counts['sizes'][mb_strtoupper($size)] ?? 0,
        ], $base);

        $priceRanges = [];
        foreach (ProductFilters::PRICE_RANGES as $value => $label) {
            $priceRanges[] = [
                'value' => $value,
                'label' => $label,
                'count' => $counts['prices'][$value] ?? 0,
            ];
        }

        $subCategories = [];
        foreach ($category !== null ? [$category] : $visibleCategories as $cat) {
            foreach ($cat->getSubCategories() as $sub) {
                $subCategories[] = [
                    'slug'  => $sub->getSlug(),
                    'name'  => $sub->getName(),
                    'count' => $counts['subCategories'][$sub->getId()] ?? 0,
                ];
            }
        }

        return ['sizes' => array_values($sizes), 'priceRanges' => $priceRanges, 'subCategories' => $subCategories];
    }

    /** @param list<Category> $categories */
    private function relevantCategory(array $categories, ?string $slug): ?Category
    {
        if ($slug === null) {
            return null;
        }
        foreach ($categories as $category) {
            if ($category->getSlug() === $slug) {
                return $category;
            }
            foreach ($category->getSubCategories() as $sub) {
                if ($sub->getSlug() === $slug) {
                    return $category;
                }
            }
        }

        return null;
    }

    private function displaySize(string $upper): string
    {
        return $upper === 'UNIQUE' ? 'Unique' : $upper;
    }
}
