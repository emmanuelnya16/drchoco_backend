<?php

namespace App\Security;

use App\Api\RequestPath;
use App\Api\ApiException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Protection des routes de connexion qui posent ou effacent le cookie de session (/api/auth/*,
 * /api/admin/auth/*), et de l'abonnement aux notifications qui accepte ce cookie (/api/push/subscribe, P7),
 * contre les envois forcés depuis un autre site (CSRF de connexion ou de déconnexion, appareil imposé) :
 *
 *  - une requête venue d'une autre origine que l'API ou que les origines autorisées par CORS_ALLOW_ORIGIN
 *    est refusée (403), de même qu'une requête marquée « cross-site » par le navigateur ;
 *  - les routes client avec un corps (code SMS, rafraîchissement) exigent « Content-Type: application/json » :
 *    un formulaire HTML ou un envoi « simple » sans contrôle CORS ne peut pas les appeler.
 *
 * Les appels sans en-tête Origin (outils, tests, serveur à serveur) ne viennent pas d'un navigateur piégé.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final class AuthOriginGuard
{
    private const GUARDED_PREFIXES = ['/api/auth/', '/api/admin/auth/', '/api/push/subscribe'];
    // Routes envoyées avec un corps JSON par le front (le rafraîchissement admin part sans corps : non concerné).
    private const JSON_ONLY_PATHS = ['/api/auth/otp/request', '/api/auth/otp/verify', '/api/auth/whatsapp/start', '/api/auth/refresh', '/api/admin/auth/login', '/api/push/subscribe'];

    public function __construct(
        #[Autowire('%env(CORS_ALLOW_ORIGIN)%')]
        private readonly string $corsAllowOrigin,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = RequestPath::of($request);
        if ($request->getMethod() !== 'POST' || !$this->isGuarded($path)) {
            return;
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null) {
            if (!$this->isAllowedOrigin($origin, (string) $request->headers->get('Host', ''))) {
                throw $this->forbidden();
            }
        } elseif (strtolower((string) $request->headers->get('Sec-Fetch-Site', '')) === 'cross-site') {
            throw $this->forbidden();
        }

        if (\in_array($path, self::JSON_ONLY_PATHS, true) && $request->getContentTypeFormat() !== 'json') {
            throw new ApiException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Requête invalide : le corps doit être envoyé en JSON.');
        }
    }

    private function isGuarded(string $path): bool
    {
        foreach (self::GUARDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Même domaine que l'API (l'hôte seul est comparé : un relais HTTPS peut changer le schéma),
     * ou origine autorisée par CORS_ALLOW_ORIGIN (même expression que nelmio/cors-bundle).
     */
    private function isAllowedOrigin(string $origin, string $host): bool
    {
        $parts = parse_url($origin);
        if (!\is_array($parts) || !isset($parts['host'])) {
            return false; // « null » (page isolée, fichier local…) ou valeur illisible
        }

        $originHost = strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        if ($host !== '' && $originHost === strtolower($host)) {
            return true;
        }

        return $this->corsAllowOrigin !== '' && preg_match('{' . $this->corsAllowOrigin . '}i', $origin) === 1;
    }

    private function forbidden(): ApiException
    {
        return new ApiException(403, 'FORBIDDEN_ORIGIN', 'Cette demande doit venir de l’application DR Choco.');
    }
}
