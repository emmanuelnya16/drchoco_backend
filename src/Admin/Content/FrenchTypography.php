<?php

namespace App\Admin\Content;

/**
 * Espaces insécables du français, même règle que le front (src/features/content/markdown/typography.ts,
 * `frenchSpacing`) : avant « : ; ? ! », dans « … », entre les milliers, avant FCFA, h, min, % et Mo.
 * Seules les espaces ordinaires sont remplacées : un texte déjà correct ne change pas.
 */
final class FrenchTypography
{
    private const NBSP = "\u{00A0}";

    public static function spacing(string $text): string
    {
        $rules = [
            '/ +([:;?!])(?=[\s*_)\]»"]|$)/u'           => self::NBSP . '$1',
            '/ +»/u'                                    => self::NBSP . '»',
            '/« +/u'                                    => '«' . self::NBSP,
            '/(\d) (?=\d{3}(?!\d))/u'                   => '$1' . self::NBSP,
            '/(\d) (FCFA|h\b|min\b|%|Mo\b)/u'           => '$1' . self::NBSP . '$2',
        ];
        foreach ($rules as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }
}
