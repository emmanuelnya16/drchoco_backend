<?php

namespace App\Controller\Seo;

use App\Admin\CatalogInput;
use App\Repository\ProductRepository;
use App\Seo\FrontIndex;
use App\Seo\ProductHead;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * GET /produit/{slug} (adresse de l'app, hors de /api) — page de l'app avec les balises de l'article (contrat §2.12).
 *
 * WhatsApp, Facebook et les autres aperçus de lien ne lisent que ce HTML. Tout le monde reçoit la même page : le
 * visiteur voit ensuite l'app démarrer normalement.
 *  - article inconnu, retiré de la vente ou dans une catégorie masquée : la page de l'app, statut 404 ;
 *  - base de données indisponible : la page de l'app quand même, statut 503 (les robots repasseront).
 */
#[AsController]
final class ProductPageController
{
    private const RETRY_AFTER_SECONDS = 300;

    public function __construct(
        private readonly FrontIndex $front,
        private readonly ProductRepository $products,
        private readonly ProductHead $head,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request, string $slug): Response
    {
        $html = $this->front->html($request);
        if ($html === null) {
            $this->logger->critical('Fiche produit : index.html du front introuvable (FRONT_INDEX_PATH ou racine du site).');
            $response = new Response('La boutique est momentanément indisponible. Veuillez réessayer dans quelques instants.', Response::HTTP_SERVICE_UNAVAILABLE, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);

            return $this->finish($response, true);
        }

        try {
            // Adresses toujours en minuscules, comme le routeur du front (qui ignore la casse).
            $slug = mb_strtolower($slug);
            $product = preg_match(CatalogInput::SLUG_PATTERN, $slug) === 1 ? $this->products->findVisibleBySlug($slug) : null;
            $page = $product !== null ? $this->head->build($product, $request)->applyTo($html) : $html;
        } catch (\Throwable $e) {
            $this->logger->error('Fiche produit : balises de partage impossibles à écrire.', ['exception' => $e]);

            return $this->finish(new Response($html, Response::HTTP_SERVICE_UNAVAILABLE), true);
        }

        $response = new Response($page, $product !== null ? Response::HTTP_OK : Response::HTTP_NOT_FOUND);
        if ($product === null) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $this->finish($response, false);
    }

    /** La page de l'app est toujours revérifiée par le navigateur et le service worker (contrat §2.12). */
    private function finish(Response $response, bool $unavailable): Response
    {
        if (!$response->headers->has('Content-Type')) {
            $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        }
        $response->headers->set('Cache-Control', 'no-cache');
        if ($unavailable) {
            $response->headers->set('Retry-After', (string) self::RETRY_AFTER_SECONDS);
        }

        return $response;
    }
}
