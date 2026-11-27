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
        if ($this->config->verifyToken === '' || $mode !== 'subscribe' || $token === null || $challenge === null) {
            return null;
        }

        return hash_equals($this->config->verifyToken, $token) ? $challenge : null;
    }

    /**
     * @throws ApiException 401 (signature absente ou fausse) ou 503 (clé secrète non réglée)
     */
    public function handle(string $rawBody, ?string $signature): void
    {
        if ($this->config->appSecret === '') {
            $this->logger->critical('Message WhatsApp reçu mais WHATSAPP_APP_SECRET est vide : impossible de vérifier qu’il vient de Meta.');

            throw new ApiException(503, 'WHATSAPP_UNAVAILABLE', 'Service momentanément indisponible.');
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $this->config->appSecret);
        if ($signature === null || !hash_equals($expected, strtolower(trim($signature)))) {
            $this->logger->warning('Webhook WhatsApp refusé : signature absente ou fausse.');

            throw new ApiException(401, 'INVALID_SIGNATURE', 'Signature invalide.');
        }

        try {
            $payload = json_decode($rawBody, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->warning('Webhook WhatsApp signé mais illisible : ignoré.');

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
                    continue;
                }
                $value = $change['value'];
                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
                if ($this->config->phoneNumberId !== '' && $phoneNumberId !== $this->config->phoneNumberId) {
                    continue; // autre numéro du même compte Meta
                }
                foreach (\is_array($value['messages'] ?? null) ? $value['messages'] : [] as $message) {
                    if (!\is_array($message) || ($message['type'] ?? null) !== 'text') {
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
            return;
        }

        $reference = $this->referenceIn($body);
        $reply = $reference === null
            ? ['text' => self::REPLY_HELP, 'key' => 'help']
            : $this->em->wrapInTransaction(fn (): ?array => $this->answer($reference, $from, $messageId));

        $this->cache->save($seen->set(true)->expiresAfter(self::SEEN_TTL_SECONDS));
        if ($reply !== null && $this->mayReply($from, $reply['key'])) {
            $this->replies->queue($from, $reply['text']);
        }
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
            return ['text' => self::REPLY_ALREADY_SENT, 'key' => 'sent|' . $reference];
        }
        if ($request === null || !$request->isPending($now)) {
            return ['text' => self::REPLY_UNKNOWN, 'key' => 'unknown|' . $reference];
        }

        $phone = $request->getPhone();
        if (PhoneNumber::normalizeCameroonMobile($from) !== $phone) {
            $request->markWrongSender($now);
            $this->logger->info('Demande de code WhatsApp envoyée depuis un autre numéro que celui saisi ({phone}).', [
                'phone' => PhoneNumber::mask($phone),
            ]);

            return ['text' => self::wrongSenderMessage($phone), 'key' => 'wrong|' . $reference];
        }

        try {
            $issued = $this->otp->issueForWhatsapp($phone);
        } catch (ApiException $refused) {
            // Compte suspendu, trop de codes demandés, numéro verrouillé : la demande reste ouverte (nouvel essai
            // possible plus tard avec le même message).
            $text = $refused->getStatusCode() === 403 ? self::REPLY_BLOCKED : $refused->getMessage();

            return ['text' => $text, 'key' => 'refused|' . $reference];
        }

        $request->markCodeSent($now, $messageId);

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
