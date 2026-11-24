<?php

namespace App\Seo;

/**
 * Textes du référencement, identiques à ceux que le front écrit ensuite dans la page
 * (src/lib/seo/seo.ts, src/lib/utils/price.ts et ProductSeo.tsx) : la page ne change pas quand l'app démarre.
 */
final class SeoText
{
    public const SITE_NAME = 'DR Choco L’Incompris';

    public const DEFAULT_DESCRIPTION = 'Vêtements, chaussures et babouches pour hommes. Réservez en ligne, payez par Mobile Money. Livraison partout au Cameroun ou retrait en boutique.';

    /** Longueur au-delà de laquelle les moteurs coupent la description. */
    private const DESCRIPTION_MAX_LENGTH = 160;

    /** Description sur une ligne, coupée au dernier mot avant 160 caractères (normalizeDescription du front). */
    public static function description(?string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
        if ($text === '') {
            return self::DEFAULT_DESCRIPTION;
        }
        if (mb_strlen($text) <= self::DESCRIPTION_MAX_LENGTH) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::DESCRIPTION_MAX_LENGTH - 1);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 80) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return preg_replace('/[\s,;:.]+$/u', '', $cut) . '…';
    }

    /** « 45 000 FCFA » : espaces fines insécables entre les milliers, espace insécable avant FCFA. */
    public static function price(int $amount): string
    {
        return number_format($amount, 0, ',', "\u{202F}") . "\u{00A0}FCFA";
    }
}
