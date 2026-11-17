<?php

namespace App\Push;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Envoi juste après la réponse (fin de la requête qui a mis des notifications en file) : 10 notifications et
 * 6 secondes au plus ; le reste part avec la tâche `app:push:send` (chaque minute). Avec PHP-FPM ou LiteSpeed, la
 * réponse est déjà partie ; sinon elle attend la fin de l'envoi (désactivable : `PUSH_SEND_ON_TERMINATE=0`).
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
final class PushTerminateListener
{
    public function __construct(
        private readonly PushQueue $queue,
        private readonly PushSender $sender,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:PUSH_SEND_ON_TERMINATE)%')]
        private readonly bool $enabled = true,
    ) {}

    public function __invoke(TerminateEvent $event): void
    {
        if (!$this->queue->hasQueued() || !$this->enabled) {
            return;
        }
        $this->queue->resetQueued();
        try {
            ignore_user_abort(true);
            $this->sender->sendDue(10, 6.0);
        } catch (\Throwable $e) {
            // Jamais d'erreur visible : les notifications restent en file pour la tâche planifiée.
            $this->logger->error('Envoi des notifications push après la réponse : échec.', ['exception' => $e]);
        }
    }
}
