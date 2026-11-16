<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Réponses JSON de l'API.
 *
 * - catalogue et données publiques : « public, max-age=60, stale-while-revalidate=600 » avec ETag
 *   (contrat §2.7.2) : le service worker du front en garde une copie pour la consultation hors connexion ;
 * - écritures et données sensibles : « no-store ».
 */
final class ApiJson
{
    // Accents et « / » lisibles tels quels ; < > & ' " échappés (sûr même affiché dans du HTML).
    public const ENCODING = JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;

    public static function publicCached(Request $request, mixed $data, int $maxAge = 60, ?int $staleWhileRevalidate = 600): JsonResponse
    {
        $response = self::create($data);
        $response->setPublic();
        $response->setMaxAge($maxAge);
        if ($staleWhileRevalidate !== null) {
            $response->headers->addCacheControlDirective('stale-while-revalidate', (string) $staleWhileRevalidate);
        }

        $etag = hash('xxh128', (string) $response->getContent());
        $response->setEtag($etag, true);

        if (self::matchesEtag($request, $etag)) {
            $response->setNotModified();
        }

        return $response;
    }

    public static function noStore(mixed $data, int $status = 200): JsonResponse
    {
        $response = self::create($data, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Lecture privée du client connecté (GET /me, /me/addresses, /favorites) : « private, no-cache »
     * (proposition du contrat §2.7.2, question 1 du §17). Aucun intermédiaire ne la garde, le navigateur
     * revalide à chaque appel, et le service worker peut garder la dernière copie pour le hors connexion
     * (cache « api-user », vidé à la déconnexion).
     *
     * Volontairement SANS « Vary: Authorization » : le jeton d'accès change à chaque rafraîchissement (et
     * manque au démarrage hors connexion) ; avec cet en-tête, le service worker ne retrouverait jamais sa copie.
     * « private » suffit à empêcher tout cache partagé de garder la réponse.
     */
    public static function privateNoCache(mixed $data): JsonResponse
    {
        $response = self::create($data);
        $response->headers->set('Cache-Control', 'private, no-cache');

        return $response;
    }

    private static function create(mixed $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse(null, $status);
        $response->setEncodingOptions(self::ENCODING);
        $response->setData($data);

        return $response;
    }

    /**
     * If-None-Match : compare sans tenir compte de « W/ », des guillemets ni du suffixe que la compression
     * d'Apache ajoute parfois (« -gzip », « -br »).
     */
    private static function matchesEtag(Request $request, string $etag): bool
    {
        $header = (string) $request->headers->get('If-None-Match', '');
        if ($header === '') {
            return false;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*') {
                return true;
            }
            $candidate = preg_replace(['/^W\//', '/^"|"$/', '/-(gzip|br|deflate)$/'], '', $candidate);
            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
