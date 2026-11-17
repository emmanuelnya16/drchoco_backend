<?php

namespace App\Push;

use App\Entity\Reservation;
use App\Entity\ReservationPackagePhoto;
use App\Media\PrivateMediaStorage;

/**
 * Textes des notifications des réservations (contrat §2.11, P7), en français, heures du Cameroun (Africa/Douala).
 * Le client ne reçoit que la référence (jamais son téléphone) ; l'équipe reçoit aussi le nom et le montant.
 */
final class ReservationPushMessages
{
    private const TIMEZONE = 'Africa/Douala';
    private const NNBSP = "\u{202F}";

    public function __construct(private readonly PrivateMediaStorage $media) {}

    // --- Équipe ---

    public function newReservation(Reservation $r): PushMessage
    {
        $where = $r->isPickup() ? 'Retrait à ' . $r->getShopCity() : 'Livraison à ' . $r->getDeliveryCityName();

        return $this->team($r, 'new_reservation', 'Nouvelle réservation', sprintf('%s · %s · %s · %s', $r->getRef(), $this->customer($r), self::fcfa($r->getTotal()), $where));
    }

    public function proofReceived(Reservation $r): PushMessage
    {
        $operator = match ($r->getPaymentOperator()) {
            'orange' => 'Orange Money',
            'mtn'    => 'MTN MoMo',
            default  => 'Mobile Money',
        };

        return $this->team($r, 'proof_received', 'Preuve de paiement à vérifier', sprintf('%s · %s · %s', $r->getRef(), $operator, self::fcfa($r->getTotal())), PushMessage::URGENCY_HIGH);
    }

    public function cancelledByClientAfterPayment(Reservation $r): PushMessage
    {
        return $this->team($r, 'reservation_cancelled', 'Réservation annulée par le client', sprintf('%s · paiement déjà validé (%s) : remboursement à prévoir.', $r->getRef(), self::fcfa($r->getTotal())), PushMessage::URGENCY_HIGH);
    }

    // --- Client ---

    public function paymentValidated(Reservation $r): PushMessage
    {
        return $this->client($r, 'payment_validated', 'Paiement validé ✓', sprintf('%s est confirmée. Nous préparons votre colis.', $r->getRef()));
    }

    public function paymentRejected(Reservation $r, \DateTimeImmutable $now): PushMessage
    {
        $reason = self::withoutFinalDots((string) $r->getRejectionReason());

        return $this->client(
            $r,
            'payment_rejected',
            'Paiement non validé',
            sprintf('%s : %s. Renvoyez votre preuve avant %s.', $r->getRef(), $reason, $this->dayAndTime($r->getExpiresAt(), $now)),
            PushMessage::URGENCY_HIGH,
            $this->untilDeadline($r, $now),
        );
    }

    /** Colis prêt (photos envoyées) : photo du colis dans la notification. */
    public function packageReady(Reservation $r, \DateTimeImmutable $now): PushMessage
    {
        $agency = $r->getAgency();
        $body = match (true) {
            $r->isPickup()    => sprintf('%s vous attend à la boutique de %s. Voyez la photo de votre colis.', $r->getRef(), $r->getShopCity()),
            $agency !== null  => sprintf('%s a été remis à %s. Voyez la photo de votre colis.', $r->getRef(), (string) ($agency['name'] ?? 'l’agence')),
            default           => sprintf('%s est emballé, le livreur part bientôt. Voyez la photo de votre colis.', $r->getRef()),
        };
        $photo = $r->getPackagePhotos()->first();
        $image = $photo instanceof ReservationPackagePhoto && $photo->getId() !== null ? $this->media->packagePhotoPushUrl((int) $photo->getId(), $now) : null;

        return new PushMessage('package_ready', 'Votre colis est prêt 📦', $body, '/reservations/' . $r->getRef() . '#colis', $r->getRef(), $image, $r->getRef(), topic: $r->getRef());
    }

    public function inDelivery(Reservation $r): PushMessage
    {
        $body = $r->getAgency() !== null
            ? sprintf('%s part vers %s.', $r->getRef(), (string) $r->getDeliveryCityName())
            : sprintf('%s : le livreur est en route.', $r->getRef());

        return $this->client($r, 'in_delivery', 'Votre colis est en route', $body);
    }

