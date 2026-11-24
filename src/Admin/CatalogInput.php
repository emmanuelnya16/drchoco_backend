<?php

namespace App\Admin;

use App\Account\TextInput;
use App\Catalog\TextFolder;

/**
 * Règles communes aux formulaires du catalogue de l'administration (articles, catégories, looks).
 */
final class CatalogInput
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /** Identifiant d'adresse : « Babouche Cuir Souverain » → « babouche-cuir-souverain » (règle du front). */
    public static function slugify(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', TextFolder::fold($text)), '-');
    }

    /** Hébergement des photos (POST /api/admin/media) : le seul autorisé par la politique de sécurité du front. */
    private const IMAGE_HOST = 'res.cloudinary.com';

    /**
     * Adresse d'image acceptée : Cloudinary (photo envoyée par POST /api/admin/media) ou chemin du site
     * (« /images/products/… »). Refusées : un autre hébergement (il verrait passer tous les visiteurs, et pourrait changer
     * l'image sans que la boutique le sache), « blob: » et « data: » (aperçus locaux du navigateur, perdus au
     * rechargement), http, chemins « // » et tout autre schéma.
     */
    public static function isImageUrl(string $url): bool
    {
        if ($url === '' || \strlen($url) > 500 || preg_match('/[\s<>"\']/', $url) === 1) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            // « /mocks/… » : photos d'exemple du formulaire du front, absentes du site (image vide en boutique).
            return !str_starts_with($url, '//') && !str_starts_with($url, '/mocks/') && !str_contains($url, '\\');
        }

        return str_starts_with($url, 'https://' . self::IMAGE_HOST . '/') && filter_var($url, \FILTER_VALIDATE_URL) !== false;
    }

    /** Message clair pour une adresse d'image refusée (le front affiche le message tel quel). */
    public static function imageError(string $url, string $what): string
    {
        if (str_starts_with($url, 'blob:') || str_starts_with($url, 'data:')) {
            return sprintf('%s n’a pas été envoyée au serveur : importez-la de nouveau.', $what);
        }
        if (str_starts_with($url, '/mocks/')) {
            return sprintf('%s est une photo d’exemple : remplacez-la par une vraie photo.', $what);
        }

        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            return sprintf('%s vient d’un autre site : importez-la depuis votre appareil (bouton d’envoi de photo).', $what);
        }

        return sprintf('%s : adresse invalide (photo importée ou /images/…).', $what);
    }

    /** Texte nettoyé (espaces réduits), ou null si ce n'est pas une chaîne. */
    public static function text(mixed $value): ?string
    {
        return TextInput::clean($value);
    }

    /** Texte long : retours à la ligne gardés, espaces en trop retirés. */
    public static function longText(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        if ($text === null) {
            return null;
        }
        $text = str_replace("\r\n", "\n", $text);

        return trim((string) preg_replace("/[ \t\x{00A0}]+/u", ' ', $text));
    }

    public static function length(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    /** Identifiant aléatoire court et lisible : « prod-3f9c0a1b2c ». */
    public static function newId(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(5));
    }
}
