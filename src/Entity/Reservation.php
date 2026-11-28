<?php

namespace App\Entity;

use App\Repository\ReservationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réservation d'un client (contrat §10) : articles d'une seule ville de boutique, retirés en boutique ou livrés
 * (à domicile dans la ville de la boutique, sinon par une agence). Les articles, la boutique et l'agence sont
 * recopiés au moment de la réservation : la fiche reste juste même si le catalogue change ensuite.
 *
 * Le stock est retiré à la création et remis en vente une seule fois, au passage à « expirée » ou « annulée ».
 */
#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'reservations')]
#[ORM\UniqueConstraint(name: 'uniq_reservation_idempotency', columns: ['user_id', 'idempotency_key'])]
#[ORM\Index(name: 'idx_reservation_due', columns: ['status', 'expires_at'])]
#[ORM\Index(name: 'idx_reservation_user_updated', columns: ['user_id', 'updated_at'])]
#[ORM\Index(name: 'idx_reservation_shop', columns: ['shop_city', 'status', 'updated_at'])]
#[ORM\Index(name: 'idx_reservation_paid', columns: ['paid_at'])]
#[ORM\Index(name: 'idx_reservation_created', columns: ['created_at'])]
class Reservation
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_PENDING_VALIDATION = 'pending_validation';
    public const STATUS_PAYMENT_REJECTED = 'payment_rejected';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_HANDED_TO_AGENCY = 'handed_to_agency';
    public const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';
    public const STATUS_IN_DELIVERY = 'in_delivery';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_PICKED_UP = 'picked_up';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const FINAL_STATUSES = [self::STATUS_DELIVERED, self::STATUS_PICKED_UP, self::STATUS_EXPIRED, self::STATUS_CANCELLED];

    /** En attente du paiement du client : seuls ces statuts expirent à l'échéance. */
    public const AWAITING_PAYMENT = [self::STATUS_RESERVED, self::STATUS_PAYMENT_REJECTED];

    /** Annulables par le client lui-même (contrat §10.8). */
    public const CLIENT_CANCELLABLE = [self::STATUS_RESERVED, self::STATUS_PAYMENT_REJECTED, self::STATUS_PENDING_VALIDATION, self::STATUS_CONFIRMED];

    /**
     * Le client peut confirmer avoir reçu son colis (« J'ai bien reçu mon colis ») : colis prêt, remis à l'agence, en
     * route, ou déjà marqué remis par la boutique. Le statut ne change pas : la boutique confirme elle-même la remise.
     */
    public const CLIENT_RECEIPT_STATUSES = [
        self::STATUS_READY_FOR_PICKUP, self::STATUS_HANDED_TO_AGENCY, self::STATUS_IN_DELIVERY, self::STATUS_DELIVERED, self::STATUS_PICKED_UP,
    ];

    /** Paiement validé par la boutique (chiffre « confirmé » des clients et du tableau de bord). */
    public const PAID_STATUSES = [
        self::STATUS_CONFIRMED, self::STATUS_PREPARING, self::STATUS_HANDED_TO_AGENCY, self::STATUS_READY_FOR_PICKUP,
        self::STATUS_IN_DELIVERY, self::STATUS_DELIVERED, self::STATUS_PICKED_UP,
    ];

    public const MODE_DELIVERY = 'delivery';
    public const MODE_PICKUP = 'pickup';

    public const ACTOR_CLIENT = 'client';
    public const ACTOR_SHOP = 'shop';
    public const ACTOR_SYSTEM = 'system';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** RES-000001 : fixée juste après l'enregistrement, à partir de l'identifiant. */
    #[ORM\Column(type: 'string', length: 16, unique: true, nullable: true)]
    private ?string $ref = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'string', length: 24)]
    private string $status = self::STATUS_RESERVED;

    #[ORM\Column(type: 'string', length: 20)]
    private string $shopCity;

    #[ORM\Column(type: 'string', length: 10)]
    private string $receptionMode;

    #[ORM\Column(type: 'string', length: 100)]
    private string $customerFirstName;

    #[ORM\Column(type: 'string', length: 100, options: ['default' => ''])]
    private string $customerLastName = '';

    #[ORM\Column(type: 'string', length: 20)]
    private string $customerPhone;

    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $deliveryCityId = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $deliveryCityName = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $district = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $landmark = null;

    /** Agence choisie (identifiant et copie de la fiche, prix compris). */
    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $agencyId = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $agency = null;

    /** Boutique de retrait (copie de la fiche des réglages). @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $pickupShop = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $recipientName = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $recipientPhone = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'integer')]
    private int $subtotal = 0;

    #[ORM\Column(type: 'integer')]
    private int $deliveryFee = 0;

    #[ORM\Column(type: 'integer')]
    private int $total = 0;

    // Pièces du portefeuille utilisées (App\Wallet\CoinWallet) et réduction en FCFA, déjà retirée de `total`
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $coinsUsed = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $coinsDiscount = 0;

    // Pièces rendues au client (réservation expirée ou annulée) : une seule fois
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $coinsRefundedAt = null;

    #[ORM\Column(type: 'string', length: 12)]
    private string $deliveryFeePayment = 'upfront';

    #[ORM\Column(type: 'smallint')]
    private int $holdHours = 24;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    /** Rappel « plus que 2 h » déjà envoyé (une seule fois). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiringNotifiedAt = null;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $paymentOperator = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $rejectedAt = null;

    /** Paiement validé par la boutique (P6). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    /** Remboursement fait, ou constaté inutile (fausse capture), par un super admin (P9). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $refundSettledAt = null;

    /** Photos du colis prêt envoyées (P6). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $packageReadyAt = null;

    /** Réception du colis confirmée par le client ; la remise reste à confirmer par la boutique (statut inchangé). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $customerReceivedAt = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $idempotencyKey;

    /** Empreinte du corps de la demande : même clé + autre contenu = refus. */
    #[ORM\Column(type: 'string', length: 64)]
    private string $requestHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ReservationItem> */
    #[ORM\OneToMany(targetEntity: ReservationItem::class, mappedBy: 'reservation', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $items;

    /** @var Collection<int, ReservationEvent> */
    #[ORM\OneToMany(targetEntity: ReservationEvent::class, mappedBy: 'reservation', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $events;

    /** @var Collection<int, ReservationProof> */
    #[ORM\OneToMany(targetEntity: ReservationProof::class, mappedBy: 'reservation', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $proofs;

    /** @var Collection<int, ReservationPackagePhoto> */
    #[ORM\OneToMany(targetEntity: ReservationPackagePhoto::class, mappedBy: 'reservation', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $packagePhotos;

    /** @var Collection<int, ReservationNote> */
    #[ORM\OneToMany(targetEntity: ReservationNote::class, mappedBy: 'reservation', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $internalNotes;

    public function __construct(User $user, string $shopCity, string $receptionMode, \DateTimeImmutable $now)
    {
        $this->user = $user;
        $this->shopCity = $shopCity;
        $this->receptionMode = $receptionMode;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->expiresAt = $now;
        $this->items = new ArrayCollection();
        $this->events = new ArrayCollection();
        $this->proofs = new ArrayCollection();
        $this->packagePhotos = new ArrayCollection();
        $this->internalNotes = new ArrayCollection();
    }

    public function isFinal(): bool { return \in_array($this->status, self::FINAL_STATUSES, true); }
    public function isAwaitingPayment(): bool { return \in_array($this->status, self::AWAITING_PAYMENT, true); }
    public function isCancellableByClient(): bool { return \in_array($this->status, self::CLIENT_CANCELLABLE, true); }
    public function isOverdue(\DateTimeImmutable $now): bool { return $this->isAwaitingPayment() && $this->expiresAt <= $now; }

    /**
     * Annulée alors que le paiement était validé, ou qu'une preuve attendait encore d'être examinée (même effacée depuis) :
     * la boutique doit vérifier et rembourser, jusqu'à ce qu'un super admin close le remboursement.
     */
    public function isRefundDue(): bool
    {
        $last = $this->proofs->last();

        return $this->status === self::STATUS_CANCELLED && $this->refundSettledAt === null
            && ($this->paidAt !== null || ($last instanceof ReservationProof && $last->getStatus() === ReservationProof::STATUS_PENDING));
    }

    /** « J'ai bien reçu mon colis » encore possible : colis remis ou en route, pas encore confirmé par le client. */
    public function canClientConfirmReceipt(): bool
    {
        return $this->customerReceivedAt === null && \in_array($this->status, self::CLIENT_RECEIPT_STATUSES, true);
    }

    public function getCustomerReceivedAt(): ?\DateTimeImmutable { return $this->customerReceivedAt; }

    /** Réception confirmée par le client : remonte la réservation dans la liste de l'équipe, statut inchangé. */
    public function confirmReceiptByClient(\DateTimeImmutable $now): static
    {
        $this->customerReceivedAt = $now;
        $this->updatedAt = $now;

        return $this;
    }

    public function getRefundSettledAt(): ?\DateTimeImmutable { return $this->refundSettledAt; }
    public function settleRefund(\DateTimeImmutable $now): static { $this->refundSettledAt = $now; return $this; }

    /** Nouveau statut et sa ligne d'historique. */
    public function record(string $status, string $actor, ?string $note, \DateTimeImmutable $now, ?Admin $admin = null): ReservationEvent
    {
        $this->status = $status;
        $this->updatedAt = $now;
        $event = new ReservationEvent($this, $status, $actor, $note, $now, $admin);
        $this->events->add($event);

        return $event;
    }

    public function addItem(ReservationItem $item): void { $this->items->add($item); }
    public function addProof(ReservationProof $proof): void { $this->proofs->add($proof); }

    /** Dernière preuve envoyée (null si aucune, ou si elle a été effacée après 12 mois). */
    public function latestProof(): ?ReservationProof
    {
        $last = $this->proofs->last();

        return $last instanceof ReservationProof && !$last->isPurged() ? $last : null;
    }

    /** Quantité totale (Σ des quantités). */
    public function itemsCount(): int
    {
        $n = 0;
        foreach ($this->items as $item) {
            $n += $item->getQuantity();
        }

        return $n;
    }

    public function getId(): ?int { return $this->id; }
    public function getRef(): ?string { return $this->ref; }
    public function setRef(string $v): static { $this->ref = $v; return $this; }
    public function getUser(): User { return $this->user; }
    public function getStatus(): string { return $this->status; }
    public function getShopCity(): string { return $this->shopCity; }
    public function getReceptionMode(): string { return $this->receptionMode; }
    public function isPickup(): bool { return $this->receptionMode === self::MODE_PICKUP; }

    public function getCustomerFirstName(): string { return $this->customerFirstName; }
    public function getCustomerLastName(): string { return $this->customerLastName; }
    public function getCustomerPhone(): string { return $this->customerPhone; }
    public function setCustomer(string $firstName, string $lastName, string $phone): static
    {
        $this->customerFirstName = $firstName;
        $this->customerLastName = $lastName;
        $this->customerPhone = $phone;

        return $this;
    }

    public function getDeliveryCityId(): ?string { return $this->deliveryCityId; }
    public function getDeliveryCityName(): ?string { return $this->deliveryCityName; }
    public function setDeliveryCity(?string $id, ?string $name): static { $this->deliveryCityId = $id; $this->deliveryCityName = $name; return $this; }
    public function getDistrict(): ?string { return $this->district; }
    public function getLandmark(): ?string { return $this->landmark; }
    public function setHomeAddress(?string $district, ?string $landmark): static { $this->district = $district; $this->landmark = $landmark; return $this; }
    public function getAgencyId(): ?string { return $this->agencyId; }
    /** @return array<string, mixed>|null */
    public function getAgency(): ?array { return $this->agency; }
    /** @param array<string, mixed>|null $snapshot */
    public function setAgency(?string $id, ?array $snapshot): static { $this->agencyId = $id; $this->agency = $snapshot; return $this; }
    /** @return array<string, mixed>|null */
    public function getPickupShop(): ?array { return $this->pickupShop; }
    /** @param array<string, mixed>|null $v */
    public function setPickupShop(?array $v): static { $this->pickupShop = $v; return $this; }
    public function getRecipientName(): ?string { return $this->recipientName; }
    public function getRecipientPhone(): ?string { return $this->recipientPhone; }
    public function setRecipient(?string $name, ?string $phone): static { $this->recipientName = $name; $this->recipientPhone = $phone; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): static { $this->notes = $v; return $this; }

    public function getSubtotal(): int { return $this->subtotal; }
    public function getDeliveryFee(): int { return $this->deliveryFee; }
    public function getTotal(): int { return $this->total; }
    public function getDeliveryFeePayment(): string { return $this->deliveryFeePayment; }
    /**
     * `total` : montant à payer maintenant. Frais payés à la livraison : il n'en tient pas compte (contrat §10.1).
     * Réduction des pièces : sur les articles seulement, jamais sur les frais de livraison.
     */
    public function setAmounts(int $subtotal, int $deliveryFee, string $feePayment, int $coinsUsed = 0, int $coinsDiscount = 0): static
    {
        $this->subtotal = $subtotal;
        $this->deliveryFee = $deliveryFee;
        $this->deliveryFeePayment = $feePayment === 'on_delivery' ? 'on_delivery' : 'upfront';
        $this->coinsDiscount = max(0, min($coinsDiscount, $subtotal));
        $this->coinsUsed = $this->coinsDiscount > 0 ? max(0, $coinsUsed) : 0;
        $articles = $subtotal - $this->coinsDiscount;
        $this->total = $this->deliveryFeePayment === 'upfront' ? $articles + $deliveryFee : $articles;

        return $this;
    }

    public function getCoinsUsed(): int { return $this->coinsUsed; }
    public function getCoinsDiscount(): int { return $this->coinsDiscount; }
    public function getCoinsRefundedAt(): ?\DateTimeImmutable { return $this->coinsRefundedAt; }
    public function setCoinsRefundedAt(?\DateTimeImmutable $v): static { $this->coinsRefundedAt = $v; return $this; }

    public function getHoldHours(): int { return $this->holdHours; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setHold(int $hours, \DateTimeImmutable $expiresAt): static { $this->holdHours = $hours; $this->expiresAt = $expiresAt; return $this; }
    public function setExpiresAt(\DateTimeImmutable $v): static { $this->expiresAt = $v; return $this; }
    public function getExpiringNotifiedAt(): ?\DateTimeImmutable { return $this->expiringNotifiedAt; }
    public function setExpiringNotifiedAt(?\DateTimeImmutable $v): static { $this->expiringNotifiedAt = $v; return $this; }

    public function getPaymentOperator(): ?string { return $this->paymentOperator; }
    public function setPaymentOperator(?string $v): static { $this->paymentOperator = $v; return $this; }
    public function getRejectionReason(): ?string { return $this->rejectionReason; }
    public function getRejectedAt(): ?\DateTimeImmutable { return $this->rejectedAt; }
    public function setRejection(?string $reason, ?\DateTimeImmutable $at): static { $this->rejectionReason = $reason; $this->rejectedAt = $at; return $this; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $v): static { $this->paidAt = $v; return $this; }
    public function getPackageReadyAt(): ?\DateTimeImmutable { return $this->packageReadyAt; }

    /**
     * Remplace les photos du colis (les anciennes lignes sont supprimées à l'enregistrement).
     *
     * @param list<ReservationPackagePhoto> $photos
     */
    public function replacePackagePhotos(array $photos, \DateTimeImmutable $now): void
    {
        $this->packagePhotos->clear();
        foreach ($photos as $photo) {
            $this->packagePhotos->add($photo);
        }
        $this->packageReadyAt = $now;
        $this->updatedAt = $now;
    }

    public function addInternalNote(ReservationNote $note): void { $this->internalNotes->add($note); }

    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getRequestHash(): string { return $this->requestHash; }
    public function setIdempotency(string $key, string $hash): static { $this->idempotencyKey = $key; $this->requestHash = $hash; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, ReservationItem> */
    public function getItems(): Collection { return $this->items; }
    /** @return Collection<int, ReservationEvent> */
    public function getEvents(): Collection { return $this->events; }
    /** @return Collection<int, ReservationProof> */
    public function getProofs(): Collection { return $this->proofs; }
    /** @return Collection<int, ReservationPackagePhoto> */
    public function getPackagePhotos(): Collection { return $this->packagePhotos; }
    /** @return Collection<int, ReservationNote> */
    public function getInternalNotes(): Collection { return $this->internalNotes; }
}
