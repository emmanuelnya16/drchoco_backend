<?php

namespace App\Push;

use App\Entity\Admin;
use App\Entity\PushOutbox;
use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Mise en file des notifications : une ligne par appareil du ou des destinataires (comptes actifs seulement),
 * écrite en SQL après l'enregistrement de l'action. Une panne ici est journalisée et n'annule jamais l'action.
 * L'envoi a lieu juste après la réponse (PushTerminateListener) ou par la tâche `app:push:send`.
 */
class PushQueue
{
    /** Appareil de l'équipe dont l'administration n'a pas été ouverte depuis 30 jours : plus aucune notification. */
    public const ADMIN_DEVICE_IDLE_DAYS = 30;

    private int $queued = 0;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /** Tous les appareils d'un client (compte non bloqué). @return int lignes mises en file */
    public function toUser(User $user, PushMessage $message, ?int $reservationId = null): int
    {
        return $this->insert(
            'FROM push_subscriptions s INNER JOIN users u ON u.id = s.user_id WHERE s.owner_kind = ? AND s.user_id = ? AND u.status <> ?',
            [PushSubscription::KIND_USER, (int) $user->getId(), User::STATUS_BLOCKED],
            $message,
            $reservationId,
        );
    }

    /**
     * Appareils de membres de l'équipe (comptes actifs).
     *
     * @param list<int> $adminIds
     */
    public function toAdmins(array $adminIds, PushMessage $message, ?int $reservationId = null): int
    {
        $adminIds = array_values(array_unique(array_map('intval', $adminIds)));
        if ($adminIds === []) {
            return 0;
        }

        return $this->insert(
            'FROM push_subscriptions s INNER JOIN admins a ON a.id = s.admin_id WHERE s.owner_kind = ? AND a.is_active = 1 AND s.last_seen_at >= ? AND s.admin_id IN (' . implode(', ', $adminIds) . ')',
            [PushSubscription::KIND_ADMIN, (new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp()))->modify(sprintf('-%d days', self::ADMIN_DEVICE_IDLE_DAYS))->format('Y-m-d H:i:s')],
            $message,
            $reservationId,
        );
    }

    /** Des notifications ont été mises en file pendant cette requête. */
    public function hasQueued(): bool
    {
        return $this->queued > 0;
    }

    public function resetQueued(): void
    {
        $this->queued = 0;
    }

    /** @param list<int|string> $params */
    private function insert(string $from, array $params, PushMessage $message, ?int $reservationId): int
    {
        try {
            $now = new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
            $format = fn (\DateTimeImmutable $d) => $d->format('Y-m-d H:i:s');
            $count = $this->db->executeStatement(
                'INSERT INTO push_outbox (subscription_id, reservation_id, type, payload, ttl, urgency, topic, status, attempts, next_attempt_at, expires_at, created_at)
                 SELECT s.id, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ? ' . $from,
                [
                    $reservationId, $message->type, $message->payload(), $message->ttl, $message->urgency, $message->topic,
                    PushOutbox::STATUS_PENDING, $format($now), $format($now->modify(sprintf('+%d seconds', max(60, $message->ttl)))), $format($now),
                    ...$params,
                ],
            );
            $this->queued += (int) $count;

            return (int) $count;
        } catch (\Throwable $e) {
            $this->logger->error('Notification push non mise en file.', ['type' => $message->type, 'ref' => $message->ref, 'exception' => $e]);

            return 0;
        }
    }
}
