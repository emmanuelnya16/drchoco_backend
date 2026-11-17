<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Photo du colis prêt (P6), prise par l'équipe : rangée hors du site public (var/private/packages), montrée au
 * client et à l'équipe par un lien signé et temporaire. Un nouvel envoi remplace les photos précédentes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_package_photos')]
class ReservationPackagePhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class, inversedBy: 'packagePhotos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Reservation $reservation;

    #[ORM\Column(type: 'smallint')]
    private int $position;

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

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $adminId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $takenAt;

    public function __construct(Reservation $reservation, int $position, string $storagePath, string $mimeType, int $bytes, int $width, int $height, ?Admin $admin, \DateTimeImmutable $takenAt)
    {
        $this->reservation = $reservation;
        $this->position = $position;
        $this->storagePath = $storagePath;
        $this->mimeType = $mimeType;
        $this->bytes = $bytes;
        $this->width = $width;
        $this->height = $height;
        $this->adminId = $admin?->getId();
        $this->takenAt = $takenAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getReservation(): Reservation { return $this->reservation; }
    public function getPosition(): int { return $this->position; }
    public function getStoragePath(): string { return $this->storagePath; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getBytes(): int { return $this->bytes; }
    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }
    public function getAdminId(): ?int { return $this->adminId; }
    public function getTakenAt(): \DateTimeImmutable { return $this->takenAt; }
}
