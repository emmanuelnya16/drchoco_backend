<?php

namespace App\Account;

/**
 * Nettoyage des textes saisis par le client (prénom, quartier, point de repère…) :
 * caractères de contrôle retirés, espaces (y compris insécables) réduits à une seule, bords rognés.
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
        $text = preg_replace('/[\s\x{00A0}\x{202F}\x{2007}\x{2009}]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public static function length(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }
}
