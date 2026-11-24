<?php

namespace App\EventSubscriber;

use App\Api\ApiException;
use App\Api\RequestPath;
use App\Entity\Admin;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limite de débit des écritures d'un compte de l'équipe : 120 par minute (un jeton volé, ou une boucle, ne remplit pas
 * la boutique). Exécuté après le pare-feu : le compte est déjà identifié par son JWT.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class AdminWriteRateLimitSubscriber
{
    public function __construct(
        private readonly Security $security,
        #[Autowire(service: 'limiter.admin_writes')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest()
            || \in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
            || !str_starts_with(RequestPath::of($request), '/api/admin/')) {
            return;
        }

        $admin = $this->security->getUser();
        if (!$admin instanceof Admin) {
            return;
        }

        $limit = $this->limiter->create('admin-' . $admin->getId())->consume();
        if (!$limit->isAccepted()) {
            throw ApiException::tooManyRequests('RATE_LIMITED', 'Trop d’actions en peu de temps. Réessayez dans quelques instants.', max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }
    }
}
