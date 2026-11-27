<?php

namespace App\Auth\Whatsapp;

use App\Account\PhoneNumber;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire(service: 'monolog.logger.whatsapp')]
        private readonly LoggerInterface $logger,
    ) {}

    public function queue(string $to, string $text): void
    {
        if (!$this->config->canReply()) {
            $this->logger->error('Réponse non envoyée : WHATSAPP_TOKEN ou WHATSAPP_PHONE_NUMBER_ID est vide.');

            return;
        }
        if (\count($this->queued) >= self::MAX_PER_REQUEST) {
            $this->logger->warning('Réponse à {to} non envoyée : plus de {max} réponses dans le même envoi de Meta.', [
                'to'  => PhoneNumber::mask('+' . ltrim($to, '+')),
                'max' => self::MAX_PER_REQUEST,
            ]);

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
                $this->logger->error('Réponse non envoyée : {error}', ['error' => mb_substr($e::class . ' : ' . $e->getMessage(), 0, 300)]);
            }
        }
    }
}
