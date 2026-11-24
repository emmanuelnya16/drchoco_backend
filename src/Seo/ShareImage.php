<?php

namespace App\Seo;

use App\Entity\Product;
use Symfony\Component\HttpFoundation\Request;

/**
 * Photo d'un article pour les moteurs de recherche et les aperçus de partage (WhatsApp, Facebook…).
 *
 * Les aperçus n'acceptent ni SVG ni image absente : un article sans vraie photo reçoit l'image de la marque
 * (public/brand/partage.jpg du front, 1 200 × 630).
 */
final class ShareImage
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;
    public const DEFAULT_PATH = '/brand/partage.jpg';

    /** Photo entière (jamais coupée) sur un fond assorti, au format des aperçus, en JPEG. */
    private const CLOUDINARY_SHARE = 'c_pad,b_auto,w_1200,h_630,f_jpg,q_auto';

    /** Adresse publique Cloudinary : préfixe jusqu'à « /upload/ » et reste du chemin. */
    private const CLOUDINARY_URL = '~^(https://res\.cloudinary\.com/[^/]+/image/upload/)(.+)$~';

    /** Premier segment déjà transformé (« f_auto,w_600 ») ou signé : l'adresse est gardée telle quelle. */
    private const LOCKED_SEGMENT = '~^(?:s--[^/]+--|[a-z]{1,3}_[^/]*)(?:/|$)~';

    public function __construct(
        private readonly SiteUrl $site,
        private readonly FrontIndex $front,
    ) {}

    /** @return array{url: string, width: ?int, height: ?int} image de l'aperçu de partage */
    public function share(Product $product, Request $request): array
    {
        $photo = $this->photo($product, $request);
        if ($photo === null) {
            return ['url' => $this->defaultUrl(), 'width' => self::WIDTH, 'height' => self::HEIGHT];
        }
        if (preg_match(self::CLOUDINARY_URL, $photo, $m) === 1 && preg_match(self::LOCKED_SEGMENT, $m[2]) !== 1) {
            return ['url' => $m[1] . self::CLOUDINARY_SHARE . '/' . $m[2], 'width' => self::WIDTH, 'height' => self::HEIGHT];
        }

        return ['url' => $photo, 'width' => null, 'height' => null];
    }

    /** Adresse absolue de la première vraie photo, ou null (aucune photo, SVG, fichier absent). */
    public function photo(Product $product, Request $request): ?string
    {
        return $this->firstPhoto($product->getMediaGallery(), $request);
    }

    /** @param list<array<string, string>> $gallery galerie d'un article (Product::getMediaGallery) */
    public function firstPhoto(array $gallery, Request $request): ?string
    {
        foreach ($gallery as $entry) {
            $url = trim((string) ($entry['url'] ?? ''));
            if ($url === '' || preg_match('/\.svg(?:[?#]|$)/i', $url) === 1) {
                continue;
            }
            if (str_starts_with($url, 'https://')) {
                return $url;
            }
            if ($this->front->hasPublicFile($url, $request)) {
                return $this->site->to($url);
            }
        }

        return null;
    }

    public function defaultUrl(): string
    {
        return $this->site->to(self::DEFAULT_PATH);
    }
}
