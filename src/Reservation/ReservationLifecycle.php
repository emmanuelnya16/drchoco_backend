<?php

namespace App\Reservation;

use App\Api\ApiException;
use App\Entity\Reservation;
use App\Entity\User;
use App\Repository\ReservationRepository;
use App\Wallet\CoinWallet;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Changements de statut côté client et automatiques : expiration (échéance sans paiement) et annulation.
 *
 * Chaque changement se fait sous verrou de la ligne, relue en base : la tâche planifiée, le client et la
 * boutique ne peuvent pas agir deux fois sur la même réservation. Le stock est remis en vente au passage
 * à « expirée » ou « annulée », donc une seule fois ; les pièces du portefeuille utilisées sont rendues au même moment.
 */
final class ReservationLifecycle
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationRepository $reservations,
        private readonly ReservationNotifier $notifier,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly CoinWallet $wallet,
    ) {}

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
    }

    /**
     * Expire les réservations échues (toutes, ou celles d'un client avant de lui répondre).
     *
     * @return int nombre de réservations expirées
     */
    public function expireDue(?User $user = null, int $limit = 500): int
    {
        $count = 0;
        foreach ($this->reservations->dueIds($this->now(), $user, $limit) as $id) {
            $reservation = $this->em->find(Reservation::class, $id);
            try {
                if ($reservation !== null && $this->expire($reservation)) {
                    ++$count;
                }
            } catch (\Doctrine\DBAL\Exception\RetryableException $e) {
                // Interblocage ou attente de verrou trop longue : réessayée au prochain passage (ou à la prochaine
                // lecture du client), sans bloquer les autres.
                $this->logger->warning('Expiration d’une réservation reportée (base occupée).', ['id' => $id, 'exception' => $e]);
            }
        }

        return $count;
    }

    /** Passe à « expirée » si l'échéance est passée (relu sous verrou). @return bool expirée maintenant */
    public function expire(Reservation $reservation): bool
    {
        $expired = $this->locked($reservation, function (Reservation $r, \DateTimeImmutable $now): bool {
            if (!$r->isOverdue($now)) {
                return false;
            }
            $this->releaseStock($r);
            $r->record(Reservation::STATUS_EXPIRED, Reservation::ACTOR_SYSTEM, 'Délai de paiement dépassé : articles remis en vente.', $now);

            return true;
        });
        if ($expired) {
            $this->notifier->expired($reservation);
        }

        return $expired;
    }

    /** POST /reservations/{ref}/cancel (client). */
    public function cancelByClient(Reservation $reservation, ?string $reason): void
    {
        $this->locked($reservation, function (Reservation $r, \DateTimeImmutable $now) use ($reason): void {
            if ($r->isOverdue($now)) {
                $this->releaseStock($r);
                $r->record(Reservation::STATUS_EXPIRED, Reservation::ACTOR_SYSTEM, 'Délai de paiement dépassé : articles remis en vente.', $now);
                $this->em->flush();
            }
            if (!$r->isCancellableByClient()) {
                throw new ApiException(422, 'CANCEL_NOT_ALLOWED', 'Votre réservation est déjà en préparation et ne peut plus être annulée ici.', ['status' => $r->getStatus()]);
            }
            $this->releaseStock($r);
            $r->record(Reservation::STATUS_CANCELLED, Reservation::ACTOR_CLIENT, $reason ?? 'Annulée par le client.', $now);
        }, commitOnError: true);

        $this->notifier->cancelledByClient($reservation);
    }

    /**
     * Exécute `$change` sur la réservation relue sous verrou, dans une transaction, puis enregistre.
     * `commitOnError` : une expiration constatée en chemin est gardée même si l'action est refusée ensuite.
     *
     * @template T
     *
     * @param callable(Reservation, \DateTimeImmutable): T $change
     *
     * @return T
     */
    public function locked(Reservation $reservation, callable $change, bool $commitOnError = false): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->refresh($reservation, LockMode::PESSIMISTIC_WRITE);
            $result = $change($reservation, $this->now());
            $this->em->flush();
        } catch (ApiException $e) {
            if ($commitOnError) {
                $this->em->flush();
                $connection->commit();
            } else {
                $connection->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        $connection->commit();

        return $result;
    }

    /**
     * Remet les pièces réservées en stock (variante supprimée entre-temps : rien à remettre), dans l'ordre des
     * identifiants, comme la création d'une réservation : pas d'interblocage entre les deux.
     */
    public function releaseStock(Reservation $reservation): void
    {
        $quantities = [];
        $products = [];
        foreach ($reservation->getItems() as $item) {
            $quantities[$item->getVariantId()] = ($quantities[$item->getVariantId()] ?? 0) + $item->getQuantity();
            $products[$item->getProductId()] = $item->getProductId();
        }
        ksort($quantities, \SORT_STRING);
        sort($products, \SORT_STRING);

        // « Les plus réservés » : une réservation expirée ou annulée ne compte plus (sinon il suffirait de réserver puis
        // d'annuler en boucle pour faire monter un article). Articles d'abord, comme à la création.
        $connection = $this->em->getConnection();
        $connection->executeStatement(
            'UPDATE products SET reservations_count = reservations_count - 1 WHERE reservations_count > 0 AND id IN (?)',
            [$products],
            [ArrayParameterType::STRING],
        );
        foreach ($quantities as $variantId => $quantity) {
            $connection->executeStatement('UPDATE product_variants SET stock = stock + ? WHERE id = ?', [$quantity, $variantId]);
        }

        // Pièces du portefeuille utilisées pour cette réservation : rendues au client (une seule fois)
        $this->wallet->refund($reservation, $this->now());
    }
}
