<?php

namespace App\Admin\Reservation;

use App\Api\ApiException;
use App\Audit\AuditLog;
use App\Entity\Admin;
use App\Entity\Agency;
use App\Entity\DeliveryCity;
use App\Entity\Reservation;
use App\Entity\ReservationNote;
use App\Entity\ReservationPackagePhoto;
use App\Entity\ReservationProof;
use App\Media\PrivateMediaStorage;
use App\Repository\ReservationRepository;
use App\Reservation\DeliveryOptions;
use App\Reservation\ReservationLifecycle;
use App\Reservation\ReservationNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Actions de l'équipe sur une réservation (P6), chacune sur la réservation relue sous verrou
 * (ReservationLifecycle::locked) : deux membres de l'équipe, le client et la tâche planifiée ne peuvent pas
 * agir deux fois sur la même réservation.
 *
 * Validation et rejet du paiement, annulation : super admin seulement (décision du propriétaire). Préparation,
 * photos du colis, remise et notes : tout membre de l'équipe qui voit la réservation (ReservationScope).
 */
final class AdminReservationActions
{
    /** Délai laissé au client pour renvoyer une preuve après un rejet (sans jamais raccourcir l'échéance). */
    public const RESEND_HOURS = 6;

    /** Étapes que POST /status fait avancer : statuts de départ et mode de réception (null : les deux). */
    private const STEPS = [
        Reservation::STATUS_PREPARING  => ['from' => [Reservation::STATUS_CONFIRMED], 'mode' => null],
        Reservation::STATUS_IN_DELIVERY => ['from' => [Reservation::STATUS_HANDED_TO_AGENCY], 'mode' => Reservation::MODE_DELIVERY],
        Reservation::STATUS_DELIVERED  => ['from' => [Reservation::STATUS_HANDED_TO_AGENCY, Reservation::STATUS_IN_DELIVERY], 'mode' => Reservation::MODE_DELIVERY],
        Reservation::STATUS_PICKED_UP  => ['from' => [Reservation::STATUS_READY_FOR_PICKUP], 'mode' => Reservation::MODE_PICKUP],
    ];

