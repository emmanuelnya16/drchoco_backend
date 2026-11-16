<?php

namespace App\Reservation;

use App\Api\ApiDate;
use App\Entity\Reservation;
use App\Entity\ReservationEvent;
use App\Entity\ReservationItem;
use App\Media\PrivateMediaStorage;

/**
 * Réservation telle que le front l'attend (src/lib/api/schemas/reservation.ts, contrat §10.4), avec les
 * ajouts de la P5 : `shopCity`, `pickupShop`, `deliveryFeePayment`, prix de l'agence.
 */
final class ReservationNormalizer
{
    public function __construct(private readonly PrivateMediaStorage $media) {}

    /** @return array<string, mixed> fiche complète */
    public function detail(Reservation $r, \DateTimeImmutable $now): array
    {
        $data = [
            'ref'                => $r->getRef(),
            'status'             => $r->getStatus(),
            'receptionMode'      => $r->getReceptionMode(),
            'city'               => $this->city($r),
            'shopCity'           => $r->getShopCity(),
            'items'              => array_map($this->item(...), $r->getItems()->toArray()),
            'subtotal'           => $r->getSubtotal(),
            'deliveryFee'        => $r->getDeliveryFee(),
            'total'              => $r->getTotal(),
            'amount'             => ['subtotal' => $r->getSubtotal(), 'deliveryFee' => $r->getDeliveryFee(), 'total' => $r->getTotal()],
            'deliveryFeePayment' => $r->getDeliveryFeePayment(),
            'createdAt'          => ApiDate::format($r->getCreatedAt()),
            'updatedAt'          => ApiDate::format($r->getUpdatedAt()),
            'expiresAt'          => ApiDate::format($r->getExpiresAt()),
            'serverTime'         => ApiDate::format($now),
            'cancellable'        => $r->isCancellableByClient(),
            'cancelDeadline'     => null,
            'timeline'           => array_map($this->event(...), $r->getEvents()->toArray()),
            'packagePhotos'      => [],
            'packageReadyAt'     => null,
            'customer'           => [
                'firstName' => $r->getCustomerFirstName(),
                'lastName'  => $r->getCustomerLastName(),
                'phone'     => $r->getCustomerPhone(),
            ],
        ];

        if ($r->isPickup()) {
            $data['pickupShop'] = $r->getPickupShop();
        } else {
            $agency = $r->getAgency();
            if ($agency !== null) {
                $data['agency'] = $agency;
            } else {
                $data['address'] = ['district' => (string) $r->getDistrict(), 'landmark' => (string) $r->getLandmark()];
            }
            $data['deliveryAddress'] = array_filter([
                'city'           => (string) $r->getDeliveryCityName(),
                'quarter'        => $agency !== null ? (string) ($agency['name'] ?? '') : (string) $r->getDistrict(),
                'district'       => $r->getDistrict(),
                'landmark'       => $r->getLandmark(),
                'agencyId'       => $r->getAgencyId(),
                'agencyName'     => $agency['name'] ?? null,
                'recipientName'  => $r->getRecipientName(),
                'recipientPhone' => (string) $r->getRecipientPhone(),
            ], fn ($v) => $v !== null);
        }

        if ($r->getNotes() !== null) {
            $data['notes'] = $r->getNotes();
        }

        $proof = $r->latestProof();
        if ($proof !== null) {
            $data['proof'] = array_filter([
                'thumbnailUrl'  => $this->media->proofUrl((int) $proof->getId(), $now),
                'uploadedAt'    => ApiDate::format($proof->getUploadedAt()),
                'sha256'        => $proof->getSha256(),
                'operator'      => $proof->getOperator(),
                'payerPhone'    => $proof->getPayerPhone(),
                'transactionId' => $proof->getTransactionId(),
            ], fn ($v) => $v !== null);
        }

        if ($r->getStatus() === Reservation::STATUS_PAYMENT_REJECTED && $r->getRejectionReason() !== null) {
            $data['rejection'] = [
                'reason'     => $r->getRejectionReason(),
                'rejectedAt' => ApiDate::format($r->getRejectedAt() ?? $r->getUpdatedAt()),
            ];
            $data['rejectionReason'] = $r->getRejectionReason();
        }

        return $data;
    }

    /** @return array<string, mixed> ligne de l'historique (GET /reservations?scope=) */
    public function listItem(Reservation $r, \DateTimeImmutable $now): array
    {
        $data = [
            'ref'           => $r->getRef(),
            'status'        => $r->getStatus(),
            'createdAt'     => ApiDate::format($r->getCreatedAt()),
            'updatedAt'     => ApiDate::format($r->getUpdatedAt()),
            'serverTime'    => ApiDate::format($now),
            'amount'        => ['total' => $r->getTotal()],
            'itemsCount'    => $r->itemsCount(),
            'itemsPreview'  => array_map(
                fn (ReservationItem $i) => ['image' => $i->getImage(), 'name' => $i->getProductName()],
                \array_slice($r->getItems()->toArray(), 0, 3),
            ),
            'packagePhotoThumb' => null,
            'receptionMode'     => $r->getReceptionMode(),
            'city'              => $this->city($r),
            'shopCity'          => $r->getShopCity(),
        ];
        if ($r->isAwaitingPayment()) {
            $data['expiresAt'] = ApiDate::format($r->getExpiresAt());
        }
        if (\in_array($r->getStatus(), [Reservation::STATUS_EXPIRED, Reservation::STATUS_CANCELLED], true)) {
            $events = $r->getEvents()->toArray();
            $before = \count($events) >= 2 ? $events[\count($events) - 2] : null;
            if ($before instanceof ReservationEvent) {
                $data['reachedStatus'] = $before->getStatus();
            }
        }

        return $data;
    }

    /** Ville affichée : destination pour une livraison, boutique pour un retrait. */
    private function city(Reservation $r): string
    {
        return $r->isPickup() ? $r->getShopCity() : (string) $r->getDeliveryCityName();
    }

    /** @return array<string, mixed> */
    private function item(ReservationItem $i): array
    {
        return [
            'id'        => (string) $i->getId(),
            'productId' => $i->getProductId(),
            'slug'      => $i->getProductSlug(),
            'name'      => $i->getProductName(),
            'image'     => $i->getImage(),
            'variantId' => $i->getVariantId(),
            'size'      => $i->getSize(),
            'colorName' => $i->getColorName(),
            'colorCode' => $i->getColorCode(),
            'colorHex'  => $i->getColorHex(),
            'unitPrice' => $i->getUnitPrice(),
            'quantity'  => $i->getQuantity(),
            'city'      => $i->getCity(),
        ];
    }

    /** @return array<string, mixed> */
    private function event(ReservationEvent $e): array
    {
        return array_filter([
            'status' => $e->getStatus(),
            'at'     => ApiDate::format($e->getAt()),
            'note'   => $e->getNote(),
            'actor'  => $e->getActor(),
        ], fn ($v) => $v !== null);
    }
}
