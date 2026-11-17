<?php

namespace App\Push;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Choisit l'envoi selon `PUSH_TRANSPORT` : `webpush` (réel, par défaut) ou `log` (journal, développement).
 */
#[AsAlias(PushTransportInterface::class)]
final class ConfiguredPushTransport implements PushTransportInterface
{
    public function __construct(
        #[Autowire('%env(PUSH_TRANSPORT)%')]
        private readonly string $transport,
        private readonly WebPushTransport $webPush,
        private readonly LogPushTransport $log,
    ) {}

    public function isConfigured(): bool
    {
        return $this->inner()->isConfigured();
    }

    public function send(string $endpoint, string $p256dh, string $auth, string $contentEncoding, string $payload, int $ttl, string $urgency, ?string $topic): PushResult
    {
        return $this->inner()->send($endpoint, $p256dh, $auth, $contentEncoding, $payload, $ttl, $urgency, $topic);
    }

    private function inner(): PushTransportInterface
    {
        return strtolower(trim($this->transport)) === 'log' ? $this->log : $this->webPush;
    }
}
