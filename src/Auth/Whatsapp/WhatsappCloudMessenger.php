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
        return $this->deliver($to, $text) === null;
    }

    /**
     * Envoie le message ; null si Meta l'accepte, sinon la raison (aussi écrite dans le journal WhatsApp).
     * Utilisé tel quel par app:whatsapp:essai.
     */
    public function deliver(string $to, string $text): ?string
    {
        if (!$this->config->canReply()) {
            $this->logger->error('Réponse non envoyée : WHATSAPP_TOKEN ou WHATSAPP_PHONE_NUMBER_ID est vide.');

            return 'WHATSAPP_TOKEN ou WHATSAPP_PHONE_NUMBER_ID vide';
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

                return null;
            }

            $error = $response->toArray(false)['error'] ?? [];
            $details = \is_array($error) && \is_array($error['error_data'] ?? null) ? (string) ($error['error_data']['details'] ?? '') : '';
            $reason = sprintf(
                'HTTP %d, erreur %s%s « %s »%s',
                $status,
                \is_array($error) ? (string) ($error['code'] ?? '?') : '?',
                \is_array($error) && isset($error['error_subcode']) ? '/' . $error['error_subcode'] : '',
                \is_array($error) ? mb_substr((string) ($error['message'] ?? ''), 0, 200) : '',
                $details !== '' ? ' : ' . mb_substr($details, 0, 200) : '',
            );
            // 190 : jeton expiré ou faux ; 10 et 200 : permission manquante ; 100 : WHATSAPP_PHONE_NUMBER_ID faux ;
            // 131030 : destinataire hors de la liste des numéros de test ; 131047 : plus de 24 h depuis son message
            $this->logger->error('Réponse à {to} REFUSÉE par Meta ({reason})', [
                'to'     => PhoneNumber::mask('+' . ltrim($to, '+')),
                'reason' => $reason,
            ]);

            return $reason;
        } catch (\Throwable $e) {
            $reason = 'Meta injoignable : ' . mb_substr($e::class . ' : ' . $e->getMessage(), 0, 300);
            $this->logger->error('Réponse à {to} non envoyée : {reason}', [
                'to'     => PhoneNumber::mask('+' . ltrim($to, '+')),
                'reason' => $reason,
            ]);

            return $reason;
        }
    }
}
