<?php

namespace App\Catalog;

use Symfony\Component\HttpFoundation\Request;

/**
 * Filtres de GET /products (contrat §6.2), lus avec tolérance : un filtre inconnu ou mal formé est
 * ignoré, jamais d'erreur 422.
 */
final class ProductFilters
{
    public const SORT_POPULAR    = 'popular';
    public const SORT_NEWEST     = 'newest';
    public const SORT_PRICE_ASC  = 'price-asc';
    public const SORT_PRICE_DESC = 'price-desc';

    public const DEFAULT_PAGE_SIZE = 12;
    public const MAX_PAGE_SIZE = 50;

    // Tranches de prix : < 15 000 ; 15 000 à 30 000 inclus ; > 30 000 à 50 000 inclus ; > 50 000.
    // Libellés avec espaces insécables, comme l'affichage du front.
    public const PRICE_RANGES = [
        '0-15000'     => "Moins de 15 000 FCFA",
        '15000-30000' => "15 000 – 30 000 FCFA",
        '30000-50000' => "30 000 – 50 000 FCFA",
        '50000+'      => "Plus de 50 000 FCFA",
    ];

    // Recherche : quelques mots, de longueur raisonnable.
    private const MAX_WORDS = 6;
    private const MAX_WORD_LENGTH = 40;

    /** Ville demandée : une ville de boutique, ou un texte inconnu (aucun article), ou null (toutes). */
    public ?string $city = null;
    public bool $unknownCity = false;
    public ?string $category = null;
    public ?string $sub = null;
    /** @var list<string> */
    public array $sizes = [];
    public ?string $price = null;
    public ?int $minPrice = null;
    public ?int $maxPrice = null;
    public bool $available = false;
    public ?string $look = null;
    /** @var list<string> mots de la recherche */
    public array $words = [];
    public string $sort = self::SORT_POPULAR;
    public int $page = 1;
    public int $pageSize = self::DEFAULT_PAGE_SIZE;

    public static function fromRequest(Request $request): self
    {
        $f = new self();
        $query = self::parseQueryString((string) $request->server->get('QUERY_STRING', ''));

        [$f->city, $f->unknownCity] = self::parseCity($query['city'][0] ?? null);
        $f->category = self::slug($query['category'][0] ?? null);
        $f->sub = self::slug($query['sub'][0] ?? null);

        // size=L&size=XL (répétable), size=L,XL ou size[]=L
        $sizes = [];
        foreach (array_merge($query['size'] ?? [], $query['size[]'] ?? []) as $raw) {
            foreach (explode(',', $raw) as $size) {
                $size = trim($size);
                if ($size !== '' && mb_strlen($size) <= 20) {
                    $sizes[mb_strtoupper($size)] = $size;
                }
            }
        }
        $f->sizes = array_values($sizes);

        $f->price = self::parsePriceRange($query['price'][0] ?? null);
        $f->minPrice = self::int($query['minPrice'][0] ?? null);
        $f->maxPrice = self::int($query['maxPrice'][0] ?? null);
        $f->available = \in_array($query['available'][0] ?? '', ['1', 'true'], true);

        $look = trim($query['look'][0] ?? '');
        $f->look = $look !== '' && mb_strlen($look) <= 40 ? $look : null;

        $f->words = self::words($query['q'][0] ?? '');
        $f->sort = self::parseSort($query['sort'][0] ?? null);

        $page = self::int($query['page'][0] ?? null);
        $f->page = $page !== null && $page >= 1 ? min($page, 10000) : 1;
        $pageSize = self::int($query['pageSize'][0] ?? null);
        $f->pageSize = $pageSize !== null && $pageSize >= 1 ? min($pageSize, self::MAX_PAGE_SIZE) : self::DEFAULT_PAGE_SIZE;

        return $f;
    }

    /**
     * Ville : « Yaoundé », « yaounde », « Douala »… ; « all » ou vide = toutes les villes.
     *
     * @return array{0: ?string, 1: bool} [ville reconnue, ville inconnue demandée]
     */
    public static function parseCity(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '' || strtolower($raw) === 'all') {
            return [null, false];
        }
        $city = ShopCity::normalize($raw);

        return [$city, $city === null];
    }

    /** @return list<string> mots de recherche (sans doublons), découpés sur les espaces */
    public static function words(string $raw): array
    {
        $raw = trim(mb_substr($raw, 0, 120));
        if ($raw === '') {
            return [];
        }
        $words = [];
        foreach (preg_split('/\s+/u', $raw) ?: [] as $word) {
            $word = mb_substr($word, 0, self::MAX_WORD_LENGTH);
            if ($word !== '') {
                $words[TextFolder::fold($word)] = $word;
            }
        }

        return \array_slice(array_values($words), 0, self::MAX_WORDS);
    }

    /**
     * Lit la chaîne de requête brute : PHP ne garde que la dernière valeur d'un paramètre répété
     * (size=L&size=XL), alors que le front répète « size ».
     *
     * @return array<string, list<string>>
     */
    private static function parseQueryString(string $queryString): array
    {
        $result = [];
        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            if (\count($result[$key] ?? []) < 30) {
                $result[$key][] = urldecode($value);
            }
        }

        return $result;
    }

    private static function slug(?string $raw): ?string
    {
        $raw = strtolower(trim((string) $raw));

        return $raw !== '' && preg_match('/^[a-z0-9-]{1,80}$/', $raw) ? $raw : null;
    }

    private static function int(?string $raw): ?int
    {
        $raw = trim((string) $raw);

        return preg_match('/^\d{1,9}$/', $raw) ? (int) $raw : null;
    }

    private static function parsePriceRange(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        // « 50000+ » non encodé arrive comme « 50000 » (le + devient une espace).
        if ($raw === '50000') {
            $raw = '50000+';
        }

        return \array_key_exists($raw, self::PRICE_RANGES) ? $raw : null;
    }

    private static function parseSort(?string $raw): string
    {
        return match (trim((string) $raw)) {
            'newest' => self::SORT_NEWEST,
            'price-asc', 'price_asc' => self::SORT_PRICE_ASC,
            'price-desc', 'price_desc' => self::SORT_PRICE_DESC,
            default => self::SORT_POPULAR,
        };
    }
}
