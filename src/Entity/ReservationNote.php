<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Note interne de l'équipe sur une réservation (P6) : jamais montrée au client.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_notes')]
class ReservationNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class, inversedBy: 'internalNotes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Reservation $reservation;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $adminId;

    #[ORM\Column(type: 'string', length: 120)]
    private string $author;

    #[ORM\Column(type: 'text')]
    private string $text;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Reservation $reservation, Admin $admin, string $text, \DateTimeImmutable $createdAt)
    {
        $this->reservation = $reservation;
        $this->adminId = $admin->getId();
        $this->author = mb_substr($admin->getName(), 0, 120);
        $this->text = $text;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getReservation(): Reservation { return $this->reservation; }
    public function getAdminId(): ?int { return $this->adminId; }
    public function getAuthor(): string { return $this->author; }
    public function getText(): string { return $this->text; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
