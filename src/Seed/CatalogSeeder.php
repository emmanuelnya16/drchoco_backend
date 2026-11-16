<?php

namespace App\Seed;

use App\Api\ApiDate;
use App\Entity\Agency;
use App\Entity\AppSetting;
use App\Entity\Category;
use App\Entity\ContentPage;
use App\Entity\DeliveryCity;
use App\Entity\Look;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\SubCategory;
use App\Settings\PublicSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Remplit la base avec les données de départ de data/seed/*.json, exportées des mocks du front
 * (tools/export-front-fixtures.ts) : mêmes catégories, articles, looks, villes, agences, pages et réglages.
 *
 * Sans danger à relancer : un élément déjà présent (même identifiant) est laissé tel quel,
 * sauf avec $update = true (il reprend alors les valeurs du fichier). Rien n'est jamais supprimé.
 */
final class CatalogSeeder
{
    public const SECTIONS = ['categories', 'products', 'looks', 'delivery', 'pages', 'settings'];

    private const PAGE_KINDS = [
        'conditions-generales'  => ContentPage::KIND_LEGAL,
        'annulation-retours'    => ContentPage::KIND_LEGAL,
        'confidentialite'       => ContentPage::KIND_LEGAL,
        'mentions-legales'      => ContentPage::KIND_LEGAL,
        'questions-frequentes'  => ContentPage::KIND_FAQ,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PublicSettingsProvider $settings,
        #[Autowire('%kernel.project_dir%/data/seed')]
        private readonly string $seedDir,
    ) {}

    /**
     * @param list<string> $sections parties à charger (toutes par défaut)
     *
     * @return array<string, array{created: int, updated: int, skipped: int}>
     */
    public function seed(array $sections = self::SECTIONS, bool $update = false): array
    {
        $report = [];

        // Ordre imposé : les articles ont besoin des catégories, les agences des villes.
        foreach (self::SECTIONS as $section) {
            if (!\in_array($section, $sections, true)) {
                continue;
            }
            $report[$section] = match ($section) {
                'categories' => $this->seedCategories($update),
                'products'   => $this->seedProducts($update),
                'looks'      => $this->seedLooks($update),
                'delivery'   => $this->seedDelivery($update),
                'pages'      => $this->seedPages($update),
                'settings'   => $this->seedSettings($update),
            };
            $this->em->flush();
            $this->em->clear();
        }

        return $report;
    }

    /** @return array{created: int, updated: int, skipped: int} */
    private function seedCategories(bool $update): array
    {
        $count = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($this->read('categories.json') as $position => $row) {
            $category = $this->em->find(Category::class, $row['id']);
            if ($category !== null && !$update) {
                ++$count['skipped'];
                continue;
            }
            $count[$category === null ? 'created' : 'updated']++;
            $category ??= new Category($row['id'], $row['slug'], $row['name']);
            $category->setSlug($row['slug'])
                ->setName($row['name'])
                ->setDescription($row['description'] ?? '')
                ->setImage($row['image'] ?? '')
                ->setCoverImage($row['coverImage'] ?? '')
                ->setFallbackImage($row['fallbackImage'] ?? '')
                ->setPosition($position)
                ->setIsActive(true);
            $this->em->persist($category);

            foreach ($row['subCategories'] ?? [] as $subPosition => $subRow) {
                $sub = $this->em->find(SubCategory::class, $subRow['id'])
                    ?? new SubCategory($subRow['id'], $category, $subRow['slug'], $subRow['name']);
                $sub->setSlug($subRow['slug'])
                    ->setName($subRow['name'])
                    ->setCoverImage($subRow['coverImage'] ?? '')
                    ->setFallbackImage($subRow['fallbackImage'] ?? '')
                    ->setPosition($subPosition);
                $this->em->persist($sub);
            }
        }

        return $count;
    }

    /** @return array{created: int, updated: int, skipped: int} */
    private function seedProducts(bool $update): array
    {
        $count = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $categories = [];
        foreach ($this->em->getRepository(Category::class)->findAll() as $c) {
            $categories[$c->getSlug()] = $c;
        }
        $subCategories = [];
        foreach ($this->em->getRepository(SubCategory::class)->findAll() as $s) {
            $subCategories[$s->getSlug()] = $s;
        }

        foreach ($this->read('products.json') as $row) {
            $category = $categories[$row['categorySlug']] ?? throw new \RuntimeException(sprintf('Catégorie « %s » absente : chargez d\'abord les catégories.', $row['categorySlug']));

            $product = $this->em->find(Product::class, $row['id']);
            if ($product !== null && !$update) {
                ++$count['skipped'];
                continue;
            }
            $count[$product === null ? 'created' : 'updated']++;
            $product ??= new Product($row['id'], $row['slug'], $row['name'], $category, $row['city']);

            $product->setSlug($row['slug'])
                ->setName($row['name'])
                ->setCategory($category)
                ->setSubCategory(isset($row['subCategorySlug']) ? ($subCategories[$row['subCategorySlug']] ?? null) : null)
                ->setCity($row['city'])
                ->setDescription($row['description'] ?? '')
                ->setComposition($row['composition'] ?? '')
                ->setCareInstructions($row['careInstructions'] ?? [])
                ->setSizeGuide($row['sizeGuide'] ?? [])
                ->setPrice((int) $row['price'])
                ->setMediaGallery($row['mediaGallery'] ?? [])
                ->setColors($row['colors'] ?? [])
                ->setSizes($row['sizes'] ?? [])
                ->setIsNew((bool) ($row['isNew'] ?? false))
                ->setReservationsCount((int) ($row['reservationsCount'] ?? 0))
                ->setCreatedAt(ApiDate::parse($row['createdAt']))
                ->setIsActive(true);

            $existing = [];
            foreach ($product->getVariants() as $variant) {
                $existing[$variant->getId()] = $variant;
            }
            foreach ($row['variants'] ?? [] as $position => $v) {
                $variant = $existing[$v['id']] ?? new ProductVariant($v['id'], $product, $v['colorCode'], $v['size']);
                $variant->setColorCode($v['colorCode'])
                    ->setSize($v['size'])
                    ->setStock((int) $v['stock'])
                    ->setPosition($position);
            }
            $this->em->persist($product);
        }

        return $count;
    }

