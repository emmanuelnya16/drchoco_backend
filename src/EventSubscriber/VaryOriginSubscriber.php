<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Contrat §2.1 : une réponse CORS dépend de l'origine de la requête => « Vary: Origin »,
 * pour qu'aucun cache intermédiaire ne serve la réponse d'une origine à une autre.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final class VaryOriginSubscriber
{
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api') || !$request->headers->has('Origin')) {
            return;
        }

        $event->getResponse()->setVary('Origin', false);
    }
}
