<?php

namespace App\EventSubscriber;

use App\Api\ApiException;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limite de débit des écritures d'un client connecté (contrat §2.14) : 60 par minute et par compte
 * (adresses, profil, favoris, puis réservations…). Au-delà : 429 RATE_LIMITED avec Retry-After.
 *
 * Exécuté après le pare-feu (priorité 8) : le client est déjà identifié par son JWT.
 * Ne concerne ni la connexion (/auth, limites propres), ni les routes publiques, ni l'administration.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class ClientWriteRateLimitSubscriber
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];
    private const EXCLUDED_PREFIXES = ['/api/auth/', '/api/admin', '/api/cart/validate', '/api/client-errors'];

    public function __construct(
        private readonly Security $security,
        #[Autowire(service: 'limiter.client_writes')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (\in_array($request->getMethod(), self::READ_METHODS, true) || !str_starts_with($path, '/api/')) {
            return;
        }
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $limit = $this->limiter->create('user-' . $user->getId())->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw ApiException::tooManyRequests('RATE_LIMITED', 'Trop d’actions en peu de temps. Réessayez dans quelques instants.', $retryAfter);
        }
    }
}
