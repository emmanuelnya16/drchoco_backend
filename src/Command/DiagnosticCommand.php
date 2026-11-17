<?php

namespace App\Command;

use App\Auth\SmsOtpSender;
use Cloudinary\Cloudinary;
use Doctrine\DBAL\Connection;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use App\Push\WebPushTransport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twilio\Rest\Client as TwilioClient;

/**
 * Vérifie que les services externes sont joignables, SANS rien envoyer :
 * base de données, clés JWT, Cloudinary (ping), Twilio (lecture du compte), envoi des codes de connexion,
 * PawaPay (interrupteur).
 *
 *   php bin/console app:diagnostic
 *
 * À lancer après chaque mise en ligne. Aucun secret n'est affiché.
 */
#[AsCommand(name: 'app:diagnostic', description: 'Vérifie la base, le JWT, Cloudinary, Twilio et PawaPay sans rien envoyer.')]
final class DiagnosticCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly JWTTokenManagerInterface $jwtManager,
        #[Autowire('%env(CLOUDINARY_URL)%')] private readonly string $cloudinaryUrl,
        #[Autowire('%env(TWILIO_ACCOUNT_SID)%')] private readonly string $twilioSid,
        #[Autowire('%env(TWILIO_AUTH_TOKEN)%')] private readonly string $twilioToken,
        #[Autowire('%env(TWILIO_FROM_NUMBER)%')] private readonly string $twilioFrom,
        #[Autowire('%env(bool:PAWAPAY_ENABLED)%')] private readonly bool $pawapayEnabled,
        private readonly SmsOtpSender $otpSender,
        #[Autowire('%env(int:OTP_DAILY_SMS_LIMIT)%')] private readonly int $dailySmsLimit,
        #[Autowire('%env(OTP_WEBOTP_HOST)%')] private readonly string $webOtpHost,
        #[Autowire('%env(PUSH_TRANSPORT)%')] private readonly string $pushTransport,
        private readonly WebPushTransport $webPush,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Diagnostic — API DR Choco');
        $rows = [];
        $failed = false;

        // Base de données
        try {
            $version = (string) $this->connection->executeQuery('SELECT VERSION()')->fetchOne();
            $rows[] = ['Base de données', 'OK', $this->connection->getDatabase() . ' — ' . $version];

            // Administration (P3) : sans propriétaire actif, personne ne peut gérer la boutique.
            $owners = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admins WHERE role = 'owner' AND is_active = 1");
            $sellers = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admins WHERE role = 'seller' AND is_active = 1");
            $rows[] = $owners > 0
                ? ['Comptes de l’équipe', 'OK', sprintf('%d propriétaire(s) actif(s), %d vendeur(s) actif(s)', $owners, $sellers)]
                : ['Comptes de l’équipe', 'ABSENT', 'Aucun propriétaire actif : php bin/console app:create-owner'];
            if ($owners === 0) {
                $failed = true;
            }
        } catch (\Throwable $e) {
            $rows[] = ['Base de données', 'ÉCHEC', $this->short($e)];
            $failed = true;
        }

        // JWT : signature puis lecture d'un jeton de test (clés et phrase de passe)
        try {
            $token = $this->jwtManager->createFromPayload(new \Symfony\Component\Security\Core\User\InMemoryUser('diagnostic', null), []);
            $this->jwtManager->parse($token);
            $rows[] = ['JWT (RS256)', 'OK', 'Signature et vérification fonctionnent'];
        } catch (\Throwable $e) {
            $rows[] = ['JWT (RS256)', 'ÉCHEC', $this->short($e)];
            $failed = true;
        }

        // Cloudinary : ping de l'API d'administration (aucun envoi d'image)
        if ($this->cloudinaryUrl === '') {
            $rows[] = ['Cloudinary', 'ABSENT', 'CLOUDINARY_URL vide'];
            $failed = true;
        } else {
            try {
                $cloudinary = new Cloudinary($this->cloudinaryUrl);
                $ping = $cloudinary->adminApi()->ping();
                $cloud = $cloudinary->configuration->cloud->cloudName;
                $rows[] = ['Cloudinary', ($ping['status'] ?? '') === 'ok' ? 'OK' : 'ÉCHEC', 'Compte « ' . $cloud . ' » joignable'];
            } catch (\Throwable $e) {
                $rows[] = ['Cloudinary', 'ÉCHEC', $this->short($e)];
                $failed = true;
            }
        }

        // Twilio : lecture du compte (type essai ou payant), aucun SMS envoyé
        if ($this->twilioSid === '' || $this->twilioToken === '') {
            $rows[] = ['Twilio (SMS)', 'ABSENT', 'Identifiants Twilio vides'];
        } else {
            try {
                $account = (new TwilioClient($this->twilioSid, $this->twilioToken))->api->v2010->accounts($this->twilioSid)->fetch();
                $type = $account->type === 'Trial'
                    ? 'compte d’ESSAI : SMS seulement vers les numéros vérifiés'
                    : 'compte payant';
                $from = str_starts_with($this->twilioFrom, '+') ? 'expéditeur ' . substr($this->twilioFrom, 0, 3) . '…' : 'expéditeur non numérique';
                $rows[] = ['Twilio (SMS)', $account->status === 'active' ? 'OK' : strtoupper((string) $account->status), $type . ', ' . $from];
            } catch (\Throwable $e) {
                $rows[] = ['Twilio (SMS)', 'ÉCHEC', $this->short($e)];
                $failed = true;
            }
        }

        // Codes de connexion (P2) : SMS Twilio, ou journal en développement (OTP_SMS_TRANSPORT=log).
        // Sans Twilio (et hors mode journal), aucun client ne peut se connecter : le diagnostic échoue.
        $logMode = $this->otpSender->usesLogTransport();
        $smsReady = $logMode || $this->otpSender->isTwilioConfigured();
        if (!$smsReady) {
            $failed = true;
        }
        $rows[] = [
            'Codes SMS (connexion)',
            $logMode ? 'JOURNAL' : ($smsReady ? 'SMS' : 'ÉCHEC'),
            ($logMode ? 'aucun SMS envoyé, code écrit dans var/log/dev.log' : ($smsReady ? 'envoyés par Twilio' : 'TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN ou TWILIO_FROM_NUMBER vide : aucun client ne peut se connecter'))
                . ', plafond ' . $this->dailySmsLimit . ' SMS par jour, remplissage auto. Android : '
                . ($this->webOtpHost !== '' ? $this->webOtpHost : 'non configuré (OTP_WEBOTP_HOST)'),
        ];

        // Notifications push (P7) : clés VAPID et chiffrement (OpenSSL doit pouvoir créer des clés EC P-256).
        if (strtolower(trim($this->pushTransport)) === 'log') {
            $rows[] = ['Notifications push', 'JOURNAL', 'aucune notification envoyée, écrites dans var/log (PUSH_TRANSPORT=log)'];
        } elseif (!$this->webPush->isConfigured()) {
            $rows[] = ['Notifications push', 'ABSENT', 'VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY ou VAPID_SUBJECT vide : app:push:vapid-keys --write=.env.local'];
        } elseif (($problem = $this->webPush->keysProblem()) !== null) {
            $failed = true;
            $rows[] = ['Notifications push', 'ÉCHEC', 'Clés VAPID invalides : ' . $problem];
        } elseif (@openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]) === false) {
            $failed = true;
            $rows[] = ['Notifications push', 'ÉCHEC', 'OpenSSL ne peut pas chiffrer les notifications (Windows/XAMPP : définir OPENSSL_CONF)'];
        } else {
            $rows[] = ['Notifications push', 'OK', 'clés VAPID présentes, chiffrement possible ; tâche app:push:send chaque minute'];
        }

        $rows[] = ['PawaPay', $this->pawapayEnabled ? 'ACTIVÉ' : 'DÉSACTIVÉ', $this->pawapayEnabled ? 'Paiement PawaPay possible' : 'Paiement par code USSD + capture (normal)'];

        $io->table(['Service', 'État', 'Détail'], $rows);

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function short(\Throwable $e): string
    {
        return mb_substr(\get_class($e) . ' : ' . $e->getMessage(), 0, 140);
    }
}
