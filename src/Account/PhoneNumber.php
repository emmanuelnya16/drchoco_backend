<?php

namespace App\Account;

/**
 * Numéros de téléphone mobiles camerounais (+237 6XX XX XX XX).
 *
 * Mêmes règles que normalizeCmPhone() du front (src/lib/utils/phone.ts) : espaces, points, tirets et
 * parenthèses ignorés ; préfixe +237, 00237 ou 237 accepté ; 9 chiffres commençant par 6.
 */
final class PhoneNumber
{
    /** Format E.164 d'un mobile camerounais, ou null si le texte n'en est pas un. */
    public static function normalizeCameroonMobile(mixed $raw): ?string
    {
        if (!\is_string($raw) || \strlen($raw) > 40) {
            return null;
        }

        // Espaces ordinaires, insécables (U+00A0, U+202F, U+2007) et fines (U+2009) : le front affiche
        // les numéros avec des espaces insécables, un numéro recopié depuis l'app doit rester valide.
        $digits = preg_replace('/[\s\x{00A0}\x{202F}\x{2007}\x{2009}.\-()]/u', '', $raw);
        if ($digits === null) {
            return null; // texte qui n'est pas de l'UTF-8 valide
        }

        if (str_starts_with($digits, '+237')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '00237')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '237') && \strlen($digits) === 12) {
            $digits = substr($digits, 3);
        }

        return preg_match('/^6\d{8}\z/', $digits) === 1 ? '+237' . $digits : null;
    }

    /** Numéro masqué pour les journaux : « +2376****0001 ». */
    public static function mask(string $phone): string
    {
        if (\strlen($phone) <= 9) {
            return str_repeat('*', \strlen($phone));
        }

        return substr($phone, 0, 5) . str_repeat('*', \strlen($phone) - 9) . substr($phone, -4);
    }
}
