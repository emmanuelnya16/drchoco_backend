<?php

namespace App\Account;

/**
 * Nettoyage des textes saisis par le client (prénom, quartier, point de repère…) :
 * caractères de contrôle, invisibles et de sens d'écriture retirés (un nom ne peut ni être vide à l'écran, ni retourner
 * le numéro qui le suit), espaces (y compris insécables) réduits à une seule, bords rognés.
 */
final class TextInput
{
    /** Texte nettoyé, ou null si la valeur n'est pas une chaîne. */
    public static function clean(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        if ($text === null) {
            return null; // UTF-8 invalide
        }
        $text = preg_replace('/[\x{0080}-\x{009F}\x{00AD}\x{061C}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{2800}\x{3164}\x{FEFF}\x{FFA0}\x{FFF9}-\x{FFFB}]+/u', '', $text) ?? $text;
        $text = preg_replace('/[\s\x{00A0}\x{202F}\x{2007}\x{2009}]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public static function length(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }
}
