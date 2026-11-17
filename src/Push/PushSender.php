<?php

namespace App\Push;

use App\Entity\PushOutbox;
use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Envoi des notifications en file (SQL seulement : utilisable après la réponse, même si l'EntityManager est fermé).
 *
 * Les lignes sont réservées par lot (`claim`) avant l'envoi : deux envois en même temps (fin de requête et tâche
 * planifiée) ne prennent jamais la même ligne. Réponse du service de push :
 *  - 2xx : envoyée ;
 *  - 404/410 : appareil désabonné, supprimé (ses lignes en attente avec lui) ;
 *  - 429, 5xx, réseau : nouvel essai après 1, 5, 15 puis 60 min (5 essais au plus, jamais après la durée de vie) ;
 *  - 401/403 : clés VAPID refusées, échec et alerte (l'appareil est gardé) ;
 *  - autre : échec (contenu refusé).
 */
class PushSender
{
    public const MAX_ATTEMPTS = 5;
    private const BACKOFF = [60, 300, 900, 3600];
    private const CLAIM_SECONDS = 120;
    private const BATCH = 10;

    private bool $warnedNotConfigured = false;

    public function __construct(
        private readonly Connection $db,
        private readonly PushTransportInterface $transport,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Envoie les notifications dues, dans la limite d'un nombre et d'une durée.
     *
     * @return array{sent: int, retried: int, failed: int, removed: int, dropped: int}
     */
    public function sendDue(int $limit = 500, float $seconds = 50.0): array
    {
        $stats = ['sent' => 0, 'retried' => 0, 'failed' => 0, 'removed' => 0, 'dropped' => 0];
        if (!$this->transport->isConfigured()) {
            if (!$this->warnedNotConfigured) {
                $this->warnedNotConfigured = true;
                $this->logger->warning('Notifications push non envoyées : clés VAPID absentes (VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT).');
            }

            return $stats;
        }

        $deadline = microtime(true) + $seconds;
        $done = 0;
        while ($done < $limit && microtime(true) < $deadline) {
            $rows = $this->claim(min(self::BATCH, $limit - $done));
            if ($rows === []) {
                break;
            }
            foreach ($rows as $index => $row) {
                if (microtime(true) >= $deadline) {
                    $this->release(\array_slice($rows, $index)); // pas le temps : rendues tout de suite à la file
                    break 2;
                }
                ++$stats[$this->deliver($row)];
                ++$done;
            }
        }

        return $stats;
    }

    /** @return list<array<string, mixed>> lignes réservées pour cet envoi */
    private function claim(int $count): array
    {
        $now = $this->now();
        $claim = bin2hex(random_bytes(16));
        $this->db->executeStatement(
            'UPDATE push_outbox SET claim = ?, locked_until = ?, attempts = attempts + 1
             WHERE status = ? AND next_attempt_at <= ? AND (locked_until IS NULL OR locked_until < ?)
             ORDER BY id LIMIT ' . max(1, $count),
            [$claim, $this->sql($now->modify('+' . self::CLAIM_SECONDS . ' seconds')), PushOutbox::STATUS_PENDING, $this->sql($now), $this->sql($now)],
        );

        return $this->db->fetchAllAssociative(
            'SELECT o.id, o.type, o.payload, o.ttl, o.urgency, o.topic, o.attempts, o.expires_at, o.created_at,
                    s.id AS subscription_id, s.endpoint, s.p256dh, s.auth, s.content_encoding, s.owner_kind,
                    u.status AS user_status, a.is_active AS admin_active
             FROM push_outbox o
             INNER JOIN push_subscriptions s ON s.id = o.subscription_id
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN admins a ON a.id = s.admin_id
             WHERE o.claim = ? ORDER BY o.id',
            [$claim],
        );
    }

    /** @param array<string, mixed> $row @return string clé de statistique */
    private function deliver(array $row): string
    {
        $now = $this->now();
        $expiresAt = new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC'));
        $active = $row['owner_kind'] === PushSubscription::KIND_ADMIN ? (bool) $row['admin_active'] : $row['user_status'] !== User::STATUS_BLOCKED;
        if ($expiresAt <= $now || !$active) {
            $this->finish((int) $row['id'], PushOutbox::STATUS_DROPPED, null, $expiresAt <= $now ? 'expirée' : 'compte inactif');

            return 'dropped';
        }

        // Nouvel essai d'une notification déjà remplacée sur l'appareil (même réservation, plus récente envoyée) :
        // l'envoyer maintenant afficherait une étape dépassée à la place de la bonne.
        if ($row['topic'] !== null && $this->db->fetchOne(
            'SELECT 1 FROM push_outbox WHERE subscription_id = ? AND topic = ? AND id > ? AND status = ? LIMIT 1',
            [(int) $row['subscription_id'], (string) $row['topic'], (int) $row['id'], PushOutbox::STATUS_SENT],
        ) !== false) {
            $this->finish((int) $row['id'], PushOutbox::STATUS_DROPPED, null, 'remplacée par une notification plus récente');

            return 'dropped';
        }

        // Durée de vie restante (le service de push n'a pas à garder plus longtemps une notification retardée).
        $ttl = max(0, min((int) $row['ttl'], $expiresAt->getTimestamp() - $now->getTimestamp()));
        $result = $this->transport->send((string) $row['endpoint'], (string) $row['p256dh'], (string) $row['auth'], (string) ($row['content_encoding'] ?: 'aes128gcm'), (string) $row['payload'], $ttl, (string) $row['urgency'], $row['topic'] !== null ? (string) $row['topic'] : null);
        $subscriptionId = (int) $row['subscription_id'];

        if ($result->isSuccess()) {
            $this->finish((int) $row['id'], PushOutbox::STATUS_SENT, $result->status, null, $now);
            $this->db->executeStatement('UPDATE push_subscriptions SET last_success_at = ?, failure_count = 0, last_status = ? WHERE id = ?', [$this->sql($now), $result->status, $subscriptionId]);

            return 'sent';
        }
        if ($result->isGone()) {
            // Appareil désabonné : supprimé, ses notifications en attente avec lui (clé étrangère en cascade).
            $this->db->executeStatement('DELETE FROM push_subscriptions WHERE id = ?', [$subscriptionId]);
            $this->logger->info('Appareil désabonné des notifications : supprimé.', ['service' => parse_url((string) $row['endpoint'], \PHP_URL_HOST), 'status' => $result->status]);

            return 'removed';
        }

        $this->db->executeStatement('UPDATE push_subscriptions SET failure_count = failure_count + 1, last_status = ? WHERE id = ?', [$result->status, $subscriptionId]);
        if ($result->isTemporary()) {
            $attempts = (int) $row['attempts'];
            $delay = $result->retryAfter !== null ? max(60, min(3600, $result->retryAfter)) : self::BACKOFF[min($attempts - 1, \count(self::BACKOFF) - 1)];
            $next = $now->modify(sprintf('+%d seconds', $delay));
            if ($attempts >= self::MAX_ATTEMPTS || $next >= $expiresAt) {
                $this->logger->warning('Notification push abandonnée après plusieurs essais.', ['type' => $row['type'], 'status' => $result->status, 'error' => $result->error, 'service' => parse_url((string) $row['endpoint'], \PHP_URL_HOST)]);
                $this->finish((int) $row['id'], PushOutbox::STATUS_DROPPED, $result->status, $result->error ?? 'abandonnée après plusieurs essais');

                return 'dropped';
            }
            $this->db->executeStatement(
                'UPDATE push_outbox SET claim = NULL, locked_until = NULL, next_attempt_at = ?, last_status = ?, last_error = ? WHERE id = ?',
                [$this->sql($next), $result->status, self::short($result->error), (int) $row['id']],
            );

            return 'retried';
        }

        if ($result->isAuthError()) {
            $this->logger->critical('Notifications push refusées par le service de push : vérifier les clés VAPID (VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT).', ['status' => $result->status, 'service' => parse_url((string) $row['endpoint'], \PHP_URL_HOST)]);
        } else {
            $this->logger->error('Notification push refusée par le service de push.', ['status' => $result->status, 'type' => $row['type'], 'error' => $result->error]);
        }
        $this->finish((int) $row['id'], PushOutbox::STATUS_FAILED, $result->status, $result->error);

        return 'failed';
    }

    /** @param list<array<string, mixed>> $rows */
    private function release(array $rows): void
    {
        foreach ($rows as $row) {
            $this->db->executeStatement('UPDATE push_outbox SET claim = NULL, locked_until = NULL, attempts = GREATEST(0, attempts - 1) WHERE id = ?', [(int) $row['id']]);
        }
    }

    private function finish(int $id, string $status, ?int $httpStatus, ?string $error, ?\DateTimeImmutable $sentAt = null): void
    {
        $this->db->executeStatement(
            'UPDATE push_outbox SET status = ?, claim = NULL, locked_until = NULL, last_status = ?, last_error = ?, sent_at = ? WHERE id = ?',
            [$status, $httpStatus, self::short($error), $sentAt !== null ? $this->sql($sentAt) : null, $id],
        );
    }

    private static function short(?string $text): ?string
    {
        return $text !== null ? mb_substr($text, 0, 255) : null;
    }

    private function sql(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
    }
}
