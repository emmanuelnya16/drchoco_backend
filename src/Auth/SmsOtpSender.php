<?php

namespace App\Auth;

use App\Account\PhoneNumber;
use App\Service\Notification\SmsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Envoie le code de connexion par SMS avec le service Twilio repris du backend de référence
 * (SmsService::send(), inchangé).
 *
 * OTP_SMS_TRANSPORT=log (développement uniquement) : aucun SMS n'est envoyé, le code est écrit dans
 * var/log/dev.log. Pratique avec le compte Twilio d'essai, qui n'envoie qu'aux numéros vérifiés.
 * En production, ce réglage est ignoré : le code part toujours par SMS.
 */
final class SmsOtpSender implements OtpSenderInterface
{
    public const TRANSPORT_TWILIO = 'twilio';
    public const TRANSPORT_LOG    = 'log';

    public function __construct(
        #[Autowire(lazy: true)]
        private readonly SmsService $sms,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(OTP_SMS_TRANSPORT)%')]
        private readonly string $transport,
        #[Autowire('%env(OTP_WEBOTP_HOST)%')]
        private readonly string $webOtpHost,
        private readonly string $appEnv,
        #[Autowire('%env(TWILIO_ACCOUNT_SID)%')]
        private readonly string $twilioSid = '',
        #[Autowire('%env(TWILIO_AUTH_TOKEN)%')]
        private readonly string $twilioToken = '',
        #[Autowire('%env(TWILIO_FROM_NUMBER)%')]
        private readonly string $twilioFrom = '',
    ) {}

    /** Ne lève jamais d'exception : un échec renvoie false (l'API répond alors 503 SMS_UNAVAILABLE). */
    public function send(string $phone, string $code): bool
    {
        $message = self::message($code, $this->webOtpHost);

        if ($this->usesLogTransport()) {
            $this->logger->warning('[DEV] SMS non envoyé (OTP_SMS_TRANSPORT=log). Code de connexion pour {phone} : {code}', [
                'phone' => $phone,
                'code'  => $code,
            ]);

            return true;
        }

        // Niveau « error » : l'échec apparaît dans le journal de production (var/log/prod.log).
        if (!$this->isTwilioConfigured()) {
            $this->logger->error('Code de connexion non envoyé à {phone} : identifiants Twilio absents (TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_FROM_NUMBER).', [
                'phone' => PhoneNumber::mask($phone),
            ]);

            return false;
        }

        try {
            $sent = $this->sms->send($phone, $message);
        } catch (\Throwable $e) {
            $this->logger->error('Code de connexion non envoyé à {phone} : erreur {error}.', [
                'phone' => PhoneNumber::mask($phone),
                'error' => $e::class,
                'exception' => $e,
            ]);

            return false;
        }

        if (!$sent) {
            $this->logger->error('Code de connexion non envoyé par Twilio à {phone}. Causes possibles : numéro non vérifié (compte d’essai), crédit épuisé, envoi vers le Cameroun non autorisé, identifiants invalides. Raison exacte dans le journal PHP (error_log).', [
                'phone' => PhoneNumber::mask($phone),
            ]);
        }

        return $sent;
    }

    public function usesLogTransport(): bool
    {
        return strtolower(trim($this->transport)) === self::TRANSPORT_LOG && $this->appEnv !== 'prod';
    }

    public function isTwilioConfigured(): bool
    {
        return trim($this->twilioSid) !== '' && trim($this->twilioToken) !== '' && trim($this->twilioFrom) !== '';
    }

    /**
     * Texte du SMS. Uniquement des caractères de l'alphabet SMS de base (GSM 7 bits, sans apostrophe
     * typographique) : le message tient en un seul SMS de 160 caractères, même avec la mention ajoutée
     * par un compte Twilio d'essai.
     *
     * La dernière ligne « @<domaine> #<code> » permet le remplissage automatique sur Android (WebOTP,
     * contrat §4.1) ; elle est omise tant que le domaine de l'application n'est pas connu.
     */
    public static function message(string $code, string $webOtpHost = ''): string
    {
        $text = sprintf('DR Choco : votre code de connexion est %s. Valable 5 min. Ne le donnez à personne.', $code);

        $host = strtolower(trim($webOtpHost));
        if ($host !== '' && preg_match('/^[a-z0-9.-]+(:\d+)?\z/', $host) === 1) {
            $text .= "\n\n@" . $host . ' #' . $code;
        }

        return $text;
    }
}
