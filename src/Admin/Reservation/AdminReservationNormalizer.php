<?php

namespace App\Admin\Reservation;

use App\Api\ApiDate;
use App\Catalog\CatalogNormalizer;
use App\Entity\Agency;
use App\Entity\Reservation;
use App\Entity\ReservationEvent;
use App\Entity\ReservationItem;
use App\Entity\ReservationNote;
use App\Entity\ReservationProof;
use App\Repository\ProductRepository;
use App\Repository\ReservationRepository;
use App\Reservation\ReservationNormalizer;

/**
 * Réservations pour l'administration (front : src/features/admin/types.ts) :
 *  - `row()` : `AdminReservationRow`, ligne de la liste, de la recherche, du tableau de bord et de la fiche client ;
 *  - `detail()` : fiche du client (ReservationNormalizer) + ce que seule l'équipe voit (vendeur de chaque
 *    article, membre de l'équipe qui a agi, état de la preuve, date de validation du paiement).
 */
final class AdminReservationNormalizer
{
    public function __construct(
        private readonly ReservationNormalizer $client,
        private readonly CatalogNormalizer $catalog,
        private readonly ReservationRepository $reservations,
        private readonly ProductRepository $products,
    ) {}

    /** @return array<string, mixed> */
    public function row(Reservation $r, \DateTimeImmutable $now): array
    {
        $items = $r->getItems()->toArray();
        usort($items, fn (ReservationItem $a, ReservationItem $b) => $a->getPosition() <=> $b->getPosition());
        $first = $items[0] ?? null;
        $summary = 'Article';
        if ($first instanceof ReservationItem) {
            $details = array_filter([$first->getSize(), $first->getColorName()], fn (string $v) => $v !== '');
            $summary = $first->getProductName() . ($details !== [] ? ' (' . implode(' · ', $details) . ')' : '');
            if (\count($items) > 1) {
                $summary .= ' +' . (\count($items) - 1);
            }
        }

        $proof = $this->latestProof($r);
        $row = [
            'ref'              => $r->getRef(),
            'status'           => $r->getStatus(),
            'createdAt'        => ApiDate::format($r->getCreatedAt()),
            'updatedAt'        => ApiDate::format($r->getUpdatedAt()),
            'expiresAt'        => ApiDate::format($r->getExpiresAt()),
            'total'            => $r->getTotal(),
            'itemsCount'       => $r->itemsCount(),
            'firstItemSummary' => $summary,
            'receptionMode'    => $r->getReceptionMode(),
            'city'             => $r->isPickup() ? $r->getShopCity() : (string) $r->getDeliveryCityName(),
            'shopCity'         => $r->getShopCity(),
            'customer'         => [
                'firstName' => $r->getCustomerFirstName(),
                'lastName'  => $r->getCustomerLastName(),
                'phone'     => $r->getCustomerPhone(),
            ],
        ];
        $operator = $r->getPaymentOperator() ?? $proof?->getOperator();
        if ($operator !== null) {
            $row['operator'] = $operator;
        }
        $row['hasProof'] = $proof !== null;
        $row['hasPackagePhotos'] = $r->getPackageReadyAt() !== null;
        $row['refundDue'] = $r->isRefundDue();

        return $row;
    }

    /**
     * Fiche de la réservation pour l'équipe. La capture du paiement (nom, numéro et transaction du client) n'est montrée
     * qu'aux super admins, seuls à valider les paiements ; un vendeur voit seulement qu'elle est reçue.
     *
     * @return array<string, mixed>
     */
    public function detail(Reservation $r, \DateTimeImmutable $now, bool $superAdmin): array
    {
        $data = $this->client->detail($r, $now);
        $data['refundDue'] = $r->isRefundDue();
        $currentPrices = $superAdmin ? $this->products->prices(array_map(fn (ReservationItem $i) => $i->getProductId(), array_values($r->getItems()->toArray()))) : [];

        $items = array_values($r->getItems()->toArray());
        foreach ($data['items'] as $i => $line) {
            $item = $items[$i] ?? null;
            if ($item instanceof ReservationItem && isset($currentPrices[$item->getProductId()]) && $currentPrices[$item->getProductId()] !== $item->getUnitPrice()) {
                $data['items'][$i]['currentUnitPrice'] = $currentPrices[$item->getProductId()]; // prix baissé puis remis ?
            }
            if ($item instanceof ReservationItem && $item->getSellerId() !== null) {
                $data['items'][$i]['sellerId'] = (string) $item->getSellerId();
                if ($item->getSellerName() !== null) {
                    $data['items'][$i]['sellerName'] = $item->getSellerName();
                }
            }
        }

        $events = array_values($r->getEvents()->toArray());
        foreach ($data['timeline'] as $i => $entry) {
            $event = $events[$i] ?? null;
            if ($event instanceof ReservationEvent && $event->getAdminName() !== null) {
                $data['timeline'][$i]['by'] = $event->getAdminName();
            }
        }

        $proof = $r->latestProof();
        if ($proof !== null && isset($data['proof'])) {
            $data['proof'] = $superAdmin
                ? $data['proof'] + ['status' => $proof->getStatus()]
                : ['operator' => $proof->getOperator(), 'status' => $proof->getStatus(), 'uploadedAt' => ApiDate::format($proof->getUploadedAt())];
        }
        if ($r->getPaidAt() !== null) {
            $data['paidAt'] = ApiDate::format($r->getPaidAt());
        }
        if ($r->getRefundSettledAt() !== null) {
            $data['refundSettledAt'] = ApiDate::format($r->getRefundSettledAt());
        }

        return $data;
    }

    /**
     * `AdminReservationDetailResponse`. Contrôle des captures et des références de transaction en double : pour le super
     * admin seulement (lui seul valide les paiements ; un vendeur ne voit pas les références hors de son périmètre).
     *
     * @param list<Agency> $agencies
     *
     * @return array<string, mixed>
     */
    public function detailResponse(Reservation $r, array $agencies, bool $superAdmin, \DateTimeImmutable $now): array
    {
        $proof = $r->latestProof();

        return [
            'reservation'              => $this->detail($r, $now, $superAdmin),
            'duplicateProofRefs'       => $proof !== null && $superAdmin ? $this->reservations->refsWithProof($proof->getSha256(), (int) $r->getId()) : [],
            'duplicateTransactionRefs' => $proof?->getTransactionId() !== null && $superAdmin ? $this->reservations->refsWithTransaction((string) $proof->getTransactionId(), (int) $r->getId()) : [],
            'internalNotes'            => $this->notes($r),
            'availableAgencies'        => array_map($this->catalog->agency(...), $agencies),
        ];
    }

    /** @return list<array{id: string, author: string, text: string, createdAt: string}> */
    public function notes(Reservation $r): array
    {
        return array_map(fn (ReservationNote $n) => $this->note($n), array_values($r->getInternalNotes()->toArray()));
    }

    /** @return array{id: string, author: string, text: string, createdAt: string} */
    public function note(ReservationNote $n): array
    {
        return [
            'id'        => (string) $n->getId(),
            'author'    => $n->getAuthor(),
            'text'      => $n->getText(),
            'createdAt' => ApiDate::format($n->getCreatedAt()),
        ];
    }

    private function latestProof(Reservation $r): ?ReservationProof
    {
        $latest = null;
        foreach ($r->getProofs() as $proof) {
            if ($latest === null || (int) $proof->getId() > (int) $latest->getId()) {
                $latest = $proof;
            }
        }

        return $latest?->isPurged() ? null : $latest;
    }
}
