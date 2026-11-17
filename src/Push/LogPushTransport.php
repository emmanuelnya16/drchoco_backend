<?php

namespace App\Push;

use Psr\Log\LoggerInterface;

/**
 * Développement (`PUSH_TRANSPORT=log`) : rien n'est envoyé, la notification est écrite dans le journal (type,
 * titre, texte, adresse ; jamais l'endpoint ni les clés de l'appareil).
 */
final class LogPushTransport implements PushTransportInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $endpoint, string $p256dh, string $auth, string $contentEncoding, string $payload, int $ttl, string $urgency, ?string $topic): PushResult
    {
        $this->logger->info('[DEV] Notification push non envoyée', [
            'service' => parse_url($endpoint, \PHP_URL_HOST),
            'payload' => json_decode($payload, true),
            'ttl'     => $ttl,
            'urgency' => $urgency,
        ]);

        return new PushResult(201);
    }
}
