<?php

namespace App\Admin;

use App\Api\ApiException;
use App\Entity\Category;
use App\Entity\SubCategory;
use App\Repository\CategoryRepository;
use App\Repository\SubCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Catégories et sous-catégories (écran « Looks & Catégories »), modifiables par toute l'équipe comme le prévoit
 * le front (« En tant que vendeur ou Super Admin, créez une nouvelle catégorie… »).
 *
 * - L'adresse (slug) d'une catégorie ne change jamais : le front et le site public s'y réfèrent.
 * - Les sous-catégories sont envoyées en entier à chaque enregistrement : reconnues par leur slug, ajoutées,
 *   renommées ou retirées. Une sous-catégorie retirée laisse ses articles dans la catégorie (sans sous-catégorie).
 * - Un slug de sous-catégorie est unique dans toute la boutique.
 */
final class CategoryEditor
{
    public const DEFAULT_COVER = '/images/categories/vetements.webp';
    public const DEFAULT_FALLBACK = '/images/fallback/mood-vetements.svg';
    public const MAX_SUBCATEGORIES = 40;

    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly SubCategoryRepository $subCategories,
        private readonly EntityManagerInterface $em,
    ) {}

    public function find(string $idOrSlug): ?Category
    {
        if (\strlen($idOrSlug) > 80) {
            return null;
        }

        return $this->categories->find($idOrSlug) ?? $this->categories->findOneBy(['slug' => $idOrSlug]);
    }

    /** @param array<string, mixed> $body */
    public function create(array $body): Category
    {
        $errors = [];
        $name = $this->name($body['name'] ?? null, $errors);
        $rawSlug = \is_string($body['slug'] ?? null) && trim($body['slug']) !== '' ? $body['slug'] : $name;
        $slug = CatalogInput::slugify($rawSlug);
        if ($slug === '' || \strlen($slug) > 80) {
            $errors['slug'] = 'Identifiant (slug) de catégorie invalide.';
        }
        $description = $this->description($body['description'] ?? null, $errors);
        $cover = $this->image($body['coverImage'] ?? null, self::DEFAULT_COVER, 'L’image de couverture', $errors);
        $this->throwIfInvalid($errors);

        if ($this->categories->findOneBy(['slug' => $slug]) !== null) {
            throw new ApiException(409, 'DUPLICATE_CATEGORY', sprintf('La catégorie « %s » existe déjà.', $slug));
        }
        // Le filtre du site (?category=) accepte une catégorie ou une sous-catégorie : jamais le même slug.
        if ($this->subCategories->findOneBy(['slug' => $slug]) !== null) {
            throw new ApiException(409, 'DUPLICATE_CATEGORY', sprintf('« %s » est déjà le nom d’une sous-catégorie.', $slug));
        }

        $id = 'cat-' . $slug;
        if (\strlen($id) > 40 || $this->categories->find($id) !== null) {
            $id = CatalogInput::newId('cat');
        }

        $category = (new Category($id, $slug, $name))
            ->setDescription($description !== '' ? $description : sprintf('Sélection %s signée DR Choco L’Incompris.', $name))
            ->setCoverImage($cover)
            ->setImage($cover)
            ->setFallbackImage(self::DEFAULT_FALLBACK)
            ->setPosition($this->nextPosition());

        $this->em->wrapInTransaction(function () use ($category, $body): void {
            $this->em->persist($category);
            if (\is_array($body['subCategories'] ?? null)) {
                $this->syncSubCategories($category, $body['subCategories']);
            }
        });

        return $category;
    }

    /** @param array<string, mixed> $body seuls les champs présents sont modifiés */
    public function update(Category $category, array $body): Category
    {
        $errors = [];
        $name = \array_key_exists('name', $body) ? $this->name($body['name'], $errors) : null;
        $description = \array_key_exists('description', $body) ? $this->description($body['description'], $errors) : null;
        $cover = null;
        if (\array_key_exists('coverImage', $body) && \is_string($body['coverImage']) && trim($body['coverImage']) !== '') {
            $cover = $this->image($body['coverImage'], $category->getCoverImage(), 'L’image de couverture', $errors);
        }
        $this->throwIfInvalid($errors);

        $this->em->wrapInTransaction(function () use ($category, $body, $name, $description, $cover): void {
            if ($name !== null) {
                $category->setName($name);
            }
            if ($description !== null) {
                $category->setDescription($description);
            }
            if ($cover !== null) {
                $category->setCoverImage($cover)->setImage($cover);
            }
            if (\is_array($body['subCategories'] ?? null)) {
                $this->syncSubCategories($category, $body['subCategories']);
            }
        });

        return $category;
    }

    /**
     * Liste complète des sous-catégories, reconnues par leur slug (l'identifiant envoyé par le front est ignoré :
     * il en invente pour les nouvelles).
     *
     * @param array<mixed> $entries
     */
    private function syncSubCategories(Category $category, array $entries): void
    {
        $existing = [];
        foreach ($category->getSubCategories()->toArray() as $sub) {
            $existing[$sub->getSlug()] = $sub;
        }

        $kept = [];
        $position = 0;
        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $name = CatalogInput::text($entry['name'] ?? null) ?? '';
            $rawSlug = \is_string($entry['slug'] ?? null) && trim($entry['slug']) !== '' ? $entry['slug'] : $name;
            $slug = CatalogInput::slugify($rawSlug);
            if ($slug === '' || isset($kept[$slug])) {
                continue; // vide ou en double dans la liste envoyée
            }
            if ($name === '') {
                $name = ($existing[$slug] ?? null)?->getName() ?? $slug;
            }
            if (CatalogInput::length($name) > 80 || \strlen($slug) > 80) {
                throw ApiException::validation(['subCategories' => 'Nom de sous-catégorie trop long.'], 'Nom de sous-catégorie trop long (80 caractères au plus).');
            }

            $sub = $existing[$slug] ?? null;
            if ($sub === null) {
                $other = $this->subCategories->findOneBy(['slug' => $slug]);
                if ($other !== null) {
                    throw new ApiException(409, 'DUPLICATE_SUBCATEGORY', sprintf('La sous-catégorie « %s » existe déjà dans la catégorie « %s ».', $slug, $other->getCategory()->getName()));
                }
                if ($this->categories->findOneBy(['slug' => $slug]) !== null) {
                    throw new ApiException(409, 'DUPLICATE_SUBCATEGORY', sprintf('Une catégorie s’appelle déjà « %s ».', $slug));
                }
                $id = 'sub-' . $slug;
                if (\strlen($id) > 40 || $this->subCategories->find($id) !== null) {
                    $id = CatalogInput::newId('sub');
                }
                $sub = new SubCategory($id, $category, $slug, $name);
                $this->em->persist($sub);
            } else {
                $sub->setName($name);
            }

            $cover = \is_string($entry['coverImage'] ?? null) ? trim($entry['coverImage']) : '';
            $fallback = \is_string($entry['fallbackImage'] ?? null) ? trim($entry['fallbackImage']) : '';
            $sub->setCoverImage(CatalogInput::isImageUrl($cover) ? $cover : ($sub->getCoverImage() !== '' ? $sub->getCoverImage() : $category->getCoverImage()));
            $sub->setFallbackImage(CatalogInput::isImageUrl($fallback) ? $fallback : ($sub->getFallbackImage() !== '' ? $sub->getFallbackImage() : $category->getFallbackImage()));
            $sub->setPosition($position++);
            $kept[$slug] = true;
        }

        if (\count($kept) > self::MAX_SUBCATEGORIES) {
            throw ApiException::validation(['subCategories' => 'Trop de sous-catégories.'], sprintf('%d sous-catégories au plus.', self::MAX_SUBCATEGORIES));
        }

        foreach ($existing as $slug => $sub) {
            if (!isset($kept[$slug])) {
                $category->removeSubCategory($sub); // ses articles restent dans la catégorie, sans sous-catégorie
            }
        }
    }

    /** @param array<string, string> $errors */
    private function name(mixed $raw, array &$errors): string
    {
        $name = CatalogInput::text($raw) ?? '';
        if (CatalogInput::length($name) < 2) {
            $errors['name'] = 'Veuillez indiquer le nom de la catégorie.';
        } elseif (CatalogInput::length($name) > 80) {
            $errors['name'] = 'Le nom de la catégorie ne doit pas dépasser 80 caractères.';
        }

        return $name;
    }

    /** @param array<string, string> $errors */
    private function description(mixed $raw, array &$errors): string
    {
        $description = CatalogInput::longText($raw) ?? '';
        if (CatalogInput::length($description) > 500) {
            $errors['description'] = 'La description ne doit pas dépasser 500 caractères.';
        }

        return $description;
    }

    /** @param array<string, string> $errors */
    private function image(mixed $raw, string $default, string $what, array &$errors): string
    {
        $url = \is_string($raw) ? trim($raw) : '';
        if ($url === '') {
            return $default;
        }
        if (!CatalogInput::isImageUrl($url)) {
            $errors['coverImage'] = CatalogInput::imageError($url, $what);
        }

        return $url;
    }

    private function nextPosition(): int
    {
        return (int) $this->categories->createQueryBuilder('c')->select('MAX(c.position)')->getQuery()->getSingleScalarResult() + 1;
    }

    /** @param array<string, string> $errors */
    private function throwIfInvalid(array $errors): void
    {
        if ($errors !== []) {
            throw ApiException::validation($errors, reset($errors));
        }
    }
}
