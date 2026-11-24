<?php

namespace App\Seo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Page index.html construite du front : le serveur y ajoute les balises d'une fiche produit (contrat §2.12).
 *
 * Sur l'hébergement, elle se trouve à la racine du site (DOCUMENT_ROOT/index.html). FRONT_INDEX_PATH donne un autre
 * chemin (poste de développement, dossier particulier). Lue à chaque requête : une nouvelle version du front est
 * prise en compte dès son envoi.
 */
final class FrontIndex
{
    private const MAX_BYTES = 1_048_576;

    public function __construct(
        #[Autowire('%app.front_index_path%')]
        private readonly string $configuredPath,
    ) {}

    public function configuredPath(): string
    {
        return trim($this->configuredPath);
    }

    /** Chemin du fichier, ou null s'il est introuvable. */
    public function path(?Request $request = null): ?string
    {
        $path = $this->configuredPath();
        if ($path === '') {
            $root = (string) ($request?->server->get('DOCUMENT_ROOT') ?? '');
            $path = $root !== '' ? rtrim($root, '/\\') . '/index.html' : '';
        }

        return $path !== '' && is_file($path) && is_readable($path) ? $path : null;
    }

    /** Contenu de la page, ou null si le fichier est introuvable ou n'est pas une page HTML. */
    public function html(Request $request): ?string
    {
        $path = $this->path($request);
        if ($path === null || (int) @filesize($path) > self::MAX_BYTES) {
            return null;
        }
        $html = @file_get_contents($path);

        return \is_string($html) && stripos($html, '</head>') !== false ? $html : null;
    }

    /** Fichier public du front (« /images/products/x.webp ») réellement présent à côté de index.html ? */
    public function hasPublicFile(string $publicPath, Request $request): bool
    {
        $index = $this->path($request);
        if ($index === null || !str_starts_with($publicPath, '/') || str_contains($publicPath, '..') || str_contains($publicPath, "\0")) {
            return false;
        }

        return is_file(\dirname($index) . $publicPath);
    }
}
