<?php

namespace App\Tests\Fake;

use App\Push\PushResult;
use App\Push\PushTransportInterface;

/**
 * Faux envoi de notifications push (tests) : chaque envoi est enregistré ; la réponse du service de push est
 * choisie par le test (201 par défaut, ou une suite de codes : 410, 500, 429…).
 */
final class RecordingPushTransport implements PushTransportInterface
{
    /** @var list<array{endpoint: string, payload: array<string, mixed>, ttl: int, urgency: string, topic: ?string}> */
    private array $sent = [];

    /** @var list<int> réponses à donner, dans l'ordre (ensuite : 201) */
    private array $responses = [];

    public bool $configured = true;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(string $endpoint, string $p256dh, string $auth, string $contentEncoding, string $payload, int $ttl, string $urgency, ?string $topic): PushResult
    {
        $this->sent[] = ['endpoint' => $endpoint, 'payload' => json_decode($payload, true, 16, \JSON_THROW_ON_ERROR), 'ttl' => $ttl, 'urgency' => $urgency, 'topic' => $topic, 'bytes' => \strlen($payload)];

        return new PushResult(array_shift($this->responses) ?? 201);
    }

    /** @param list<int> $statuses */
    public function respondWith(array $statuses): void
    {
        $this->responses = $statuses;
    }

    /** @return list<array<string, mixed>> */
    public function sent(): array
    {
        return $this->sent;
    }

    /** @return list<array<string, mixed>> envois vers cet appareil */
    public function sentTo(string $endpoint): array
    {
        return array_values(array_filter($this->sent, fn (array $s) => $s['endpoint'] === $endpoint));
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->responses = [];
        $this->configured = true;
    }
}
