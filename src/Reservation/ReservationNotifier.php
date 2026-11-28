<?php

namespace App\Reservation;

use App\Account\PhoneNumber;
use App\Admin\Reservation\ReservationScope;
use App\Entity\Admin;
use App\Entity\Reservation;
use App\Entity\ReservationEvent;
use App\Push\PushMessage;
use App\Push\PushQueue;
use App\Push\ReservationPushMessages;
use App\Repository\AdminRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Événements des réservations : écrits dans le journal de l'application et, depuis la P7, notifications push
 * mises en file (ReservationPushMessages pour les textes, PushQueue pour la file) :
 *  - équipe : nouvelle réservation (super admins et vendeurs concernés), preuve reçue (super admins),
 *    annulation par le client d'une réservation payée ou dont la preuve attendait (super admins), colis reçu selon le
 *    client, remise encore à confirmer (super admins et vendeurs concernés) ;
 *  - client : paiement validé ou rejeté, colis prêt (avec sa photo), en route, livré ou retiré, bientôt expirée,
 *    annulée par la boutique.
 * Appelé après l'enregistrement : une panne ici n'annule jamais l'action.
 */
class ReservationNotifier
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly PushQueue $push,
        private readonly ReservationPushMessages $messages,
        private readonly AdminRepository $admins,
        private readonly ClockInterface $clock,
    ) {}

    public function created(Reservation $r): void
    {
        $this->log('Nouvelle réservation', $r, ['total' => $r->getTotal(), 'shopCity' => $r->getShopCity()]);
        $this->toTeam($r, fn () => $this->messages->newReservation($r), withSellers: true);
    }

    public function proofReceived(Reservation $r): void
    {
        $this->log('Preuve de paiement reçue', $r, ['operator' => $r->getPaymentOperator()]);
        $this->toTeam($r, fn () => $this->messages->proofReceived($r));
    }

    public function expiringSoon(Reservation $r): void
    {
        $this->log('Réservation bientôt expirée (rappel au client)', $r, ['expiresAt' => $r->getExpiresAt()->format(\DATE_ATOM)]);
        $this->toClient($r, fn () => $this->messages->expiringSoon($r, $this->now()));
    }

    public function expired(Reservation $r): void
    {
        $this->log('Réservation expirée, articles remis en vente', $r);
    }

    public function cancelledByClient(Reservation $r): void
    {
        $this->log('Réservation annulée par le client', $r);
        if ($r->isRefundDue()) {
            // Paiement validé, ou preuve envoyée et jamais examinée : la boutique doit vérifier et rembourser.
            $this->toTeam($r, fn () => $r->getPaidAt() !== null
                ? $this->messages->cancelledByClientAfterPayment($r)
                : $this->messages->cancelledByClientWithProof($r));
        }
    }

    /** « J'ai bien reçu mon colis » : l'équipe (et le vendeur concerné) confirme à son tour, si ce n'est pas déjà fait. */
    public function receivedByClient(Reservation $r): void
    {
        $this->log('Réception du colis confirmée par le client', $r);
        if (!$r->isFinal()) {
            $this->toTeam($r, fn () => $this->messages->receivedByClient($r), withSellers: true);
        }
    }

    public function paymentValidated(Reservation $r): void
    {
        $this->log('Paiement validé par la boutique', $r, ['total' => $r->getTotal()]);
        $this->toClient($r, fn () => $this->messages->paymentValidated($r));
    }

    public function paymentRejected(Reservation $r): void
    {
        $this->log('Paiement rejeté par la boutique', $r, ['expiresAt' => $r->getExpiresAt()->format(\DATE_ATOM)]);
        $this->toClient($r, fn () => $this->messages->paymentRejected($r, $this->now()));
    }

    /** Étape de la boutique : colis prêt (avec photos), en route, livré ou retiré (rien pour la préparation). */
    public function statusChanged(Reservation $r): void
    {
        $this->log('Réservation mise à jour par la boutique', $r);
        $message = match ($r->getStatus()) {
            Reservation::STATUS_READY_FOR_PICKUP, Reservation::STATUS_HANDED_TO_AGENCY => fn () => $this->messages->packageReady($r, $this->now()),
            Reservation::STATUS_IN_DELIVERY                                            => fn () => $this->messages->inDelivery($r),
            Reservation::STATUS_DELIVERED, Reservation::STATUS_PICKED_UP               => fn () => $this->messages->delivered($r),
            default                                                                    => null,
        };
        if ($message !== null) {
            $this->toClient($r, $message);
        }
    }

    public function cancelledByShop(Reservation $r): void
    {
        $this->log('Réservation annulée par la boutique, articles remis en vente', $r);
        $last = $r->getEvents()->last();
        $this->toClient($r, fn () => $this->messages->cancelledByShop($r, $last instanceof ReservationEvent ? $last->getNote() : null));
    }

    /** @param callable(): PushMessage $message */
    private function toClient(Reservation $r, callable $message): void
    {
        try {
            $this->push->toUser($r->getUser(), $message(), $r->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Notification push au client non préparée.', ['ref' => $r->getRef(), 'exception' => $e]);
        }
    }

    /**
     * Super admins actifs ; avec `$withSellers`, aussi les vendeurs actifs qui voient la réservation (règle P6).
     *
     * @param callable(): PushMessage $message
     */
    private function toTeam(Reservation $r, callable $message, bool $withSellers = false): void
    {
        try {
            $ids = [];
            foreach ($this->admins->findBy(['isActive' => true]) as $admin) {
                if ($admin->isSuperAdmin() || ($withSellers && $admin->getRole() === Admin::ROLE_SELLER && ReservationScope::canSee($admin, $r))) {
                    $ids[] = (int) $admin->getId();
                }
            }
            $this->push->toAdmins($ids, $message(), $r->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Notification push à l’équipe non préparée.', ['ref' => $r->getRef(), 'exception' => $e]);
        }
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
    }

    /** @param array<string, mixed> $context */
    private function log(string $message, Reservation $r, array $context = []): void
    {
        try {
            $this->logger->info($message, $context + [
                'ref'      => $r->getRef(),
                'status'   => $r->getStatus(),
                'customer' => PhoneNumber::mask($r->getCustomerPhone()),
            ]);
        } catch (\Throwable) {
            // jamais bloquant
        }
    }
}
