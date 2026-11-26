<?php

namespace App\Auth\Whatsapp;

use App\Account\PhoneNumber;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoi par l'API WhatsApp Cloud de Meta (POST /{phone-number-id}/messages). Délais courts : une panne de Meta
 * n'occupe pas les processus PHP de l'hébergement.
 */
final class WhatsappCloudMessenger implements WhatsappMessengerInterface
{
    public function __construct(
        private readonly WhatsappConfig $config,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(string $to, string $text): bool
    {
        if (!$this->config->canReply()) {
            return false;
        }

        try {
            $response = $this->httpClient->request('POST', $this->config->graphUrl($this->config->phoneNumberId . '/messages'), [
                'auth_bearer' => $this->config->token,
                'json'        => [
                    'messaging_product' => 'whatsapp',
                    'recipient_type'    => 'individual',
                    'to'                => ltrim($to, '+'),
                    'type'              => 'text',
                    'text'              => ['preview_url' => false, 'body' => $text],
                ],
                'timeout'      => 5,
                'max_duration' => 8,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return true;
            }

            $error = $response->toArray(false)['error'] ?? [];
            $this->logger->warning('Réponse WhatsApp non envoyée à {to} : {status} {code} {message}', [
                'to'      => PhoneNumber::mask('+' . ltrim($to, '+')),
                'status'  => $status,
                'code'    => \is_array($error) ? ($error['code'] ?? '') : '',
                'message' => \is_array($error) ? mb_substr((string) ($error['message'] ?? ''), 0, 200) : '',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Réponse WhatsApp non envoyée à {to} : {error}', [
                'to'    => PhoneNumber::mask('+' . ltrim($to, '+')),
                'error' => $e::class,
            ]);
        }

        return false;
    }
}
