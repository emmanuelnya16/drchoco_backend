<?php

namespace App\Auth\Whatsapp;

use App\Account\PhoneNumber;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Réponses aux messages reçus. Envoyées PENDANT le traitement de l'envoi de Meta, avant de lui répondre 200
 * (WhatsappWebhook::handle) : sur un hébergement mutualisé (cPanel), le travail fait après la réponse (kernel.terminate)
 * peut être interrompu sans trace. Délais courts (WhatsappCloudMessenger) : Meta reçoit sa réponse en quelques
 * secondes au plus ; s'il renvoie le même message, il n'est pas traité deux fois.
 * kernel.terminate reste un filet : envoie ce qui serait encore en attente.
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

    /** Envoie les réponses en attente (appelé à la fin du traitement de chaque envoi de Meta). */
    public function sendQueued(): void
    {
        $queued = $this->queued;
        $this->queued = [];
        foreach ($queued as $reply) {
            try {
                $this->messenger->send($reply['to'], $reply['text']);
            } catch (\Throwable $e) {
                $this->logger->error('Réponse non envoyée : {error}', ['error' => mb_substr($e::class . ' : ' . $e->getMessage(), 0, 300)]);
            }
        }
    }

    public function __invoke(TerminateEvent $event): void
    {
        if ($this->queued !== []) {
            ignore_user_abort(true);
            $this->sendQueued();
        }
    }
}
