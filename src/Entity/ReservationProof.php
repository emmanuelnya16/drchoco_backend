<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Preuve de paiement Mobile Money envoyée par le client (capture d'écran), gardée en privé sur le serveur
 * (var/private, hors du site public), sans ses métadonnées (position GPS, appareil…).
 * Toutes les preuves restent (historique) ; la plus récente est celle affichée. Le fichier, le numéro et la transaction
 * sont effacés au bout de 12 mois (App\Reservation\ReservationFileRetention).
 */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_proofs')]
#[ORM\Index(name: 'idx_reservation_proof_sha', columns: ['sha256'])]
class ReservationProof
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUPERSEDED = 'superseded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class, inversedBy: 'proofs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Reservation $reservation;

    /** Chemin relatif au dossier privé des médias. */
    #[ORM\Column(type: 'string', length: 255)]
    private string $storagePath;

    #[ORM\Column(type: 'string', length: 20)]
    private string $mimeType;

    #[ORM\Column(type: 'integer')]
    private int $bytes;

    #[ORM\Column(type: 'integer')]
    private int $width;

    #[ORM\Column(type: 'integer')]
    private int $height;

    /** SHA-256 de l'image gardée (calculé par le serveur) : une même capture ne sert pas deux réservations. */
    #[ORM\Column(type: 'string', length: 64)]
    private string $sha256;

    #[ORM\Column(type: 'string', length: 10)]
    private string $operator;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $payerPhone;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $transactionId;

    #[ORM\Column(type: 'string', length: 12)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $uploadedAt;

    public function __construct(
        Reservation $reservation,
        string $storagePath,
        string $mimeType,
        int $bytes,
        int $width,
        int $height,
        string $sha256,
        string $operator,
        ?string $payerPhone,
        ?string $transactionId,
        \DateTimeImmutable $uploadedAt,
    ) {
        $this->reservation = $reservation;
        $this->storagePath = $storagePath;
        $this->mimeType = $mimeType;
        $this->bytes = $bytes;
        $this->width = $width;
        $this->height = $height;
        $this->sha256 = $sha256;
        $this->operator = $operator;
        $this->payerPhone = $payerPhone;
        $this->transactionId = $transactionId;
        $this->uploadedAt = $uploadedAt;
    }

    /** Capture effacée (12 mois passés, ou client effacé) : il ne reste que son empreinte, contre une réutilisation. */
    public function isPurged(): bool { return $this->storagePath === ''; }

    public function getId(): ?int { return $this->id; }
    public function getReservation(): Reservation { return $this->reservation; }
    public function getStoragePath(): string { return $this->storagePath; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getBytes(): int { return $this->bytes; }
    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }
    public function getSha256(): string { return $this->sha256; }
    public function getOperator(): string { return $this->operator; }
    public function getPayerPhone(): ?string { return $this->payerPhone; }
    public function getTransactionId(): ?string { return $this->transactionId; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }
    public function getUploadedAt(): \DateTimeImmutable { return $this->uploadedAt; }
}
