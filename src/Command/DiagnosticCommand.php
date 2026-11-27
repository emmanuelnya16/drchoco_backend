<?php

namespace App\Command;

use App\Auth\SmsOtpSender;
use App\Auth\Whatsapp\WhatsappConfig;
use App\Auth\Whatsapp\WhatsappSubscription;
use App\Kernel;
use App\Seo\FrontIndex;
use App\Seo\SiteUrl;
use Cloudinary\Cloudinary;
use Doctrine\DBAL\Connection;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use App\Push\WebPushTransport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twilio\Rest\Client as TwilioClient;

/**
 * Vérifie que les services externes sont joignables, SANS rien envoyer :
 * base de données, clés JWT, Cloudinary (ping), Twilio (lecture du compte), envoi des codes de connexion,
 * PawaPay (interrupteur).
 *
 *   php bin/console app:diagnostic                sur cet ordinateur
 *   php bin/console app:diagnostic --production   sur le serveur : échoue si un réglage de mise en ligne manque
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
        private readonly SiteUrl $site,
        private readonly FrontIndex $front,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%env(CORS_ALLOW_ORIGIN)%')] private readonly string $corsAllowOrigin,
        #[Autowire('%env(default::SYMFONY_TRUSTED_PROXIES)%')] private readonly ?string $trustedProxies,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
        #[Autowire('%app.private_media_dir%')] private readonly string $privateMediaDir,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly WhatsappConfig $whatsapp,
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('production', null, InputOption::VALUE_NONE, 'Exige les réglages de mise en ligne (à lancer sur le serveur)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Diagnostic — API DR Choco');
        $rows = [];
        $failed = false;

        // Réglages de production (P9). En développement, seulement pour information, sauf avec --production.
        $online = (bool) $input->getOption('production');
        $production = $this->environment === 'prod';
        $rows[] = $production
            ? ['Environnement', $this->debug ? 'ÉCHEC' : 'OK', $this->debug ? 'APP_DEBUG=1 en production : les erreurs montrent le code' : 'production, sans mode debug']
            : ['Environnement', $online ? 'ÉCHEC' : 'DÉVELOPPEMENT', 'sur le serveur, mettre APP_ENV=prod dans .env.local'];
        $failed = $failed || ($production && $this->debug) || ($online && !$production);
        $strong = Kernel::isStrongSecret($this->secret);
        $rows[] = ['Secret (APP_SECRET)', $strong ? 'OK' : 'ÉCHEC', $strong ? 'présent' : 'absent, trop court ou publié : php -r "echo bin2hex(random_bytes(32));"'];
        $failed = $failed || !$strong;
        $index = $this->front->configuredPath() !== '' ? ($this->front->path() !== null ? 'index.html trouvé' : 'FRONT_INDEX_PATH introuvable') : 'index.html lu à la racine du site';
        $failed = $failed || $index === 'FRONT_INDEX_PATH introuvable';
        $insecure = $online && !str_starts_with($this->site->origin(), 'https://');
        $failed = $failed || $insecure;
        $rows[] = ['Site et partage', $insecure || $index === 'FRONT_INDEX_PATH introuvable' ? 'ÉCHEC' : 'OK', $this->site->origin() . ($insecure ? ' (SITE_URL doit commencer par https://)' : '') . ', ' . $index];
        $cors = $this->corsAllowOrigin !== '' && preg_match('{' . $this->corsAllowOrigin . '}i', 'http://localhost:3000') === 1;
        $failed = $failed || ($online && $cors);
        $rows[] = ['Origines (CORS)', ($production || $online) && $cors ? 'ÉCHEC' : 'OK', $cors ? 'localhost accepté : en production, CORS_ALLOW_ORIGIN=\'^https://drchoco\.com$\'' : 'localhost refusé'];
        $rows[] = ['Relais devant PHP', 'INFO', ($this->trustedProxies ?? '') !== '' ? 'SYMFONY_TRUSTED_PROXIES défini' : 'aucun (si l’hébergeur en a un, définir SYMFONY_TRUSTED_PROXIES : README)'];
        $permissions = $this->worldReadable();
        $failed = $failed || ($online && $permissions !== []);
        $rows[] = ['Droits des fichiers', $permissions === [] ? 'OK' : ($online ? 'ÉCHEC' : 'À CORRIGER'), $permissions === [] ? 'secrets lisibles du seul compte' : 'lisibles par d’autres comptes du serveur : chmod 600 ' . implode(' ', $permissions)];
        $free = \function_exists('disk_free_space') ? @disk_free_space(is_dir($this->privateMediaDir) ? $this->privateMediaDir : \dirname($this->privateMediaDir)) : false;
        $rows[] = ['Espace disque', $free === false || $free > 1_073_741_824 ? 'OK' : 'À SURVEILLER', $free === false ? 'inconnu' : sprintf('%.1f Go libres pour les captures et photos', $free / 1_073_741_824)];

        // Base de données
        try {
            $version = (string) $this->connection->executeQuery('SELECT VERSION()')->fetchOne();
            $rows[] = ['Base de données', 'OK', $this->connection->getDatabase() . ' — ' . $version];

            // Administration (P3) : sans propriétaire actif, personne ne peut gérer la boutique.
            $owners = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admins WHERE role = 'owner' AND is_active = 1");
            $sellers = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admins WHERE role = 'seller' AND is_active = 1");
            $rows[] = $owners > 0
                ? ['Comptes de l’équipe', 'OK', sprintf('%d propriétaire(s) actif(s), %d vendeur(s) actif(s)', $owners, $sellers)]
                : ['Comptes de l’équipe', 'ÉCHEC', 'Aucun propriétaire actif : php bin/console app:create-owner'];
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
            $rows[] = ['Cloudinary', 'ÉCHEC', 'CLOUDINARY_URL vide'];
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
        // Connexion par WhatsApp en place : le SMS n'est plus qu'un secours (clients sans WhatsApp sur ce numéro).
        $whatsappReady = $this->whatsapp->isLoginReady();
        if (!$smsReady && !$whatsappReady) {
            $failed = true;
        }
        $rows[] = $this->whatsappRow($online);
        $failed = $failed || end($rows)[1] === 'ÉCHEC';
        if ($whatsappReady) {
            $rows[] = $this->whatsappSubscriptionRow();
            $failed = $failed || end($rows)[1] === 'ÉCHEC';
        }
        $otpIncomplete = $online && ($logMode || $this->webOtpHost === '');
        $failed = $failed || $otpIncomplete;
        $rows[] = [
            'Codes SMS (connexion)',
            (!$smsReady && !$whatsappReady) || $otpIncomplete ? 'ÉCHEC' : ($logMode ? 'JOURNAL' : ($smsReady ? 'SMS' : 'ABSENT')),
            ($logMode ? 'aucun SMS envoyé, code écrit dans var/log/dev.log' : ($smsReady ? 'envoyés par Twilio' : 'TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN ou TWILIO_FROM_NUMBER vide : '
                . ($whatsappReady ? 'connexion par WhatsApp seulement' : 'aucun client ne peut se connecter')))
                . ', plafond ' . $this->dailySmsLimit . ' SMS par jour, remplissage auto. Android : '
                . ($this->webOtpHost !== '' ? $this->webOtpHost : 'non configuré (OTP_WEBOTP_HOST)'),
        ];

        // Notifications push (P7) : clés VAPID et chiffrement (OpenSSL doit pouvoir créer des clés EC P-256).
        // En production, PUSH_TRANSPORT=log est ignoré (ConfiguredPushTransport) : les clés sont vérifiées.
        if (!$production && strtolower(trim($this->pushTransport)) === 'log') {
            $failed = $failed || $online;
            $rows[] = ['Notifications push', $online ? 'ÉCHEC' : 'JOURNAL', 'aucune notification envoyée, écrites dans var/log (PUSH_TRANSPORT=log)'];
        } elseif (!$this->webPush->isConfigured()) {
            $failed = $failed || $online;
            $rows[] = ['Notifications push', $online ? 'ÉCHEC' : 'ABSENT', 'VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY ou VAPID_SUBJECT vide : app:push:vapid-keys --write=.env.local'];
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
        if ($failed) {
            $io->error('Au moins un réglage est à corriger (lignes ÉCHEC).');
        } else {
            $io->success('Aucun réglage manquant.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<string> fichiers secrets lisibles par un autre compte du serveur (Linux ; Windows : toujours vide) */
    private function worldReadable(): array
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            return [];
        }
        $found = [];
        foreach (['.env.local', 'config/jwt/private.pem'] as $file) {
            $mode = @fileperms($this->projectDir . '/' . $file);
            if ($mode !== false && ($mode & 0o004) !== 0) {
                $found[] = $file;
            }
        }

        return $found;
    }

    /**
     * Connexion par WhatsApp : réglages, puis lecture du numéro chez Meta avec le jeton (aucun message envoyé).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function whatsappRow(bool $online): array
    {
        $name = 'WhatsApp (connexion)';
        if (!$this->whatsapp->isLoginReady()) {
            return [$name, 'ABSENT', 'WHATSAPP_BUSINESS_NUMBER, WHATSAPP_APP_SECRET, WHATSAPP_TOKEN ou WHATSAPP_PHONE_NUMBER_ID vide : connexion par SMS seulement'];
        }
        if ($this->whatsapp->verifyToken === '') {
            return [$name, 'ÉCHEC', 'WHATSAPP_VERIFY_TOKEN vide : Meta ne peut pas enregistrer l’adresse de rappel'];
        }
        $webhook = $this->site->to('/api/webhooks/whatsapp');

        try {
            $response = $this->httpClient->request('GET', $this->whatsapp->graphUrl($this->whatsapp->phoneNumberId), [
                'auth_bearer' => $this->whatsapp->token,
                'query'       => ['fields' => 'display_phone_number,verified_name,quality_rating'],
                'timeout'     => 6,
            ]);
            $data = $response->toArray(false);
            if ($response->getStatusCode() !== 200) {
                $message = \is_array($data['error'] ?? null) ? (string) ($data['error']['message'] ?? '') : '';

                return [$name, 'ÉCHEC', sprintf('Meta refuse le jeton ou l’identifiant du numéro (%d) : %s', $response->getStatusCode(), mb_substr($message, 0, 160))];
            }

            return [$name, 'OK', sprintf('numéro %s « %s », qualité %s ; adresse de rappel %s', $data['display_phone_number'] ?? '?', $data['verified_name'] ?? '?', $data['quality_rating'] ?? '?', $webhook)];
        } catch (\Throwable $e) {
            return [$name, 'ÉCHEC', 'Meta injoignable : ' . $this->short($e)];
        }
    }

    /**
     * Messages reçus : Meta ne les transmet à l'adresse de rappel que si l'app est abonnée au compte WhatsApp Business
     * (WABA). Lecture seule, avec le jeton (GET /{WABA}/subscribed_apps).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function whatsappSubscriptionRow(): array
    {
        $name = 'WhatsApp (messages reçus)';
        $state = WhatsappSubscription::read($this->httpClient, $this->whatsapp);

        return match ($state['status']) {
            'subscribed'     => [$name, 'OK', 'app abonnée au compte WhatsApp (' . implode(', ', $state['apps']) . ') ; vérifier aussi chez Meta : champ « messages » abonné. Journal : php bin/console app:whatsapp:journal'],
            'not_subscribed' => [$name, 'ÉCHEC', 'aucune app abonnée au compte WhatsApp : Meta ne transmet AUCUN message reçu. Corriger : php bin/console app:whatsapp:abonner'],
            'no_account'     => [$name, 'À VÉRIFIER', 'WHATSAPP_BUSINESS_ACCOUNT_ID vide : abonnement non vérifié'],
            default          => [$name, 'À VÉRIFIER', 'lecture impossible : ' . $state['detail']],
        };
    }

    private function short(\Throwable $e): string
    {
        return mb_substr(\get_class($e) . ' : ' . $e->getMessage(), 0, 140);
    }
}
