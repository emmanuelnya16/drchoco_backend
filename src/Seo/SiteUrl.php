<?php

namespace App\Seo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adresse publique du site (SITE_URL, « https://drchoco.com »).
 *
 * Toujours lue dans la configuration, jamais dans la requête : un en-tête Host falsifié ne change ni les adresses
 * canoniques, ni les aperçus de partage, ni le plan du site.
 */
final class SiteUrl
{
    private readonly string $origin;

    public function __construct(
        #[Autowire('%env(SITE_URL)%')]
        string $siteUrl,
    ) {
        $parts = parse_url(trim($siteUrl));
        $scheme = \is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        if (!\in_array($scheme, ['https', 'http'], true) || ($parts['host'] ?? '') === '') {
            throw new \InvalidArgumentException('SITE_URL doit être une adresse complète, par exemple « https://drchoco.com ».');
        }

        $this->origin = $scheme . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** « https://drchoco.com », sans « / » final. */
    public function origin(): string
    {
        return $this->origin;
    }

    /** Adresse absolue d'un chemin du site (« /produit/hoodie-barbele-noir »). */
    public function to(string $path): string
    {
        return $this->origin . '/' . ltrim($path, '/');
    }
}
