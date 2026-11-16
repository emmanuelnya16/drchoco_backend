<?php

namespace App\Admin;

use App\Api\ApiException;
use App\Entity\Look;
use App\Repository\LookRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Looks de l'accueil (écran « Looks & Catégories »), modifiables par toute l'équipe comme le prévoit le front.
 * Un look désigne ses articles par leur adresse (slug) : seuls les articles en vente sont gardés.
 */
final class LookEditor
{
    public const DEFAULT_IMAGE = '/images/looks/look-1.webp';
    public const DEFAULT_FALLBACK = '/images/fallback/mood-portrait.svg';
    public const DEFAULT_SUBTITLE = 'Silhouette signature DR Choco';
    public const MAX_PRODUCTS = 20;

    public function __construct(
        private readonly LookRepository $looks,
        private readonly ProductRepository $products,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @param array<string, mixed> $body */
    public function create(array $body): Look
    {
        $errors = [];
        $title = $this->title($body['title'] ?? null, $errors);
        $values = $this->values($body, null, $errors);
        $this->throwIfInvalid($errors);

        $look = new Look(CatalogInput::newId('look'), $title);
        $this->apply($look, $values);
        $look->setPosition((int) $this->looks->createQueryBuilder('l')->select('MAX(l.position)')->getQuery()->getSingleScalarResult() + 1);
        $this->em->persist($look);
        $this->em->flush();

        return $look;
    }

    /** @param array<string, mixed> $body seuls les champs présents sont modifiés */
    public function update(Look $look, array $body): Look
    {
        $errors = [];
        $title = \array_key_exists('title', $body) ? $this->title($body['title'], $errors) : null;
        $values = $this->values($body, $look, $errors);
        $this->throwIfInvalid($errors);

        if ($title !== null) {
            $look->setTitle($title);
        }
        $this->apply($look, $values);
        $this->em->flush();

        return $look;
    }

    public function delete(Look $look): void
    {
        $this->em->remove($look);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function values(array $body, ?Look $current, array &$errors): array
    {
        $values = [];

        if (\array_key_exists('subtitle', $body) || $current === null) {
            $subtitle = CatalogInput::text($body['subtitle'] ?? null) ?? '';
            if (CatalogInput::length($subtitle) > 200) {
                $errors['subtitle'] = 'Le sous-titre ne doit pas dépasser 200 caractères.';
            }
            $values['subtitle'] = $subtitle !== '' ? $subtitle : self::DEFAULT_SUBTITLE;
        }

        foreach (['image' => [self::DEFAULT_IMAGE, 'La photo du look'], 'fallback' => [self::DEFAULT_FALLBACK, 'Le visuel de repli']] as $field => [$default, $what]) {
            $url = \is_string($body[$field] ?? null) ? trim($body[$field]) : '';
            if ($url === '') {
                if ($current === null) {
                    $values[$field] = $default; // vide : visuel par défaut ; en modification, l'image actuelle reste
                }
                continue;
            }
            if (!CatalogInput::isImageUrl($url)) {
                $errors[$field] = CatalogInput::imageError($url, $what);
                continue;
            }
            $values[$field] = $url;
        }

        if (\array_key_exists('productSlugs', $body) || $current === null) {
            $wanted = [];
            foreach (\is_array($body['productSlugs'] ?? null) ? $body['productSlugs'] : [] as $slug) {
                if (\is_string($slug) && $slug !== '' && \strlen($slug) <= 160) {
                    $wanted[] = $slug;
                }
            }
            $wanted = array_values(array_unique($wanted));
            // Articles inconnus ou retirés de la vente : ignorés (le front renvoie parfois d'anciennes adresses).
            $existing = $this->products->activeSlugSet($wanted);
            $slugs = array_values(array_filter($wanted, fn (string $slug) => isset($existing[$slug])));
            if ($slugs === []) {
                $errors['productSlugs'] = 'Sélectionnez au moins un produit du catalogue pour ce look.';
            } elseif (\count($slugs) > self::MAX_PRODUCTS) {
                $errors['productSlugs'] = sprintf('%d articles au plus par look.', self::MAX_PRODUCTS);
            }
            $values['productSlugs'] = $slugs;
        }

        return $values;
    }

    /** @param array<string, mixed> $values */
    private function apply(Look $look, array $values): void
    {
        if (isset($values['subtitle'])) {
            $look->setSubtitle($values['subtitle']);
        }
        if (isset($values['image'])) {
            $look->setImage($values['image']);
        }
        if (isset($values['fallback'])) {
            $look->setFallback($values['fallback']);
        }
        if (isset($values['productSlugs'])) {
            $look->setProductSlugs($values['productSlugs']);
        }
    }

    /** @param array<string, string> $errors */
    private function title(mixed $raw, array &$errors): string
    {
        $title = CatalogInput::text($raw) ?? '';
        if ($title === '') {
            $errors['title'] = 'Le titre du look est obligatoire.';
        } elseif (CatalogInput::length($title) > 120) {
            $errors['title'] = 'Le titre ne doit pas dépasser 120 caractères.';
        }

        return $title;
    }

    /** @param array<string, string> $errors */
    private function throwIfInvalid(array $errors): void
    {
        if ($errors !== []) {
            throw ApiException::validation($errors, reset($errors));
        }
    }
}
