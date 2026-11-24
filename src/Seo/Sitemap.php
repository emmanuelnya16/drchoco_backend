<?php

namespace App\Seo;

/**
 * Plans du site au format sitemaps.org (contrat §2.13). Adresses et dates seulement : aucune donnée privée.
 */
final class Sitemap
{
    /** Limite du format : 50 000 adresses par plan. */
    public const MAX_URLS = 50_000;

    private const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const NS_IMAGE = 'http://www.google.com/schemas/sitemap-image/1.1';

    /** @param list<array{loc: string, lastmod: ?\DateTimeInterface}> $sitemaps */
    public static function index(array $sitemaps): string
    {
        $xml = self::open('sitemapindex', false);
        foreach ($sitemaps as $sitemap) {
            $xml .= '  <sitemap>' . self::loc($sitemap['loc']) . self::lastmod($sitemap['lastmod']) . "</sitemap>\n";
        }

        return $xml . "</sitemapindex>\n";
    }

    /** @param list<array{loc: string, lastmod: ?\DateTimeInterface, image?: ?string}> $urls */
    public static function urls(array $urls): string
    {
        $xml = self::open('urlset', true);
        foreach (\array_slice($urls, 0, self::MAX_URLS) as $url) {
            $image = ($url['image'] ?? null) !== null
                ? '<image:image><image:loc>' . self::escape($url['image']) . '</image:loc></image:image>'
                : '';
            $xml .= '  <url>' . self::loc($url['loc']) . self::lastmod($url['lastmod']) . $image . "</url>\n";
        }

        return $xml . "</urlset>\n";
    }

    private static function open(string $root, bool $images): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<' . $root . ' xmlns="' . self::NS . '"' . ($images ? ' xmlns:image="' . self::NS_IMAGE . '"' : '') . ">\n";
    }

    private static function loc(string $url): string
    {
        return '<loc>' . self::escape($url) . '</loc>';
    }

    private static function lastmod(?\DateTimeInterface $date): string
    {
        return $date !== null ? '<lastmod>' . $date->format(\DATE_ATOM) . '</lastmod>' : '';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_XML1, 'UTF-8');
    }
}
