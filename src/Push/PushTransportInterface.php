<?php

namespace App\Push;

/**
 * Envoi d'une notification à un appareil (service de push du navigateur). Implémentations : WebPushTransport
 * (chiffrement + clés VAPID, `PUSH_TRANSPORT=webpush`), LogPushTransport (journal seulement, `PUSH_TRANSPORT=log`)
 * et, dans les tests, un faux qui enregistre les envois.
 */
interface PushTransportInterface
{
    /** Prêt à envoyer (clés VAPID présentes) ; sinon les notifications attendent dans la file. */
    public function isConfigured(): bool;

    public function send(string $endpoint, string $p256dh, string $auth, string $contentEncoding, string $payload, int $ttl, string $urgency, ?string $topic): PushResult;
}
