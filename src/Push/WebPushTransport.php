<?php

namespace App\Push;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoi réel (minishlink/web-push) : message chiffré pour l'appareil (aes128gcm) et signé avec les clés VAPID
 * de la boutique. Délai court (4 s, 6 s au plus) : un service de push lent ne bloque pas la suite de la file.
 *
 * Windows/XAMPP : le chiffrement a besoin de `OPENSSL_CONF` (fichier openssl.cnf) dans l'environnement de PHP ;
 * sans lui, chaque envoi échoue (« Unable to create the local key ») et reste en file pour un nouvel essai.
 */
final class WebPushTransport implements PushTransportInterface
{
    private ?WebPush $webPush = null;

    public function __construct(
        #[Autowire('%env(string:default::VAPID_PUBLIC_KEY)%')]
        private readonly string $publicKey,
        #[Autowire('%env(string:default::VAPID_PRIVATE_KEY)%')]
        private readonly string $privateKey,
        #[Autowire('%env(string:default::VAPID_SUBJECT)%')]
        private readonly string $subject,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->publicKey) !== '' && trim($this->privateKey) !== '' && trim($this->subject) !== '';
    }

    /** Clés lisibles (format VAPID) : null, sinon la raison (diagnostic). */
    public function keysProblem(): ?string
    {
        try {
            VAPID::validate(['subject' => trim($this->subject), 'publicKey' => trim($this->publicKey), 'privateKey' => trim($this->privateKey)]);

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    public function send(string $endpoint, string $p256dh, string $auth, string $contentEncoding, string $payload, int $ttl, string $urgency, ?string $topic): PushResult
    {
        try {
            $client = $this->client();
        } catch (\Throwable $e) {
            // Clés VAPID illisibles (inversées, tronquées…) : aucune notification ne partira, alerte comme un refus.
            return new PushResult(401, null, mb_substr('Clés VAPID invalides : ' . $e->getMessage(), 0, 200));
        }

        try {
            $report = $client->sendOneNotification(
                Subscription::create(['endpoint' => $endpoint, 'publicKey' => $p256dh, 'authToken' => $auth, 'contentEncoding' => $contentEncoding]),
                $payload,
                array_filter(['TTL' => max(0, $ttl), 'urgency' => $urgency, 'topic' => $topic], fn ($v) => $v !== null),
            );
        } catch (\Throwable $e) {
            return new PushResult(0, null, mb_substr($e->getMessage(), 0, 200));
        }

        $response = $report->getResponse();
        if ($response === null) {
            return new PushResult(0, null, mb_substr($report->getReason(), 0, 200));
        }
        $retryAfter = $response->getHeaderLine('Retry-After');

        return new PushResult(
            $response->getStatusCode(),
            ctype_digit($retryAfter) ? (int) $retryAfter : null,
            $report->isSuccess() ? null : mb_substr($report->getReason(), 0, 200),
        );
    }

    private function client(): WebPush
    {
        if ($this->webPush === null) {
            $http = new Psr18Client($this->httpClient->withOptions(['timeout' => 4, 'max_duration' => 6]));
            $this->webPush = new WebPush(
                ['VAPID' => ['subject' => trim($this->subject), 'publicKey' => trim($this->publicKey), 'privateKey' => trim($this->privateKey)]],
                [],
                $http,
                $http,
                $http,
                null,
                $this->logger,
            );
            $this->webPush->setReuseVAPIDHeaders(true);
        }

        return $this->webPush;
    }
}
