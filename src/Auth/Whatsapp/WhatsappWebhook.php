<?php

namespace App\Auth\Whatsapp;

use App\Account\PhoneNumber;
use App\Api\ApiException;
use App\Auth\OtpService;
use App\Repository\WhatsappLoginRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Messages WhatsApp reçus par la boutique, transmis par Meta (WHATSAPP_OTP.md).
 *
 *  - Chaque envoi est authentifié par sa signature (X-Hub-Signature-256, HMAC du corps brut avec la clé secrète de
 *    l'app Meta) : sans elle, 401 et rien n'est lu.
 *  - Un message texte qui contient la référence d'une demande en cours (« Réf. K7F2QX ») reçoit en réponse un code à
 *    6 chiffres, seulement s'il vient du WhatsApp du numéro saisi dans l'app. Venu d'un autre WhatsApp, il reçoit une
 *    explication et aucun code : la référence seule ne prouve pas que l'on possède le numéro.
 *  - Une demande ne donne qu'un code. Meta peut envoyer deux fois le même message : chacun n'est traité qu'une fois
 *    (identifiant wamid).
 *  - Les accusés de lecture et de livraison (statuses) et les messages qui ne sont pas du texte sont ignorés.
 */
final class WhatsappWebhook
{
    /** « Réf. K7F2QX », « ref K7F2QX », « Référence : K7F2QX »… (la référence est vérifiée ensuite). */
    private const REFERENCE_PATTERN = '/R[EÉ]F(?:[EÉ]RENCE)?[\s.:#\-]*([A-Z0-9]{6})(?![A-Z0-9])/iu';
    /** Message déjà traité (doublon envoyé par Meta) : souvenir gardé un jour. */
    private const SEEN_TTL_SECONDS = 86400;
    /** Une même aide ou un même refus n'est répété qu'une fois par heure au même expéditeur. */
    private const REPLY_INTERVAL_SECONDS = 3600;

    public const REPLY_HELP = "Bonjour ! Ce numéro envoie les codes de connexion DR Choco. Pour recevoir le vôtre, appuyez sur « Recevoir mon code sur WhatsApp » dans l’application. Pour toute autre question, écrivez à la boutique depuis le site.";
    public const REPLY_UNKNOWN = "Cette demande de code n’est plus valable. Recommencez la connexion dans l’application DR Choco pour recevoir un nouveau code.";
    public const REPLY_ALREADY_SENT = "Votre code vous a déjà été envoyé juste au-dessus. S’il a expiré, recommencez la connexion dans l’application DR Choco.";
    public const REPLY_BLOCKED = "Ce compte DR Choco est suspendu. Pour en savoir plus, contactez la boutique.";

    public function __construct(
        private readonly WhatsappConfig $config,
        private readonly WhatsappLoginRequestRepository $requests,
        private readonly OtpService $otp,
        private readonly EntityManagerInterface $em,
        private readonly WhatsappReplies $replies,
        private readonly ClockInterface $clock,
        // Journal var/log/whatsapp-AAAA-MM-JJ.log : jamais le code, ni le texte des messages ; numéros masqués
        #[Autowire(service: 'monolog.logger.whatsapp')]
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {}

    /** Réponse avec le code (le code seul en gras : facile à lire et à copier). */
    public static function codeMessage(string $code, int $expiresIn = OtpService::CODE_TTL_SECONDS): string
    {
        return sprintf(
            "Votre code de connexion DR Choco : *%s*\n\nEntrez-le dans l’application. Il est valable %d minutes.\nNe le donnez à personne, même à quelqu’un qui dit être de DR Choco.",
            $code,
            max(1, intdiv($expiresIn, 60)),
        );
    }

    /** Référence envoyée depuis un autre WhatsApp que celui du numéro saisi. */
    public static function wrongSenderMessage(string $phone): string
    {
        $shown = self::display($phone);

        return sprintf(
            "Ce code a été demandé pour le %s, mais votre message vient d’un autre numéro WhatsApp. Envoyez le message depuis le WhatsApp du %s, ou choisissez « Recevoir par SMS » dans l’application DR Choco.",
            $shown,
            $shown,
        );
    }

    /** Vérification de l'adresse par Meta (GET) : le défi à renvoyer, ou null si le jeton ne correspond pas. */
    public function verifySubscription(?string $mode, ?string $token, ?string $challenge): ?string
    {
        if ($mode !== 'subscribe' || $token === null || $challenge === null) {
            $this->logger->info('Adresse de rappel ouverte sans les paramètres de Meta (navigateur ?) : 403.');

            return null;
        }
        if ($this->config->verifyToken === '') {
            $this->logger->error('Vérification de l’adresse de rappel par Meta refusée : WHATSAPP_VERIFY_TOKEN est vide dans .env.local.');

            return null;
        }
        if (!hash_equals($this->config->verifyToken, $token)) {
            $this->logger->warning('Vérification de l’adresse de rappel par Meta refusée : le « jeton de vérification » saisi chez Meta est différent de WHATSAPP_VERIFY_TOKEN.');

            return null;
        }
        $this->logger->info('Adresse de rappel vérifiée par Meta (« Vérifier et enregistrer ») : OK.');

        return $challenge;
    }

    /**
     * @throws ApiException 401 (signature absente ou fausse) ou 503 (clé secrète non réglée)
     */
    public function handle(string $rawBody, ?string $signature): void
    {
        $this->logger->info('Envoi reçu de Meta ({bytes} octets).', ['bytes' => \strlen($rawBody)]);
        if ($this->config->appSecret === '') {
            $this->logger->critical('Envoi refusé (503) : WHATSAPP_APP_SECRET est vide dans .env.local, impossible de vérifier qu’il vient de Meta.');

            throw new ApiException(503, 'WHATSAPP_UNAVAILABLE', 'Service momentanément indisponible.');
        }
        if ($signature === null || trim($signature) === '') {
            $this->logger->warning('Envoi refusé (401) : aucune signature X-Hub-Signature-256. Il ne vient pas de Meta.');

            throw new ApiException(401, 'INVALID_SIGNATURE', 'Signature invalide.');
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $this->config->appSecret);
        if (!hash_equals($expected, strtolower(trim($signature)))) {
            $this->logger->warning('Envoi refusé (401) : signature fausse. WHATSAPP_APP_SECRET ne correspond pas à la « Clé secrète » de l’app Meta (Paramètres de l’app > Général), ou l’envoi ne vient pas de Meta.');

            throw new ApiException(401, 'INVALID_SIGNATURE', 'Signature invalide.');
        }

        try {
            $payload = json_decode($rawBody, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->warning('Envoi signé mais illisible (pas du JSON) : ignoré.');

            return;
        }

        foreach ($this->textMessages($payload) as $message) {
            $this->process($message['id'], $message['from'], $message['body']);
        }
    }

    /**
     * Messages texte du numéro de la boutique contenus dans l'envoi.
     *
     * @return list<array{id: string, from: string, body: string}>
     */
    private function textMessages(mixed $payload): array
    {
        $messages = [];
        foreach ((\is_array($payload) ? $payload['entry'] ?? [] : []) as $entry) {
            foreach ((\is_array($entry) ? $entry['changes'] ?? [] : []) as $change) {
                if (!\is_array($change) || ($change['field'] ?? null) !== 'messages' || !\is_array($change['value'] ?? null)) {
                    $this->logger->info('Notification Meta « {field} » ignorée (seul le champ « messages » est lu).', [
                        'field' => \is_array($change) ? mb_substr((string) ($change['field'] ?? '?'), 0, 60) : '?',
                    ]);
                    continue;
                }
                $value = $change['value'];
                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
                if ($this->config->phoneNumberId !== '' && $phoneNumberId !== $this->config->phoneNumberId) {
                    // Autre numéro du même compte Meta, ou WHATSAPP_PHONE_NUMBER_ID faux
                    $this->logger->warning('Envoi ignoré : il concerne le numéro Meta {received}, alors que WHATSAPP_PHONE_NUMBER_ID vaut {configured}.', [
                        'received'   => mb_substr($phoneNumberId, 0, 40),
                        'configured' => $this->config->phoneNumberId,
                    ]);
                    continue;
                }
                $this->logStatuses($value['statuses'] ?? null);
                foreach (\is_array($value['errors'] ?? null) ? $value['errors'] : [] as $error) {
                    $this->logger->warning('Erreur signalée par Meta : {error}', ['error' => self::describeError($error)]);
                }
                foreach (\is_array($value['messages'] ?? null) ? $value['messages'] : [] as $message) {
                    if (!\is_array($message) || ($message['type'] ?? null) !== 'text') {
                        $this->logger->info('Message de {from} ignoré : type « {type} » (seuls les messages texte sont lus).', [
                            'from' => self::maskWa(\is_array($message) ? $message['from'] ?? null : null),
                            'type' => \is_array($message) ? mb_substr((string) ($message['type'] ?? '?'), 0, 30) : '?',
                        ]);
                        continue;
                    }
                    $id = $message['id'] ?? null;
                    $from = $message['from'] ?? null;
                    $body = $message['text']['body'] ?? null;
                    if (\is_string($id) && $id !== '' && \is_string($from) && \is_string($body)) {
                        $messages[] = ['id' => mb_substr($id, 0, 128), 'from' => $from, 'body' => mb_substr($body, 0, 1000)];
                    }
                }
            }
        }

        return $messages;
    }

    private function process(string $messageId, string $from, string $body): void
    {
        $seen = $this->cache->getItem('wa-msg-' . hash('sha256', $messageId));
        if ($seen->isHit()) {
            $this->logger->info('Message de {from} déjà traité (Meta l’a envoyé deux fois) : ignoré.', ['from' => self::maskWa($from)]);

            return;
        }

        $reference = $this->referenceIn($body);
        if ($reference === null) {
            $this->logger->info('Message texte de {from} sans référence de connexion : réponse d’aide.', ['from' => self::maskWa($from)]);
        } else {
            $this->logger->info('Message texte de {from} avec la référence {reference}.', [
                'from'      => self::maskWa($from),
                'reference' => $reference !== '' ? $reference : '(illisible)',
            ]);
        }
        $reply = $reference === null
            ? ['text' => self::REPLY_HELP, 'key' => 'help']
            : $this->em->wrapInTransaction(fn (): ?array => $this->answer($reference, $from, $messageId));

        $this->cache->save($seen->set(true)->expiresAfter(self::SEEN_TTL_SECONDS));
        if ($reply === null) {
            return;
        }
        if ($this->mayReply($from, $reply['key'])) {
            $this->replies->queue($from, $reply['text']);
        } else {
            $this->logger->info('Même réponse déjà envoyée à {from} il y a moins d’une heure : pas de nouvelle réponse.', ['from' => self::maskWa($from)]);
        }
    }

    /** Accusés de Meta sur les réponses de la boutique : envoyée, remise, lue, ou échec (avec la raison). */
    private function logStatuses(mixed $statuses): void
    {
        foreach (\is_array($statuses) ? $statuses : [] as $status) {
            if (!\is_array($status)) {
                continue;
            }
            $to = self::maskWa($status['recipient_id'] ?? null);
            $state = (string) ($status['status'] ?? '?');
            if ($state !== 'failed') {
                $this->logger->info('Réponse à {to} : {status}.', [
                    'to'     => $to,
                    'status' => match ($state) {
                        'sent'      => 'envoyée',
                        'delivered' => 'remise sur le téléphone',
                        'read'      => 'lue',
                        default     => mb_substr($state, 0, 30),
                    },
                ]);
                continue;
            }
            $errors = \is_array($status['errors'] ?? null) ? $status['errors'] : [];
            $this->logger->warning('Réponse à {to} NON REMISE par Meta : {error}', [
                'to'    => $to,
                'error' => $errors === [] ? 'raison non précisée' : implode(' ; ', array_map(self::describeError(...), $errors)),
            ]);
        }
    }

    /** « 131047 Re-engagement message : détails » à partir d'une erreur de Meta. */
    private static function describeError(mixed $error): string
    {
        if (!\is_array($error)) {
            return '?';
        }
        $details = \is_array($error['error_data'] ?? null) ? (string) ($error['error_data']['details'] ?? '') : '';
        $text = trim(sprintf(
            '%s %s%s',
            (string) ($error['code'] ?? ''),
            (string) ($error['title'] ?? $error['message'] ?? ''),
            $details !== '' ? ' : ' . $details : '',
        ));

        return mb_substr($text !== '' ? $text : '?', 0, 300);
    }

    /** Numéro WhatsApp tel que Meta le transmet (« 237690000001 »), masqué pour le journal : « +2376****0001 ». */
    private static function maskWa(mixed $waId): string
    {
        $digits = preg_replace('/\D/', '', \is_scalar($waId) ? (string) $waId : '') ?? '';

        return $digits === '' ? '(numéro inconnu)' : PhoneNumber::mask('+' . $digits);
    }

    /**
     * Réponse au message qui porte cette référence : le code, ou l'explication du refus.
     *
     * @return array{text: string, key: ?string}|null null : message déjà traité ; clé null : réponse toujours envoyée
     */
    private function answer(string $reference, string $from, string $messageId): ?array
    {
        if ($this->requests->isMessageKnown($messageId)) {
            return null; // déjà traité (envoi en double pendant ce traitement)
        }

        $now = new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
        $request = $this->requests->findByReferenceForUpdate($reference);
        if ($request !== null && $request->isCodeSent()) {
            $this->logger->info('Référence {reference} : le code de cette demande a déjà été envoyé, rappel envoyé.', ['reference' => $reference]);

            return ['text' => self::REPLY_ALREADY_SENT, 'key' => 'sent|' . $reference];
        }
        if ($request === null) {
            $this->logger->warning('Référence {reference} inconnue : aucune demande enregistrée (l’app n’a pas appelé /api/auth/whatsapp/start sur cette API, ou référence mal recopiée). Aucun code.', [
                'reference' => $reference !== '' ? $reference : '(illisible)',
            ]);

            return ['text' => self::REPLY_UNKNOWN, 'key' => 'unknown|' . $reference];
        }
        if (!$request->isPending($now)) {
            $this->logger->info('Référence {reference} expirée (plus de 10 min) ou remplacée par une demande plus récente : aucun code.', ['reference' => $reference]);

            return ['text' => self::REPLY_UNKNOWN, 'key' => 'unknown|' . $reference];
        }

        $phone = $request->getPhone();
        if (PhoneNumber::normalizeCameroonMobile($from) !== $phone) {
            $request->markWrongSender($now);
            $this->logger->warning('Référence {reference} demandée pour {phone}, mais le message vient d’un autre WhatsApp ({from}) : aucun code, explication envoyée.', [
                'reference' => $reference,
                'phone'     => PhoneNumber::mask($phone),
                'from'      => self::maskWa($from),
            ]);

            return ['text' => self::wrongSenderMessage($phone), 'key' => 'wrong|' . $reference];
        }

        try {
            $issued = $this->otp->issueForWhatsapp($phone);
        } catch (ApiException $refused) {
            // Compte suspendu, trop de codes demandés, numéro verrouillé : la demande reste ouverte (nouvel essai
            // possible plus tard avec le même message).
            $text = $refused->getStatusCode() === 403 ? self::REPLY_BLOCKED : $refused->getMessage();
            $this->logger->warning('Référence {reference} ({phone}) : code refusé ({status} : {reason}).', [
                'reference' => $reference,
                'phone'     => PhoneNumber::mask($phone),
                'status'    => $refused->getStatusCode(),
                'reason'    => $refused->getMessage(),
            ]);

            return ['text' => $text, 'key' => 'refused|' . $reference];
        }

        $request->markCodeSent($now, $messageId);
        $this->logger->info('Référence {reference} : code créé pour {phone} (valable {minutes} min), envoi de la réponse.', [
            'reference' => $reference,
            'phone'     => PhoneNumber::mask($phone),
            'minutes'   => max(1, intdiv($issued['expiresIn'], 60)),
        ]);

        return ['text' => self::codeMessage($issued['code'], $issued['expiresIn']), 'key' => null];
    }

    /** Référence au bon format contenue dans le message, ou null. */
    private function referenceIn(string $body): ?string
    {
        if (preg_match(self::REFERENCE_PATTERN, $body, $match) === 1) {
            return WhatsappConfig::normalizeReference($match[1]) ?? '';
        }

        // Référence seule, recopiée à la main
        return WhatsappConfig::normalizeReference($body);
    }

    /** Le code part toujours ; une aide ou un refus, une fois par heure au plus pour un même expéditeur. */
    private function mayReply(string $from, ?string $key): bool
    {
        if ($key === null) {
            return true;
        }
        $item = $this->cache->getItem('wa-reply-' . hash('sha256', $from . '|' . $key));
        if ($item->isHit()) {
            return false;
        }
        $this->cache->save($item->set(true)->expiresAfter(self::REPLY_INTERVAL_SECONDS));

        return true;
    }

    /** « 6 99 12 34 56 » */
    private static function display(string $phone): string
    {
        $digits = substr($phone, -9);

        return sprintf('%s %s %s %s %s', $digits[0], substr($digits, 1, 2), substr($digits, 3, 2), substr($digits, 5, 2), substr($digits, 7, 2));
    }
}
