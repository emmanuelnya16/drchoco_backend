<?php

namespace App\Catalog;

/**
 * Comparaison de textes « sans accents ni casse » (Yaoundé = yaounde), comme les mocks du front.
 * Sans dépendre de l'extension intl (absente de XAMPP et de nombreux hébergements cPanel).
 */
final class TextFolder
{
    private const MAP = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'œ' => 'oe',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        '’' => "'", '‘' => "'",
    ];

    public static function fold(string $text): string
    {
        return trim(strtr(mb_strtolower($text, 'UTF-8'), self::MAP));
    }

    public static function contains(string $haystack, string $needle): bool
    {
        return $needle === '' || str_contains(self::fold($haystack), self::fold($needle));
    }
}