    private const DELIVERY_ONLY = [Reservation::STATUS_HANDED_TO_AGENCY, Reservation::STATUS_IN_DELIVERY, Reservation::STATUS_DELIVERED];
    private const PICKUP_ONLY = [Reservation::STATUS_READY_FOR_PICKUP, Reservation::STATUS_PICKED_UP];

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ReservationLifecycle $lifecycle,
        private readonly ReservationNotifier $notifier,
        private readonly PrivateMediaStorage $storage,
        private readonly PackagePhotoInput $photos,
        private readonly DeliveryOptions $delivery,
        private readonly AuditLog $audit,
        private readonly EntityManagerInterface $em,
    ) {}

    /** Réservation visible par ce membre de l'équipe ; sinon 404 (jamais 403 : on ne révèle pas qu'elle existe). */
    public function find(Admin $admin, string $ref): Reservation
    {
        $reservation = $this->reservations->findByRef($ref);
        if ($reservation === null || !ReservationScope::canSee($admin, $reservation)) {
            throw ApiException::notFound('RESERVATION_NOT_FOUND', 'Cette réservation est introuvable.');
        }

        return $reservation;
    }

    /** Réservation échue : passée à « expirée » (stock remis) avant d'être montrée à l'équipe. */
    public function expireIfDue(Reservation $reservation): void
    {
        if ($reservation->isOverdue($this->lifecycle->now())) {
            $this->lifecycle->expire($reservation);
        }
    }

    /**
     * Paiement validé : preuve en attente acceptée ; ou validation manuelle (paiement constaté sur le compte
     * marchand sans preuve exploitable) d'une réservation qui attend encore son paiement.
     *
     * @param array{expectedStatus?: string, proofSha256?: ?string} $seen ce que l'admin avait sous les yeux
     */
    public function validatePayment(Admin $admin, Reservation $reservation, ?string $note, array $seen = []): void
    {
        $this->assertSuperAdmin($admin, 'Seul le super admin peut valider un paiement.');

        $manual = false;
        $changed = $this->lifecycle->locked($reservation, function (Reservation $r, \DateTimeImmutable $now) use ($admin, $note, $seen, &$manual): bool {
            $this->expireOrFail($r, $now);
            if ($r->getStatus() === Reservation::STATUS_CONFIRMED) {
                return false; // déjà validé (double clic) : rien à refaire
            }
            $this->assertStillAsSeen($r, $seen);
            if (!\in_array($r->getStatus(), [Reservation::STATUS_PENDING_VALIDATION, ...Reservation::AWAITING_PAYMENT], true)) {
                throw $this->invalidStatus($r, 'Le paiement de cette réservation n’est plus à valider.');
            }

            $proof = $r->latestProof();
            $manual = $proof === null || $proof->getStatus() !== ReservationProof::STATUS_PENDING;
            if (!$manual) {
                $proof->setStatus(ReservationProof::STATUS_ACCEPTED);
            }
            $r->setRejection(null, null)->setPaidAt($now);
            $r->record(Reservation::STATUS_CONFIRMED, Reservation::ACTOR_SHOP, $note ?? 'Paiement validé par la boutique.', $now, $admin);

            return true;
        }, commitOnError: true);

        if ($changed) {
            $this->audit->record('payment.validated', $admin, ['ref' => $reservation->getRef(), 'total' => $reservation->getTotal(), 'manual' => $manual]);
            $this->notifier->paymentValidated($reservation);
        }
    }

    /**
     * Preuve rejetée : motif montré au client, qui a de nouveau au moins 6 h pour en envoyer une autre.
     *
     * @param array{expectedStatus?: string, proofSha256?: ?string} $seen ce que l'admin avait sous les yeux
     */
    public function rejectPayment(Admin $admin, Reservation $reservation, string $reason, array $seen = []): void
    {
        $this->assertSuperAdmin($admin, 'Seul le super admin peut rejeter un paiement.');

        $changed = $this->lifecycle->locked($reservation, function (Reservation $r, \DateTimeImmutable $now) use ($admin, $reason, $seen): bool {
            $this->assertNotFinal($r);
            if ($r->getStatus() === Reservation::STATUS_PAYMENT_REJECTED) {
                return false; // déjà rejeté (double clic)
            }
            $this->assertStillAsSeen($r, $seen);
            if ($r->getStatus() !== Reservation::STATUS_PENDING_VALIDATION) {
                throw $this->invalidStatus($r, 'Seule une preuve en attente de vérification peut être rejetée.');
            }

            $proof = $r->latestProof();
            if ($proof !== null && $proof->getStatus() === ReservationProof::STATUS_PENDING) {
                $proof->setStatus(ReservationProof::STATUS_REJECTED);
            }
            $deadline = $now->modify(sprintf('+%d hours', self::RESEND_HOURS));
            $r->setRejection($reason, $now)
                ->setExpiresAt(max($r->getExpiresAt(), $deadline))
                ->setExpiringNotifiedAt(null); // nouveau rappel « bientôt expirée » possible
            $r->record(Reservation::STATUS_PAYMENT_REJECTED, Reservation::ACTOR_SHOP, $reason, $now, $admin);

            return true;
        });

        if ($changed) {
            $this->audit->record('payment.rejected', $admin, ['ref' => $reservation->getRef(), 'total' => $reservation->getTotal(), 'reason' => $reason]);
            $this->notifier->paymentRejected($reservation);
        }
    }

    /** POST /status : préparation, en route, livrée, retirée. */
    public function advance(Admin $admin, Reservation $reservation, string $status, ?string $note): void
    {
        if (\in_array($status, [Reservation::STATUS_READY_FOR_PICKUP, Reservation::STATUS_HANDED_TO_AGENCY], true)) {
            throw new ApiException(422, 'INVALID_STATUS', 'Pour marquer le colis prêt, envoyez d’abord ses photos.', ['status' => $reservation->getStatus()]);
        }
        if (!isset(self::STEPS[$status])) {
            throw new ApiException(422, 'INVALID_STATUS', 'Ce statut ne se change pas ici.', ['status' => $reservation->getStatus()]);
        }
        $this->assertModeAllows($reservation, $status);

        $changed = $this->lifecycle->locked($reservation, function (Reservation $r, \DateTimeImmutable $now) use ($admin, $status, $note): bool {
            if ($r->getStatus() === $status) {
                return false; // déjà fait (double clic)
            }
            $this->assertNotFinal($r);
            if (!\in_array($r->getStatus(), self::STEPS[$status]['from'], true)) {
                throw $this->invalidStatus($r, 'Cette étape n’est pas possible depuis le statut actuel de la réservation.');
            }
            $r->record($status, Reservation::ACTOR_SHOP, $note ?? $this->stepNote($r, $status), $now, $admin);

            return true;
        });

        if ($changed) {
            $this->notifier->statusChanged($reservation);
        }
    }

    /**
     * Colis prêt : photos enregistrées (elles remplacent les précédentes), puis « prêt en boutique » (retrait) ou
     * « remis à l'agence / au livreur » (livraison). Depuis « confirmée », l'étape « en préparation » est ajoutée.
     */
    public function packageReady(Admin $admin, Reservation $reservation, Request $request, ?string $note, ?string $agencyId): void
    {
        if ($agencyId !== null && $reservation->isPickup()) {
            throw new ApiException(422, 'INVALID_RECEPTION_MODE', 'Retrait en boutique : aucune agence à choisir.');
        }
        $photos = $this->photos->read($request);

        $stored = [];
        $previous = [];
        try {
            $this->lifecycle->locked($reservation, function (Reservation $r, \DateTimeImmutable $now) use ($admin, $photos, $note, $agencyId, &$stored, &$previous): void {
                $this->assertNotFinal($r);
                if (!\in_array($r->getStatus(), [Reservation::STATUS_CONFIRMED, Reservation::STATUS_PREPARING], true)) {
                    throw $this->invalidStatus($r, 'Les photos du colis s’envoient une fois le paiement validé, pendant la préparation.');
                }
                if ($agencyId !== null) {
                    $this->changeAgency($r, $agencyId);
                }

                foreach ($r->getPackagePhotos() as $old) {
                    $previous[] = $old->getStoragePath();
                }
                $entities = [];
                foreach ($photos as $position => $photo) {
                    $path = $this->storage->store('packages/' . $r->getId(), $photo['extension'], $photo['bytes']);
                    $stored[] = $path;
                    $entities[] = new ReservationPackagePhoto($r, $position, $path, $photo['mime'], \strlen($photo['bytes']), $photo['width'], $photo['height'], $admin, $now);
                }
                $r->replacePackagePhotos($entities, $now);

                if ($r->getStatus() === Reservation::STATUS_CONFIRMED) {
                    $r->record(Reservation::STATUS_PREPARING, Reservation::ACTOR_SHOP, $this->stepNote($r, Reservation::STATUS_PREPARING), $now, $admin);
                }
                $target = $r->isPickup() ? Reservation::STATUS_READY_FOR_PICKUP : Reservation::STATUS_HANDED_TO_AGENCY;
                $r->record($target, Reservation::ACTOR_SHOP, $note ?? $this->stepNote($r, $target), $now, $admin);
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                $this->storage->delete($path); // enregistrement annulé : pas de fichier orphelin
            }
            throw $e;
        }

        foreach ($previous as $path) {
            $this->storage->delete($path); // anciennes photos remplacées
        }
        $this->notifier->statusChanged($reservation);
    }

    /** Annulation par la boutique (super admin) : articles remis en vente, motif affiché au client. */
    public function cancel(Admin $admin, Reservation $reservation, string $reason): void
    {
        $this->assertSuperAdmin($admin, 'Seul le super admin peut annuler une réservation.');

        $this->lifecycle->locked($reservation, function (Reservation $r, \DateTimeImmutable $now) use ($admin, $reason): void {
            if ($r->isOverdue($now)) {
                $this->lifecycle->releaseStock($r);
                $r->record(Reservation::STATUS_EXPIRED, Reservation::ACTOR_SYSTEM, 'Délai de paiement dépassé : articles remis en vente.', $now);
                $this->em->flush();
            }
            $this->assertNotFinal($r);
            $this->lifecycle->releaseStock($r);
            $r->record(Reservation::STATUS_CANCELLED, Reservation::ACTOR_SHOP, $reason, $now, $admin);
        }, commitOnError: true);

        $this->audit->record('reservation.cancelled', $admin, ['ref' => $reservation->getRef(), 'total' => $reservation->getTotal(), 'reason' => $reason, 'refundDue' => $reservation->isRefundDue()]);
        $this->notifier->cancelledByShop($reservation);
    }

    /**
     * Remboursement fait par Mobile Money, hors de l'application (ou rien à rembourser : fausse capture) : la marque
     * « remboursement à prévoir » disparaît, l'explication reste dans les notes internes.
     */
    public function settleRefund(Admin $admin, Reservation $reservation, string $note): void
    {
        $this->assertSuperAdmin($admin, 'Seul le super admin peut clore un remboursement.');

        $changed = $this->lifecycle->locked($reservation, function (Reservation $r, \DateTimeImmutable $now) use ($admin, $note): bool {
            if ($r->getRefundSettledAt() !== null) {
                return false; // déjà clos (double clic)
            }
            if (!$r->isRefundDue()) {
                throw $this->invalidStatus($r, 'Aucun remboursement n’est à prévoir pour cette réservation.');
            }
            $r->settleRefund($now);
            $internal = new ReservationNote($r, $admin, 'Remboursement clos : ' . $note, $now);
            $r->addInternalNote($internal);
            $this->em->persist($internal);

            return true;
        });

        if ($changed) {
            $this->audit->record('refund.settled', $admin, ['ref' => $reservation->getRef(), 'total' => $reservation->getTotal(), 'note' => $note]);
        }
    }

    /** Note interne de l'équipe (jamais montrée au client). */
    public function addNote(Admin $admin, Reservation $reservation, string $text): ReservationNote
    {
        $note = new ReservationNote($reservation, $admin, $text, $this->lifecycle->now());
        $reservation->addInternalNote($note);
        $this->em->persist($note);
        $this->em->flush();

        return $note;
    }

    /**
     * Agences proposées pour remettre le colis : livraison par agence seulement, agences actives de la ville de
     * destination qui desservent la ville de la boutique.
     *
     * @return list<Agency>
     */
    public function availableAgencies(Reservation $reservation): array
    {
        if ($reservation->isPickup() || $reservation->getAgencyId() === null || $reservation->getDeliveryCityId() === null) {
            return [];
        }
        $city = $this->em->find(DeliveryCity::class, $reservation->getDeliveryCityId());
        if ($city === null) {
            return [];
        }

        return $this->delivery->agencies($city, $reservation->getShopCity());
    }

    /** Agence changée au moment de la remise (le prix payé par le client ne change pas). */
    private function changeAgency(Reservation $r, string $agencyId): void
    {
        if ($r->getAgencyId() === null) {
            throw ApiException::validation(['agencyId' => 'Livraison à domicile : aucune agence à choisir.'], 'Livraison à domicile : aucune agence à choisir.');
        }
        if ($agencyId === $r->getAgencyId()) {
            return;
        }
        foreach ($this->availableAgencies($r) as $agency) {
            if ($agency->getId() === $agencyId) {
                $r->setAgency($agency->getId(), [
                    'id'           => $agency->getId(),
                    'name'         => $agency->getName(),
                    'address'      => $agency->getAddress(),
                    'city'         => $agency->getCity()->getName(),
                    'phone'        => $agency->getPhone(),
                    'openingHours' => $agency->getOpeningHours(),
                    'fee'          => $r->getAgency()['fee'] ?? $r->getDeliveryFee(),
                ]);

                return;
            }
        }

        throw ApiException::validation(['agencyId' => 'Agence inconnue, fermée ou qui ne dessert pas cette boutique.'], 'Agence inconnue, fermée ou qui ne dessert pas cette boutique.');
    }

    /** Texte de l'historique par défaut (montré tel quel au client). */
    private function stepNote(Reservation $r, string $status): string
    {
        $agency = $r->getAgency();

        return match ($status) {
            Reservation::STATUS_PREPARING        => 'Préparation du colis en cours.',
            Reservation::STATUS_READY_FOR_PICKUP => sprintf('Colis prêt à la boutique de %s.', $r->getShopCity()),
            Reservation::STATUS_HANDED_TO_AGENCY => $agency !== null ? sprintf('Colis remis à %s.', (string) ($agency['name'] ?? 'l’agence')) : 'Colis prêt, en attente du livreur.',
            Reservation::STATUS_IN_DELIVERY      => $agency !== null ? sprintf('En route vers %s.', (string) $r->getDeliveryCityName()) : 'Le livreur est en route.',
            Reservation::STATUS_DELIVERED        => 'Colis remis au client.',
            Reservation::STATUS_PICKED_UP        => 'Colis retiré à la boutique.',
            default                              => '',
        };
    }

    private function assertSuperAdmin(Admin $admin, string $message): void
    {
        if (!$admin->isSuperAdmin()) {
            throw ApiException::forbidden($message);
        }
    }

    /**
     * L'admin a jugé une preuve précise : si un collègue a déjà répondu, ou si le client a renvoyé une autre capture
     * entre-temps, rien n'est fait et l'écran se recharge.
     *
     * @param array{expectedStatus?: string, proofSha256?: ?string} $seen
     */
    private function assertStillAsSeen(Reservation $r, array $seen): void
    {
        $sameStatus = !\array_key_exists('expectedStatus', $seen) || $seen['expectedStatus'] === $r->getStatus();
        $sameProof = !\array_key_exists('proofSha256', $seen) || $seen['proofSha256'] === $r->latestProof()?->getSha256();
        if (!$sameStatus || !$sameProof) {
            throw new ApiException(409, 'STATE_CHANGED', 'Cette réservation a changé depuis que vous l’avez ouverte (autre preuve ou autre décision). Vérifiez-la à nouveau.', ['status' => $r->getStatus()]);
        }
    }

    private function assertNotFinal(Reservation $r): void
    {
        if ($r->isFinal()) {
            throw new ApiException(409, 'ALREADY_FINAL', 'Cette réservation est déjà terminée.', ['status' => $r->getStatus()]);
        }
    }

    private function assertModeAllows(Reservation $r, string $status): void
    {
        if (($r->isPickup() && \in_array($status, self::DELIVERY_ONLY, true)) || (!$r->isPickup() && \in_array($status, self::PICKUP_ONLY, true))) {
            throw new ApiException(422, 'INVALID_RECEPTION_MODE', $r->isPickup()
                ? 'Cette réservation est un retrait en boutique : pas d’étape de livraison.'
                : 'Cette réservation est une livraison : pas de retrait en boutique.');
        }
    }

    /** Échéance dépassée sans paiement : expirée tout de suite (stock remis), puis refus. */
    private function expireOrFail(Reservation $r, \DateTimeImmutable $now): void
    {
        if ($r->isOverdue($now)) {
            $this->lifecycle->releaseStock($r);
            $r->record(Reservation::STATUS_EXPIRED, Reservation::ACTOR_SYSTEM, 'Délai de paiement dépassé : articles remis en vente.', $now);
            $this->em->flush();
            throw new ApiException(409, 'RESERVATION_EXPIRED', 'Le délai de paiement est dépassé : la réservation a expiré et ses articles ont été remis en vente.', ['status' => $r->getStatus()]);
        }
        $this->assertNotFinal($r);
    }

    private function invalidStatus(Reservation $r, string $message): ApiException
    {
        return new ApiException(422, 'INVALID_STATUS', $message, ['status' => $r->getStatus()]);
    }
}
