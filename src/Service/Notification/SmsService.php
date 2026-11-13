<?php

namespace App\Service\Notification;

use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client as TwilioClient;

/**
 * Envoi de SMS via Twilio — repris du backend de référence.
 *
 * Gardé à l'identique : send() (envoi direct, ne lève jamais d'exception, renvoie true/false)
 * et la normalisation des numéros +237. Les messages liés aux commandes « Donald Gros »
 * sont retirés ; les messages DR Choco (code de connexion, alertes boutique) arrivent en P2/P6.
 *
 * IMPORTANT — compte Twilio d'essai : les SMS ne partent que vers des numéros vérifiés
 * dans la console Twilio. À vérifier avant la mise en ligne.
 */
class SmsService
{
    private TwilioClient $client;

    public function __construct(
        private readonly string $accountSid,
        private readonly string $authToken,
        private readonly string $fromNumber,    // numéro ou expéditeur Twilio
        private readonly string $adminPhone,    // numéro de la boutique à prévenir
    ) {
        $this->client = new TwilioClient($this->accountSid, $this->authToken);
    }

    /**
     * Envoie un SMS brut. Retourne true si l'envoi a réussi.
     * Ne lève jamais d'exception : un échec SMS ne doit jamais bloquer le flux principal.
     */
    public function send(string $toPhone, string $message): bool
    {
        try {
            $this->client->messages->create(
                $this->normalizePhone($toPhone),
                [
                    'from' => $this->fromNumber,
                    'body' => $message,
                ]
            );

            return true;
        } catch (TwilioException $e) {
            error_log('Échec envoi SMS Twilio : ' . $e->getMessage());

            return false;
        } catch (\Exception $e) {
            error_log('Erreur inattendue SMS : ' . $e->getMessage());

            return false;
        }
    }

    /** Alerte SMS à la boutique (numéro ADMIN_PHONE_NUMBER). */
    public function notifyAdmin(string $message): bool
    {
        if ($this->adminPhone === '') {
            return false;
        }

        return $this->send($this->adminPhone, $message);
    }

    /** Normalise le téléphone au format international requis par Twilio : +237XXXXXXXXX */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', $phone);
        if (str_starts_with($phone, '+237')) return $phone;
        if (str_starts_with($phone, '237'))  return '+' . $phone;
        if (str_starts_with($phone, '+'))    return $phone; // autre pays
        return '+237' . $phone;
    }
}