    public function delivered(Reservation $r): PushMessage
    {
        return $r->getStatus() === Reservation::STATUS_PICKED_UP
            ? $this->client($r, 'delivered', 'Colis retiré ✓', sprintf('%s a été retiré à la boutique. Merci pour votre confiance !', $r->getRef()))
            : $this->client($r, 'delivered', 'Colis livré ✓', sprintf('%s a été livré. Merci pour votre confiance !', $r->getRef()));
    }

    public function expiringSoon(Reservation $r, \DateTimeImmutable $now): PushMessage
    {
        $when = $this->dayAndTime($r->getExpiresAt(), $now, 'à ');
        $body = $r->getStatus() === Reservation::STATUS_PAYMENT_REJECTED
            ? sprintf('%s expire %s. Renvoyez votre preuve de paiement.', $r->getRef(), $when)
            : sprintf('%s expire %s. Payez pour garder vos articles.', $r->getRef(), $when);

        return $this->client($r, 'expiring_soon', 'Votre réservation expire bientôt', $body, PushMessage::URGENCY_HIGH, $this->untilDeadline($r, $now));
    }

    public function cancelledByShop(Reservation $r, ?string $reason): PushMessage
    {
        $reason = self::withoutFinalDots((string) $reason);
        $body = $reason !== ''
            ? sprintf('%s a été annulée par la boutique : %s.', $r->getRef(), $reason)
            : sprintf('%s a été annulée par la boutique.', $r->getRef());
        if ($r->getPaidAt() !== null) {
            $body .= ' La boutique vous contacte pour le remboursement.';
        }

        return $this->client($r, 'reservation_cancelled', 'Réservation annulée', $body, PushMessage::URGENCY_HIGH);
    }

    private function client(Reservation $r, string $type, string $title, string $body, string $urgency = PushMessage::URGENCY_NORMAL, int $ttl = PushMessage::DEFAULT_TTL): PushMessage
    {
        return new PushMessage($type, $title, $body, '/reservations/' . $r->getRef(), $r->getRef(), null, $r->getRef(), $ttl, $urgency, $r->getRef());
    }

    private function team(Reservation $r, string $type, string $title, string $body, string $urgency = PushMessage::URGENCY_NORMAL): PushMessage
    {
        return new PushMessage($type, $title, $body, '/admin/reservations/' . $r->getRef(), $r->getRef(), null, 'admin-' . $r->getRef(), PushMessage::DEFAULT_TTL, $urgency, $r->getRef());
    }

    private function customer(Reservation $r): string
    {
        return trim($r->getCustomerFirstName() . ' ' . $r->getCustomerLastName());
    }

    /** Jusqu'à l'échéance (une minute au moins) : un rappel arrivé trop tard ne sert à rien. */
    private function untilDeadline(Reservation $r, \DateTimeImmutable $now): int
    {
        return max(60, min(PushMessage::DEFAULT_TTL, $r->getExpiresAt()->getTimestamp() - $now->getTimestamp()));
    }

    /** « 18 h 30 », « demain 9 h 05 », « le 19/11 à 9 h 05 » (heure du Cameroun). `$at` : « à » devant l'heure seule. */
    private function dayAndTime(\DateTimeImmutable $date, \DateTimeImmutable $now, string $at = ''): string
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $local = $date->setTimezone($tz);
        $today = $now->setTimezone($tz)->setTime(0, 0);
        $days = (int) $today->diff($local->setTime(0, 0))->format('%r%a');
        $time = $local->format('G') . self::NNBSP . 'h' . self::NNBSP . $local->format('i');

        return match ($days) {
            0       => $at . $time,
            1       => 'demain ' . $at . $time,
            default => 'le ' . $local->format('d/m') . ' à ' . $time,
        };
    }

    /** Motif sans espaces ni points finaux (Unicode : « déjà. » garde son « à »). */
    private static function withoutFinalDots(string $text): string
    {
        return preg_replace('/[\s.\x{00A0}\x{202F}]+$/u', '', $text) ?? $text;
    }

    private static function fcfa(int $amount): string
    {
        return number_format($amount, 0, ',', self::NNBSP) . self::NNBSP . 'FCFA';
    }
}
