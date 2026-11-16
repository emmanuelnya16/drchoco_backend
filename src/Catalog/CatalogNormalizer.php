<?php

namespace App\Catalog;

use App\Api\ApiDate;
use App\Entity\Agency;
use App\Entity\Category;
use App\Entity\ContentPage;
use App\Entity\DeliveryCity;
use App\Entity\Look;
use App\Entity\Product;
use App\Entity\SubCategory;

/**
 * Mise en forme des données publiques exactement comme le front les lit (contrat, annexe A).
 *
 * Règle du contrat (§1.3) : un champ facultatif sans valeur est omis, jamais renvoyé à null.
 * Les informations internes (vendeur, article retiré, position…) ne sortent jamais ici.
 */
final class CatalogNormalizer
{
    /** Repli si un article n'a encore aucune photo (même visuel que les mocks). */
    private const DEFAULT_FALLBACK_IMAGE = '/mock/product-tee.svg';

    /** @return array<string, mixed> objet Product complet (cartes, listes et fiche) */
    public function product(Product $p): array
    {
        $gallery = [];
        foreach ($p->getMediaGallery() as $entry) {
            $item = [
                'url'         => (string) ($entry['url'] ?? ''),
                'fallbackUrl' => (string) ($entry['fallbackUrl'] ?? self::DEFAULT_FALLBACK_IMAGE),
            ];
            if (($entry['colorCode'] ?? '') !== '') {
                $item['colorCode'] = (string) $entry['colorCode'];
            }
            if (($entry['alt'] ?? '') !== '') {
                $item['alt'] = (string) $entry['alt'];
            }
            $gallery[] = $item;
        }

        $variants = [];
        foreach ($p->getVariants() as $variant) {
            $variants[] = [
                'id'        => $variant->getId(),
                'size'      => $variant->getSize(),
                'colorCode' => $variant->getColorCode(),
                'stock'     => $variant->getStock(),
            ];
        }

        $data = [
            'id'               => $p->getId(),
            'slug'             => $p->getSlug(),
            'name'             => $p->getName(),
            'description'      => $p->getDescription(),
            'composition'      => $p->getComposition(),
            'careInstructions' => $p->getCareInstructions(),
            'sizeGuide'        => $this->sizeGuide($p->getSizeGuide()),
            'categorySlug'     => $p->getCategory()->getSlug(),
        ];
        if ($p->getSubCategory() !== null) {
            $data['subCategorySlug'] = $p->getSubCategory()->getSlug();
        }

        return $data + [
            'price'             => $p->getPrice(),
            'images'            => array_column($gallery, 'url'),
            'fallbackImages'    => array_column($gallery, 'fallbackUrl'),
            'mediaGallery'      => $gallery,
            'colors'            => array_map(fn (array $c) => [
                'code' => (string) $c['code'],
                'name' => (string) $c['name'],
                'hex'  => (string) $c['hex'],
            ], $p->getColors()),
            'sizes'             => $p->getSizes(),
            'variants'          => $variants,
            'isNew'             => $p->isNew(),
            'reservationsCount' => $p->getReservationsCount(),
            'badges'            => $p->getBadges(),
            'createdAt'         => ApiDate::format($p->getCreatedAt()),
            'updatedAt'         => ApiDate::format($p->getUpdatedAt() ?? $p->getCreatedAt()),
            'city'              => $p->getCity(),
        ];
    }

    /** @return array<string, mixed> article réduit des suggestions de recherche (§6.4) */
    public function suggestion(Product $p): array
    {
        $gallery = $p->getMediaGallery();
        $fallback = (string) ($gallery[0]['fallbackUrl'] ?? self::DEFAULT_FALLBACK_IMAGE);

        return [
            'id'            => $p->getId(),
            'slug'          => $p->getSlug(),
            'name'          => $p->getName(),
            'price'         => $p->getPrice(),
            'image'         => (string) ($gallery[0]['url'] ?? $fallback),
            'fallbackImage' => $fallback,
            'categorySlug'  => $p->getCategory()->getSlug(),
            'totalStock'    => $p->getTotalStock(),
            'city'          => $p->getCity(),
        ];
    }

