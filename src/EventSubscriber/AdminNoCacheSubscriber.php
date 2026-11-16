<?php

namespace App\EventSubscriber;

use App\Api\RequestPath;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Contrat admin §1.3 : aucune réponse de /api/admin/* ne doit être gardée par un cache
 * (navigateur, intermédiaire, service worker), y compris les erreurs.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -20)]
final class AdminNoCacheSubscriber
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!str_starts_with(RequestPath::of($event->getRequest()), '/api/admin')) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $headers->set('Pragma', 'no-cache');
    }
}
