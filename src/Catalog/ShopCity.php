<?php

namespace App\Catalog;

/**
 * Villes des boutiques DR Choco : chaque article et chaque vendeur appartient à l'une d'elles.
 * La boutique principale (retrait en boutique) est à Yaoundé.
 */
final class ShopCity
{
    public const YAOUNDE = 'Yaoundé';
    public const DOUALA  = 'Douala';

    public const ALL = [self::YAOUNDE, self::DOUALA];

    /**
     * Ville reconnue sans tenir compte des accents ni de la casse (« yaounde » → « Yaoundé »),
     * ou null si le texte ne correspond à aucune ville de boutique.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $folded = TextFolder::fold($raw);
        foreach (self::ALL as $city) {
            if (TextFolder::fold($city) === $folded) {
                return $city;
            }
        }

        return null;
    }
}
