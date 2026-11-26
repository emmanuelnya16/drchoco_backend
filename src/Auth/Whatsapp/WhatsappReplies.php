<?php

namespace App\Auth\Whatsapp;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Réponses aux messages reçus, envoyées APRÈS la réponse faite à Meta : le webhook doit répondre vite (sinon Meta le
 * renvoie, puis coupe l'abonnement). Avec PHP-FPM ou LiteSpeed, la réponse est déjà partie quand elles s'envoient.
 * Un échec d'envoi ne change rien à la connexion (journal seulement).
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
final class WhatsappReplies
{
    /** Au plus quelques réponses par requête : un lot de messages anormal ne retient pas le processus. */
    private const MAX_PER_REQUEST = 5;

    /** @var list<array{to: string, text: string}> */
    private array $queued = [];

    public function __construct(
        private readonly WhatsappMessengerInterface $messenger,
        private readonly WhatsappConfig $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function queue(string $to, string $text): void
    {
        if (!$this->config->canReply() || \count($this->queued) >= self::MAX_PER_REQUEST) {
            return;
        }
        $this->queued[] = ['to' => $to, 'text' => $text];
    }

    public function __invoke(TerminateEvent $event): void
    {
        $queued = $this->queued;
        $this->queued = [];
        if ($queued === []) {
            return;
        }
        ignore_user_abort(true);
        foreach ($queued as $reply) {
            try {
                $this->messenger->send($reply['to'], $reply['text']);
            } catch (\Throwable $e) {
                $this->logger->warning('Réponse WhatsApp non envoyée : {error}', ['error' => $e::class]);
            }
        }
    }
}