    /** @return array{created: int, updated: int, skipped: int} */
    private function seedLooks(bool $update): array
    {
        $count = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($this->read('looks.json') as $position => $row) {
            $look = $this->em->find(Look::class, $row['id']);
            if ($look !== null && !$update) {
                ++$count['skipped'];
                continue;
            }
            $count[$look === null ? 'created' : 'updated']++;
            $look ??= new Look($row['id'], $row['title']);
            $look->setTitle($row['title'])
                ->setSubtitle($row['subtitle'] ?? '')
                ->setImage($row['image'] ?? '')
                ->setFallback($row['fallback'] ?? '')
                ->setProductSlugs($row['productSlugs'] ?? [])
                ->setPosition($position)
                ->setIsActive(true);
            $this->em->persist($look);
        }

        return $count;
    }

    /** @return array{created: int, updated: int, skipped: int} */
    private function seedDelivery(bool $update): array
    {
        $count = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $cities = [];

        foreach ($this->read('delivery-cities.json') as $position => $row) {
            $city = $this->em->find(DeliveryCity::class, $row['id']);
            $cities[$row['id']] = $city;
            if ($city !== null && !$update) {
                ++$count['skipped'];
                continue;
            }
            $count[$city === null ? 'created' : 'updated']++;
            $city ??= new DeliveryCity($row['id'], $row['name']);
            $city->setName($row['name'])
                ->setFee((int) $row['fee'])
                ->setIsLocal((bool) $row['isLocal'])
                ->setHasAgencies((bool) $row['hasAgencies'])
                ->setEstimatedDelay($row['estimatedDelay'] ?? '')
                ->setPosition($position)
                ->setIsActive(true);
            $this->em->persist($city);
            $cities[$row['id']] = $city;
        }

        $positions = [];
        foreach ($this->read('agencies.json') as $row) {
            $city = $cities[$row['cityId']] ?? $this->em->find(DeliveryCity::class, $row['cityId']);
            if ($city === null) {
                continue;
            }
            $agency = $this->em->find(Agency::class, $row['id']);
            $positions[$row['cityId']] = ($positions[$row['cityId']] ?? -1) + 1;
            if ($agency !== null && !$update) {
                ++$count['skipped'];
                continue;
            }
            $count[$agency === null ? 'created' : 'updated']++;
            $agency ??= new Agency($row['id'], $city, $row['name']);
            $agency->setCity($city)
                ->setName($row['name'])
                ->setAddress($row['address'] ?? '')
                ->setPhone($row['phone'] ?? '')
                ->setOpeningHours($row['openingHours'] ?? '')
                // Prix de l'agence : celui du fichier, sinon le prix actuel de sa ville.
                ->setFee(\is_int($row['fee'] ?? null) ? $row['fee'] : $city->getFee())
                ->setDepartureCity(\App\Catalog\ShopCity::normalize(\is_string($row['departureCity'] ?? null) ? $row['departureCity'] : null))
                ->setPosition($positions[$row['cityId']])
                ->setIsActive(true);
            $this->em->persist($agency);
        }

        return $count;
    }

    /** @return array{created: int, updated: int, skipped: int} */
    private function seedPages(bool $update): array
    {
        $count = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($this->read('pages.json') as $row) {
            $page = $this->em->find(ContentPage::class, $row['slug']);
            if ($page !== null && !$update) {
                ++$count['skipped'];
                continue;
            }
            $count[$page === null ? 'created' : 'updated']++;
            $page ??= new ContentPage($row['slug'], $row['title']);
            $page->setTitle($row['title'])
                ->setKind(self::PAGE_KINDS[$row['slug']] ?? ContentPage::KIND_HELP)
                ->setBody($row['body'])
                ->setIsPublished(true)
                ->setUpdatedAt(ApiDate::parse($row['updatedAt']));
            $this->em->persist($page);
        }

        return $count;
    }

    /** @return array{created: int, updated: int, skipped: int} */
    private function seedSettings(bool $update): array
    {
        $setting = $this->em->find(AppSetting::class, AppSetting::PUBLIC);
        if ($setting !== null && !$update) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 1];
        }
        $created = $setting === null;
        $setting ??= new AppSetting(AppSetting::PUBLIC);
        $setting->setValue($this->settings->defaults());
        $this->em->persist($setting);

        return ['created' => $created ? 1 : 0, 'updated' => $created ? 0 : 1, 'skipped' => 0];
    }

    /** @return list<array<string, mixed>> */
    private function read(string $file): array
    {
        $path = $this->seedDir . '/' . $file;
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Fichier de données introuvable : %s', $path));
        }

        return json_decode((string) file_get_contents($path), true, 64, \JSON_THROW_ON_ERROR);
    }
}
