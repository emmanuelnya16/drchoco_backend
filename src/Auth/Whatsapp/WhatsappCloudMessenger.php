<?php

namespace App\Auth\Whatsapp;

use App\Account\PhoneNumber;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire(service: 'monolog.logger.whatsapp')]
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
                $this->logger->info('Réponse à {to} acceptée par Meta (la remise sur le téléphone suit dans le journal).', [
                    'to' => PhoneNumber::mask('+' . ltrim($to, '+')),
                ]);

                return true;
            }

            $error = $response->toArray(false)['error'] ?? [];
            $details = \is_array($error) && \is_array($error['error_data'] ?? null) ? (string) ($error['error_data']['details'] ?? '') : '';
            // 190 : jeton expiré ou faux ; 10 et 200 : permission manquante ; 100 : WHATSAPP_PHONE_NUMBER_ID faux ;
            // 131030 : destinataire hors de la liste des numéros de test ; 131047 : plus de 24 h depuis son message
            $this->logger->error('Réponse à {to} REFUSÉE par Meta (HTTP {status}) : erreur {code}{subcode} « {message} »{details}', [
                'to'      => PhoneNumber::mask('+' . ltrim($to, '+')),
                'status'  => $status,
                'code'    => \is_array($error) ? (string) ($error['code'] ?? '?') : '?',
                'subcode' => \is_array($error) && isset($error['error_subcode']) ? '/' . $error['error_subcode'] : '',
                'message' => \is_array($error) ? mb_substr((string) ($error['message'] ?? ''), 0, 200) : '',
                'details' => $details !== '' ? ' : ' . mb_substr($details, 0, 200) : '',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Réponse à {to} non envoyée : Meta injoignable ({error}).', [
                'to'    => PhoneNumber::mask('+' . ltrim($to, '+')),
                'error' => mb_substr($e::class . ' : ' . $e->getMessage(), 0, 300),
            ]);
        }

        return false;
    }
}
