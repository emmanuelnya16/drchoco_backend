<?php

namespace App\Admin;

use App\Api\ApiException;
use App\Catalog\ShopCity;
use App\Catalog\TextFolder;
use App\Entity\Admin;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\SubCategory;
use App\Repository\AdminRepository;
use App\Repository\CategoryRepository;
use App\Repository\LookRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Création, modification, stocks et suppression des articles depuis l'administration (écran « Catalogue »).
 *
 * Qui peut quoi (contrôlé ici, pas seulement par le front) :
 *  - super admin : tous les articles ; choisit la ville, et éventuellement le vendeur (`sellerId`) ;
 *  - vendeur : ses articles et ceux de sa ville sans vendeur. Ce qu'il crée ou modifie lui est rattaché,
 *    dans sa ville (les `city` et `sellerId` envoyés sont ignorés).
 *
 * Les variantes gardent leur identifiant d'un enregistrement à l'autre (paniers et réservations s'en servent) :
 * elles sont reconnues par couleur et taille, jamais par l'identifiant fabriqué par le front.
 * Suppression : l'article est retiré de la vente (historique gardé), son adresse est libérée.
 */
final class ProductEditor
{
    public const MAX_COLORS = 20;
    public const MAX_SIZES = 30;
    public const MAX_PHOTOS = 20;
    public const MAX_STOCK = 99999;
    public const MAX_PRICE = 10_000_000;

    private const SIZE_GUIDE_TYPES = ['clothing', 'footwear', 'one-size'];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly CategoryRepository $categories,
        private readonly AdminRepository $admins,
        private readonly LookRepository $looks,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {}

    /** Article visible et modifiable par ce compte (les articles supprimés n'existent plus pour personne). */
    public static function canManage(Admin $admin, Product $product): bool
    {
        if (!$product->isActive()) {
            return false;
        }
        if ($admin->isSuperAdmin()) {
            return true;
        }
        $owner = $product->getSeller();

        return $owner !== null
            ? $owner->getId() === $admin->getId()
            : $product->getCity() === $admin->getCity();
    }

    /** @param array<string, mixed> $body */
    public function create(array $body, Admin $admin): Product
    {
        $errors = [];
        $values = $this->resolve($body, null, $errors);
        $this->throwIfInvalid($errors);
        $this->assertSlugFree($values['slug'], null);

        $product = new Product(CatalogInput::newId('prod'), $values['slug'], $values['name'], $values['category'], ShopCity::YAOUNDE);
        $product->setCreatedAt($this->now());
        $this->assign($product, $body, $admin, creating: true);
        $this->apply($product, $values);

        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    /** @param array<string, mixed> $body seuls les champs présents sont modifiés */
    public function update(Product $product, array $body, Admin $admin): Product
    {
        $errors = [];
        $values = $this->resolve($body, $product, $errors);
        $this->throwIfInvalid($errors);
        $oldSlug = $product->getSlug();
        $this->assertSlugFree($values['slug'], $product);

        $this->em->wrapInTransaction(function () use ($product, $body, $admin, $values, $oldSlug): void {
            $this->assign($product, $body, $admin, creating: false);
            $this->apply($product, $values);
            if ($values['slug'] !== $oldSlug) {
                $this->renameInLooks($oldSlug, $values['slug']);
            }
        });

        return $product;
    }

    /**
     * Stock rapide : `{ variants: [{ variantId, stock }] }`, valeurs absolues. Une variante inconnue est ignorée.
     *
     * @param array<string, mixed> $body
     */
    public function updateStock(Product $product, array $body): Product
    {
        if (!\is_array($body['variants'] ?? null) || !array_is_list($body['variants'])) {
            throw ApiException::validation(['variants' => 'Liste des variantes manquante.'], 'Liste des variantes manquante.');
        }

        $byId = [];
        foreach ($product->getVariants() as $variant) {
            $byId[$variant->getId()] = $variant;
        }
        $updates = [];
        foreach ($body['variants'] as $line) {
            $id = \is_array($line) && \is_string($line['variantId'] ?? null) ? $line['variantId'] : null;
            if ($id === null || !isset($byId[$id])) {
                continue;
            }
            $stock = $this->stockValue($line['stock'] ?? null);
            if ($stock === null) {
                throw ApiException::validation(['variants' => 'Stock invalide.'], sprintf('Stock invalide : un nombre entier entre 0 et %d.', self::MAX_STOCK));
            }
            $updates[$id] = $stock;
        }

        foreach ($updates as $id => $stock) {
            $byId[$id]->setStock($stock);
        }
        $this->em->flush();

        return $product;
    }

    /** Retire l'article de la vente : invisible partout, adresse libérée, retiré des looks. */
    public function delete(Product $product): void
    {
        $oldSlug = $product->getSlug();
        $this->em->wrapInTransaction(function () use ($product, $oldSlug): void {
            $product->setIsActive(false);
            $product->setSlug(mb_substr($oldSlug, 0, 140) . '-retire-' . bin2hex(random_bytes(3)));
            $this->renameInLooks($oldSlug, null);
        });
    }

    // ── Interne ───────────────────────────────────────────────────────────

    /**
     * Ville et vendeur de l'article selon le rôle (voir la description de la classe).
     *
     * @param array<string, mixed> $body
     */
    private function assign(Product $product, array $body, Admin $admin, bool $creating): void
    {
        if (!$admin->isSuperAdmin()) {
            $product->setSeller($admin);
            $product->setCity((string) $admin->getCity());

            return;
        }

        $seller = null;
        if (\is_string($body['sellerId'] ?? null) && $body['sellerId'] !== '') {
            $seller = $this->admins->findSeller($body['sellerId']);
        }
        if ($seller !== null) {
            $product->setSeller($seller);
        } elseif ($creating) {
            $product->setSeller(null);
        }

        // Un article qui a un vendeur est toujours dans la ville de ce vendeur (comme les mocks du front et
        // SellerManager, qui déplace les articles quand le vendeur change de ville) ; la ville choisie ne vaut que
        // pour un article sans vendeur.
        $owner = $product->getSeller();
        if ($owner !== null) {
            $product->setCity((string) $owner->getCity());

            return;
        }
        $city = \array_key_exists('city', $body) ? ShopCity::normalize(\is_string($body['city']) ? $body['city'] : null) : null;
        if ($city !== null) {
            $product->setCity($city);
        } elseif ($creating) {
            $product->setCity(ShopCity::YAOUNDE);
        }
    }

    /**
     * Valeurs finales : corps de la requête par-dessus l'article actuel (PATCH : un champ absent ne change pas).
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function resolve(array $body, ?Product $current, array &$errors): array
    {
        $has = fn (string $key) => \array_key_exists($key, $body);
        $values = [];

        // Nom et adresse
        $name = $has('name') ? CatalogInput::text($body['name']) : $current?->getName();
        if ($name === null || CatalogInput::length($name) < 2) {
            $errors['name'] = 'Veuillez saisir le nom du produit.';
        } elseif (CatalogInput::length($name) > 160) {
            $errors['name'] = 'Le nom ne doit pas dépasser 160 caractères.';
        }
        $values['name'] = (string) $name;

        $slug = $has('slug') ? CatalogInput::text($body['slug']) : $current?->getSlug();
        if (($slug === null || $slug === '') && $name !== null) {
            $slug = CatalogInput::slugify($name);
        }
        $slug = mb_strtolower((string) $slug);
        if ($slug === '' || preg_match(CatalogInput::SLUG_PATTERN, $slug) !== 1 || \strlen($slug) > 120) {
            $errors['slug'] = 'Le slug URL doit contenir des lettres minuscules, des chiffres et des tirets (120 caractères au plus).';
        }
        $values['slug'] = $slug;

        // Catégorie et sous-catégorie
        $category = $current?->getCategory();
        if ($has('categorySlug') || $current === null) {
            $categorySlug = \is_string($body['categorySlug'] ?? null) ? trim($body['categorySlug']) : '';
            $category = $categorySlug !== '' ? $this->categories->findOneBy(['slug' => $categorySlug]) : null;
            if ($category === null) {
                $errors['categorySlug'] = 'Choisissez une catégorie de la liste.';
            }
        }
        $values['category'] = $category;
        $values['subCategory'] = $this->subCategory($body, $current, $category);

        // Prix
        $price = $has('price') ? $body['price'] : $current?->getPrice();
        if ((\is_float($price) && floor($price) === $price) || (\is_string($price) && preg_match('/^\d{1,9}\z/', trim($price)) === 1)) {
            $price = (int) $price;
        }
        if (!\is_int($price) || $price <= 0) {
            $errors['price'] = 'Le prix en FCFA doit être supérieur à 0.';
        } elseif ($price > self::MAX_PRICE) {
            $errors['price'] = 'Prix trop élevé.';
        }
        $values['price'] = \is_int($price) ? $price : 0;

        // Textes de la fiche
        $description = $has('description') ? CatalogInput::longText($body['description']) : $current?->getDescription();
        $values['description'] = ($description === null || $description === '')
            ? $values['name'] . ' — pièce signature DR Choco L’Incompris.'
            : $description;
        if (CatalogInput::length($values['description']) > 5000) {
            $errors['description'] = 'La description ne doit pas dépasser 5 000 caractères.';
        }

        $composition = $has('composition') ? CatalogInput::text($body['composition']) : $current?->getComposition();
        $values['composition'] = ($composition === null || $composition === '') ? 'Sélection atelier DR Choco' : $composition;
        if (CatalogInput::length($values['composition']) > 255) {
            $errors['composition'] = 'La composition ne doit pas dépasser 255 caractères.';
        }

        $values['careInstructions'] = $has('careInstructions') || $current === null
            ? $this->careInstructions($body['careInstructions'] ?? null, $errors)
            : $current->getCareInstructions();

        $values['isNew'] = $has('isNew') ? (bool) $body['isNew'] : ($current?->isNew() ?? true);

        // Couleurs, tailles, variantes
        $values['colors'] = $has('colors') || $current === null ? $this->colors($body['colors'] ?? null, $errors) : $current->getColors();
        $values['sizes'] = $has('sizes') || $current === null ? $this->sizes($body['sizes'] ?? null, $errors) : $current->getSizes();
        $values['stocks'] = $this->stocks($body, $current, $errors);

        // Galerie et guide des tailles
        $colorCodes = array_column($values['colors'], 'code');
        $values['mediaGallery'] = $has('mediaGallery') || $current === null
            ? $this->gallery($body['mediaGallery'] ?? null, $colorCodes, $errors)
            : $this->keepKnownColors($current->getMediaGallery(), $colorCodes);
        $values['sizeGuide'] = $has('sizeGuide') || $current === null
            ? $this->sizeGuide($body['sizeGuide'] ?? null, $errors)
            : $current->getSizeGuide();

        return $values;
    }

    /** @param array<string, mixed> $values */
    private function apply(Product $product, array $values): void
    {
        $product->setName($values['name'])
            ->setSlug($values['slug'])
            ->setCategory($values['category'])
            ->setSubCategory($values['subCategory'])
            ->setPrice($values['price'])
            ->setDescription($values['description'])
            ->setComposition($values['composition'])
            ->setCareInstructions($values['careInstructions'])
            ->setIsNew($values['isNew'])
            ->setColors($values['colors'])
            ->setSizes($values['sizes'])
            ->setMediaGallery($values['mediaGallery'])
            ->setSizeGuide($values['sizeGuide']);

        $this->syncVariants($product, $values['colors'], $values['sizes'], $values['stocks']);
    }

    /**
     * Une variante par couleur × taille, dans l'ordre affiché. Les variantes existantes sont reconnues par
     * couleur et taille (sans tenir compte de la casse) et gardent leur identifiant ; les combinaisons
     * disparues sont supprimées ; les nouvelles reçoivent un identifiant stable.
     *
     * @param list<array{code: string}> $colors
     * @param list<string>              $sizes
     * @param array<string, int>|null   $stocks stock par clé « couleur|taille » ; null = garder le stock actuel
     */
    private function syncVariants(Product $product, array $colors, array $sizes, ?array $stocks): void
    {
        $existing = [];
        foreach ($product->getVariants()->toArray() as $variant) {
            $existing[self::variantKey($variant->getColorCode(), $variant->getSize())] = $variant;
        }

        $kept = [];
        $taken = array_fill_keys(array_map(fn (ProductVariant $v) => $v->getId(), $product->getVariants()->toArray()), true);
        $position = 0;
        foreach ($colors as $color) {
            foreach ($sizes as $size) {
                $key = self::variantKey($color['code'], $size);
                $variant = $existing[$key] ?? null;
                if ($variant === null) {
                    $variant = new ProductVariant($this->newVariantId($product, $color['code'], $size, $taken), $product, $color['code'], $size, 0);
                } else {
                    $variant->setColorCode($color['code'])->setSize($size);
                }
                if ($stocks !== null) {
                    $variant->setStock($stocks[$key] ?? 0);
                }
                $variant->setPosition($position++);
                $kept[$key] = true;
            }
        }

        foreach ($existing as $key => $variant) {
            if (!isset($kept[$key])) {
                $product->removeVariant($variant);
            }
        }
    }

    private static function variantKey(string $colorCode, string $size): string
    {
        return mb_strtolower($colorCode) . '|' . self::sizeKey($size);
    }

    /**
     * Clé de comparaison d'une taille : sans accents, casse ni espaces en trop, comme la base, qui compare
     * sans accents ni casse (« BÉBÉ » = « bebe ») : deux tailles de même clé sont une seule taille.
     */
    private static function sizeKey(string $size): string
    {
        return TextFolder::fold((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $size));
    }

    /** @param array<string, true> $taken identifiants déjà pris pour cet article (y compris dans cet enregistrement) */
    private function newVariantId(Product $product, string $colorCode, string $size, array &$taken): string
    {
        $id = $product->getId() . '-' . $colorCode . '-' . (CatalogInput::slugify($size) ?: 'taille');
        while (\strlen($id) > 80 || isset($taken[$id]) || $this->em->find(ProductVariant::class, $id) !== null) {
            $id = $product->getId() . '-v' . bin2hex(random_bytes(4));
        }
        $taken[$id] = true;

        return $id;
    }

    /**
     * Stocks envoyés, par couleur et taille (l'identifiant fabriqué par le front n'est pas utilisé).
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $errors
     *
     * @return array<string, int>|null null : variantes absentes du PATCH, stocks inchangés
     */
    private function stocks(array $body, ?Product $current, array &$errors): ?array
    {
        if (!\array_key_exists('variants', $body)) {
            if ($current === null) {
                return [];
            }

            return null;
        }
        if (!\is_array($body['variants']) || !array_is_list($body['variants'])) {
            $errors['variants'] = 'Stocks des variantes invalides.';

            return [];
        }

        $stocks = [];
        foreach ($body['variants'] as $line) {
            if (!\is_array($line) || !\is_string($line['colorCode'] ?? null) || !\is_string($line['size'] ?? null)) {
                continue;
            }
            $stock = $this->stockValue($line['stock'] ?? 0);
            if ($stock === null) {
                $errors['variants'] = sprintf('Stock invalide : un nombre entier entre 0 et %d.', self::MAX_STOCK);
                continue;
            }
            $stocks[self::variantKey(trim($line['colorCode']), CatalogInput::text($line['size']) ?? '')] = $stock;
        }

        return $stocks;
    }

    private function stockValue(mixed $raw): ?int
    {
        if (\is_float($raw) && floor($raw) === $raw) {
            $raw = (int) $raw;
        }
        if (\is_string($raw) && preg_match('/^\d{1,6}\z/', $raw) === 1) {
            $raw = (int) $raw;
        }

        return \is_int($raw) && $raw >= 0 && $raw <= self::MAX_STOCK ? $raw : null;
    }

    /**
     * Sous-catégorie de la catégorie choisie. Une sous-catégorie qui n'appartient pas à la catégorie est ignorée :
     * le front garde l'ancienne quand la nouvelle catégorie n'en a aucune.
     *
     * @param array<string, mixed> $body
     */
    private function subCategory(array $body, ?Product $current, ?Category $category): ?SubCategory
    {
        if ($category === null) {
            return null;
        }
        $slug = \array_key_exists('subCategorySlug', $body)
            ? (\is_string($body['subCategorySlug']) ? trim($body['subCategorySlug']) : '')
            : $current?->getSubCategory()?->getSlug();

        foreach ($category->getSubCategories() as $sub) {
            if ($slug !== null && $slug !== '' && $sub->getSlug() === $slug) {
                return $sub;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return list<string>
     */
    private function careInstructions(mixed $raw, array &$errors): array
    {
        $lines = [];
        foreach (\is_array($raw) ? $raw : [] as $line) {
            $text = CatalogInput::text($line);
            if ($text !== null && $text !== '') {
                $lines[] = mb_substr($text, 0, 300);
            }
        }
        if (\count($lines) > 20) {
            $errors['careInstructions'] = 'Vingt conseils d’entretien au plus.';
        }

        return $lines !== [] ? \array_slice($lines, 0, 20) : ['Entretien délicat recommandé par l’atelier DR Choco.'];
    }

    /**
     * @param array<string, string> $errors
     *
     * @return list<array{code: string, name: string, hex: string}>
     */
    private function colors(mixed $raw, array &$errors): array
    {
        $colors = [];
        $seen = [];
        foreach (\is_array($raw) ? $raw : [] as $color) {
            $code = \is_array($color) && \is_string($color['code'] ?? null) ? mb_strtolower(trim($color['code'])) : '';
            $name = \is_array($color) ? CatalogInput::text($color['name'] ?? null) : null;
            $hex = \is_array($color) && \is_string($color['hex'] ?? null) ? strtoupper(trim($color['hex'])) : '';
            if (\strlen($code) > 40) {
                // Le front fabrique le code à partir du nom : un nom trop long donne un code trop long.
                $errors['colors'] = sprintf('Nom de la couleur « %s » trop long (40 caractères au plus).', $name ?? $code);
                continue;
            }
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*\z/', $code) !== 1) {
                $errors['colors'] = sprintf('Code couleur « %s » invalide.', $code);
                continue;
            }
            if ($name === null || $name === '' || CatalogInput::length($name) > 60) {
                $errors['colors'] = sprintf('Nom de la couleur « %s » invalide.', $code);
                continue;
            }
            if (preg_match('/^#[0-9A-F]{6}\z/', $hex) !== 1) {
                $errors['colors'] = sprintf('Teinte de la couleur « %s » invalide (#RRGGBB).', $name);
                continue;
            }
            if (isset($seen[$code])) {
                $errors['colors'] = sprintf('La couleur « %s » est en double.', $name);
                continue;
            }
            $seen[$code] = true;
            $colors[] = ['code' => $code, 'name' => $name, 'hex' => $hex];
        }

        if ($colors === [] && !isset($errors['colors'])) {
            $errors['colors'] = 'Ajoutez au moins une couleur.';
        } elseif (\count($colors) > self::MAX_COLORS) {
            $errors['colors'] = sprintf('%d couleurs au plus.', self::MAX_COLORS);
        }

        return $colors;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return list<string>
     */
    private function sizes(mixed $raw, array &$errors): array
    {
        $sizes = [];
        $seen = [];
        foreach (\is_array($raw) ? $raw : [] as $size) {
            $size = CatalogInput::text($size);
            if ($size === null || $size === '' || CatalogInput::length($size) > 20) {
                $errors['sizes'] = 'Taille invalide (20 caractères au plus).';
                continue;
            }
            $key = self::sizeKey($size);
            if (isset($seen[$key])) {
                $errors['sizes'] = sprintf('La taille « %s » est en double.', $size);
                continue;
            }
            $seen[$key] = true;
            $sizes[] = $size;
        }

        if ($sizes === [] && !isset($errors['sizes'])) {
            $errors['sizes'] = 'Ajoutez au moins une taille.';
        } elseif (\count($sizes) > self::MAX_SIZES) {
            $errors['sizes'] = sprintf('%d tailles au plus.', self::MAX_SIZES);
        }

        return $sizes;
    }

    /**
     * Galerie : la 1re photo sert de couverture. Une couleur qui n'existe plus est retirée de la photo
     * (le front ne la retire pas quand on supprime une couleur).
     *
     * @param list<string>          $colorCodes
     * @param array<string, string> $errors
     *
     * @return list<array<string, string>>
     */
    private function gallery(mixed $raw, array $colorCodes, array &$errors): array
    {
        $gallery = [];
        foreach (\is_array($raw) ? $raw : [] as $index => $entry) {
            $url = \is_array($entry) && \is_string($entry['url'] ?? null) ? trim($entry['url']) : '';
            if (!CatalogInput::isImageUrl($url)) {
                $errors['mediaGallery'] = CatalogInput::imageError($url, sprintf('La photo n°%d', $index + 1));
                continue;
            }
            $fallback = \is_array($entry) && \is_string($entry['fallbackUrl'] ?? null) ? trim($entry['fallbackUrl']) : '';
            $item = ['url' => $url, 'fallbackUrl' => CatalogInput::isImageUrl($fallback) ? $fallback : $url];
            $colorCode = \is_array($entry) && \is_string($entry['colorCode'] ?? null) ? mb_strtolower(trim($entry['colorCode'])) : '';
            if ($colorCode !== '' && \in_array($colorCode, $colorCodes, true)) {
                $item['colorCode'] = $colorCode;
            }
            $alt = \is_array($entry) ? CatalogInput::text($entry['alt'] ?? null) : null;
            if ($alt !== null && $alt !== '') {
                $item['alt'] = mb_substr($alt, 0, 200);
            }
            $gallery[] = $item;
        }

        if ($gallery === [] && !isset($errors['mediaGallery'])) {
            $errors['mediaGallery'] = 'Ajoutez au moins une photo à la galerie.';
        } elseif (\count($gallery) > self::MAX_PHOTOS) {
            $errors['mediaGallery'] = sprintf('%d photos au plus.', self::MAX_PHOTOS);
        }

        return $gallery;
    }

    /**
     * @param list<array<string, mixed>> $gallery
     * @param list<string>               $colorCodes
     *
     * @return list<array<string, mixed>>
     */
    private function keepKnownColors(array $gallery, array $colorCodes): array
    {
        return array_map(function (array $entry) use ($colorCodes) {
            if (isset($entry['colorCode']) && !\in_array($entry['colorCode'], $colorCodes, true)) {
                unset($entry['colorCode']);
            }

            return $entry;
        }, $gallery);
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array{type: string, title: string, tip: string, headers: list<string>, rows: list<list<string>>}
     */
    private function sizeGuide(mixed $raw, array &$errors): array
    {
        if (!\is_array($raw)) {
            return ['type' => 'one-size', 'title' => 'Guide des tailles', 'tip' => '', 'headers' => [], 'rows' => []];
        }
        $type = \is_string($raw['type'] ?? null) ? $raw['type'] : '';
        if (!\in_array($type, self::SIZE_GUIDE_TYPES, true)) {
            $errors['sizeGuide'] = 'Type de guide des tailles invalide.';
        }
        $cells = function (mixed $list, int $max) use (&$errors): array {
            $out = [];
            foreach (\is_array($list) ? array_values($list) : [] as $cell) {
                if (!\is_string($cell) && !\is_int($cell) && !\is_float($cell)) {
                    $errors['sizeGuide'] = 'Guide des tailles invalide.';
                    continue;
                }
                $out[] = mb_substr(trim((string) $cell), 0, 80);
            }

            return \array_slice($out, 0, $max);
        };
        $rows = [];
        foreach (\is_array($raw['rows'] ?? null) ? \array_slice(array_values($raw['rows']), 0, 40) : [] as $row) {
            $rows[] = $cells($row, 10);
        }

        return [
            'type'    => $type,
            'title'   => mb_substr((string) (CatalogInput::text($raw['title'] ?? '') ?? ''), 0, 120),
            'tip'     => mb_substr((string) (CatalogInput::text($raw['tip'] ?? '') ?? ''), 0, 300),
            'headers' => $cells($raw['headers'] ?? [], 10),
            'rows'    => $rows,
        ];
    }

    private function assertSlugFree(string $slug, ?Product $except): void
    {
        $other = $this->products->findOneBy(['slug' => $slug]);
        if ($other !== null && $other !== $except) {
            throw new ApiException(409, 'DUPLICATE_SLUG', sprintf('Un article utilise déjà le slug « %s ».', $slug), ['slug' => $slug]);
        }
    }

    /** Remplace (ou retire, si $newSlug est null) l'adresse d'un article dans tous les looks. */
    private function renameInLooks(string $oldSlug, ?string $newSlug): void
    {
        foreach ($this->looks->findAll() as $look) {
            $slugs = $look->getProductSlugs();
            if (!\in_array($oldSlug, $slugs, true)) {
                continue;
            }
            $updated = [];
            foreach ($slugs as $slug) {
                if ($slug !== $oldSlug) {
                    $updated[] = $slug;
                } elseif ($newSlug !== null) {
                    $updated[] = $newSlug;
                }
            }
            $look->setProductSlugs($updated);
        }
    }

    /** @param array<string, string> $errors */
    private function throwIfInvalid(array $errors): void
    {
        if ($errors !== []) {
            // Le front affiche le message tel quel : le premier problème, en clair.
            throw ApiException::validation($errors, reset($errors));
        }
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
    }
}
