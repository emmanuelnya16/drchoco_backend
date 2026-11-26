<?php

namespace App\Auth\Whatsapp;

use App\Account\PhoneNumber;
use App\Api\ApiException;
use App\Repository\WhatsappLoginRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Messages WhatsApp reçus par la boutique, transmis par Meta (WHATSAPP_OTP.md §4.3 et §4.4).
 *
 *  - Chaque envoi est authentifié par sa signature (X-Hub-Signature-256, HMAC du corps brut avec la clé secrète de
 *    l'app Meta) : sans elle, 401 et rien n'est lu.
 *  - Un message texte qui contient un code DRC-XXXXXX confirme la demande en attente qui porte ce code, seulement s'il
 *    vient du WhatsApp du numéro saisi dans l'app. Venu d'un autre numéro, il ne confirme rien : la demande reste en
 *    attente et l'app explique d'où envoyer le message.
 *  - Meta peut envoyer deux fois le même message : chacun n'est traité qu'une fois (identifiant wamid).
 *  - Les accusés de lecture et de livraison (statuses) et les messages qui ne sont pas du texte sont ignorés.
 */
final class WhatsappWebhook
{
    private const CODE_PATTERN = '/DRC[\s\-_.:]*(\d{6})(?!\d)/i';
    /** Message déjà traité (doublon envoyé par Meta) : souvenir gardé un jour. */
    private const SEEN_TTL_SECONDS = 86400;
    private const REPLY_INTERVAL_SECONDS = 3600;

    public const REPLY_CONFIRMED = "✅ Numéro vérifié. Retournez sur DR Choco : la connexion se fait toute seule.";
    public const REPLY_WRONG_SENDER = "Ce code a été demandé pour un autre numéro. Envoyez-le depuis le WhatsApp du numéro saisi sur DR Choco.";
    public const REPLY_UNKNOWN_CODE = "Ce code n’est plus valable. Recommencez la connexion sur DR Choco : un nouveau code s’affichera.";
    public const REPLY_HELP = "Ce numéro sert uniquement à vérifier votre numéro pour vous connecter à DR Choco. Pour toute question, écrivez à la boutique depuis le site.";

    public function __construct(
        private readonly WhatsappConfig $config,
        private readonly WhatsappLoginRequestRepository $requests,
        private readonly EntityManagerInterface $em,
        private readonly WhatsappReplies $replies,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {}

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

        $sender = PhoneNumber::normalizeCameroonMobile($from);
        $code = preg_match(self::CODE_PATTERN, $body, $match) === 1 ? 'DRC-' . $match[1] : null;
        $reply = null;

        if ($code === null) {
            $reply = self::REPLY_HELP;
        } else {
            $reply = $this->em->wrapInTransaction(function () use ($code, $sender, $messageId): ?string {
                $now = new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
                $pending = $this->requests->findPendingByCode($code, $now);
                if ($this->requests->isMessageKnown($messageId)) {
                    return null; // déjà traité (envoi en double pendant ce traitement)
                }
                if ($pending === []) {
                    return self::REPLY_UNKNOWN_CODE;
                }
                foreach ($pending as $request) {
                    if ($sender !== null && $request->getPhone() === $sender) {
                        $request->confirm($now, $messageId, WhatsappLogin::CONFIRMED_GRACE_SECONDS);
                        $this->logger->info('Numéro vérifié par WhatsApp : {phone}.', ['phone' => PhoneNumber::mask($sender)]);

                        return self::REPLY_CONFIRMED;
                    }
                }
                foreach ($pending as $request) {
                    $request->markWrongSender($now);
                }
                $this->logger->info('Code WhatsApp envoyé depuis un autre numéro que celui saisi ({phone}).', [
                    'phone' => PhoneNumber::mask($pending[0]->getPhone()),
                ]);

                return self::REPLY_WRONG_SENDER;
            });
        }

        $this->cache->save($seen->set(true)->expiresAfter(self::SEEN_TTL_SECONDS));
        if ($reply !== null && $this->mayReply($from, $reply)) {
            $this->replies->queue($from, $reply);
        }
    }

    /** Une confirmation part toujours ; une aide ou un refus, une fois par heure au plus pour un même expéditeur. */
    private function mayReply(string $from, string $reply): bool
    {
        if ($reply === self::REPLY_CONFIRMED) {
            return true;
        }
        $item = $this->cache->getItem('wa-reply-' . hash('sha256', $from . '|' . $reply));
        if ($item->isHit()) {
            return false;
        }
        $this->cache->save($item->set(true)->expiresAfter(self::REPLY_INTERVAL_SECONDS));

        return true;
    }
}