    /**
     * @param array<string, int> $categoryCounts    articles visibles par catégorie
     * @param array<string, int> $subCategoryCounts articles visibles par sous-catégorie
     *
     * @return array<string, mixed>
     */
    public function category(Category $c, array $categoryCounts, array $subCategoryCounts): array
    {
        return [
            'id'            => $c->getId(),
            'slug'          => $c->getSlug(),
            'name'          => $c->getName(),
            'description'   => $c->getDescription(),
            'image'         => $c->getImage(),
            'coverImage'    => $c->getCoverImage(),
            'fallbackImage' => $c->getFallbackImage(),
            'productCount'  => $categoryCounts[$c->getId()] ?? 0,
            'subCategories' => array_values(array_map(
                fn (SubCategory $s) => $this->subCategory($s, $subCategoryCounts),
                $c->getSubCategories()->toArray(),
            )),
        ];
    }

    /**
     * @param array<string, int> $subCategoryCounts
     *
     * @return array<string, mixed>
     */
    public function subCategory(SubCategory $s, array $subCategoryCounts): array
    {
        return [
            'id'            => $s->getId(),
            'slug'          => $s->getSlug(),
            'name'          => $s->getName(),
            'coverImage'    => $s->getCoverImage(),
            'fallbackImage' => $s->getFallbackImage(),
            'productCount'  => $subCategoryCounts[$s->getId()] ?? 0,
        ];
    }

    /**
     * @param array<string, true> $visibleSlugs articles encore en vente (les autres sont retirés du look)
     *
     * @return array<string, mixed>
     */
    public function look(Look $l, array $visibleSlugs): array
    {
        return [
            'id'           => $l->getId(),
            'title'        => $l->getTitle(),
            'subtitle'     => $l->getSubtitle(),
            'image'        => $l->getImage(),
            'fallback'     => $l->getFallback(),
            'productSlugs' => array_values(array_filter(
                $l->getProductSlugs(),
                fn (string $slug) => isset($visibleSlugs[$slug]),
            )),
        ];
    }

    /** @return array<string, mixed> */
    public function deliveryCity(DeliveryCity $c): array
    {
        return [
            'id'             => $c->getId(),
            'name'           => $c->getName(),
            'fee'            => $c->getFee(),
            'isLocal'        => $c->isLocal(),
            'hasAgencies'    => $c->hasAgencies(),
            'estimatedDelay' => $c->getEstimatedDelay(),
        ];
    }

    /** @return array<string, mixed> */
    public function agency(Agency $a): array
    {
        return [
            'id'           => $a->getId(),
            'cityId'       => $a->getCity()->getId(),
            'city'         => $a->getCity()->getName(),
            'name'         => $a->getName(),
            'address'      => $a->getAddress(),
            'phone'        => $a->getPhone(),
            'openingHours' => $a->getOpeningHours(),
            'fee'           => $a->effectiveFee(),
            'departureCity' => $a->getDepartureCity(),
        ];
    }

    /** @return array<string, mixed> */
    public function page(ContentPage $p): array
    {
        return [
            'slug'      => $p->getSlug(),
            'title'     => $p->getTitle(),
            'updatedAt' => ApiDate::format($p->getUpdatedAt()),
            'body'      => $p->getBody(),
        ];
    }

    /**
     * Guide des tailles : toujours les 5 champs attendus par la fiche.
     *
     * @param array<string, mixed> $guide
     *
     * @return array<string, mixed>
     */
    private function sizeGuide(array $guide): array
    {
        $type = \in_array($guide['type'] ?? null, ['clothing', 'footwear', 'one-size'], true) ? $guide['type'] : 'one-size';

        return [
            'type'    => $type,
            'title'   => (string) ($guide['title'] ?? ''),
            'tip'     => (string) ($guide['tip'] ?? ''),
            'headers' => array_values(array_map('strval', (array) ($guide['headers'] ?? []))),
            'rows'    => array_values(array_map(
                fn ($row) => array_values(array_map('strval', (array) $row)),
                (array) ($guide['rows'] ?? []),
            )),
        ];
    }
}
