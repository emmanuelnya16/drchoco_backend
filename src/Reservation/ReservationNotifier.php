<?php

namespace App\Reservation;

use App\Account\PhoneNumber;
use App\Entity\Reservation;
use Psr\Log\LoggerInterface;

/**
 * Événements à signaler (boutique et client). P5 : écrits dans le journal de l'application ;
 * les notifications push (nouvelle réservation, preuve reçue, « plus que 2 h ») arrivent en P7.
 * Appelé après l'enregistrement : une panne ici n'annule jamais la réservation.
 */
class ReservationNotifier
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function created(Reservation $r): void
    {
        $this->log('Nouvelle réservation', $r, ['total' => $r->getTotal(), 'shopCity' => $r->getShopCity()]);
    }

    public function proofReceived(Reservation $r): void
    {
        $this->log('Preuve de paiement reçue', $r, ['operator' => $r->getPaymentOperator()]);
    }

    public function expiringSoon(Reservation $r): void
    {
        $this->log('Réservation bientôt expirée (rappel au client)', $r, ['expiresAt' => $r->getExpiresAt()->format(\DATE_ATOM)]);
    }

    public function expired(Reservation $r): void
    {
        $this->log('Réservation expirée, articles remis en vente', $r);
    }

    public function cancelledByClient(Reservation $r): void
    {
        $this->log('Réservation annulée par le client', $r);
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
