<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne de l'historique d'une réservation (timeline du front) : statut atteint, par qui, quand, avec une note.
 * Pour une action de l'équipe, le compte admin est gardé (identifiant et nom, P6).
 */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_events')]
class ReservationEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Reservation $reservation;

    #[ORM\Column(type: 'string', length: 24)]
    private string $status;

    #[ORM\Column(type: 'string', length: 10)]
    private string $actor;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $note;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $adminId = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $adminName = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $at;

    public function __construct(Reservation $reservation, string $status, string $actor, ?string $note, \DateTimeImmutable $at, ?Admin $admin = null)
    {
        $this->reservation = $reservation;
        $this->status = $status;
        $this->actor = $actor;
        $this->note = $note !== null ? mb_substr($note, 0, 255) : null;
        $this->at = $at;
        $this->adminId = $admin?->getId();
        $this->adminName = $admin?->getName();
    }

    public function getId(): ?int { return $this->id; }
    public function getStatus(): string { return $this->status; }
    public function getActor(): string { return $this->actor; }
    public function getNote(): ?string { return $this->note; }
    public function getAdminId(): ?int { return $this->adminId; }
    public function getAdminName(): ?string { return $this->adminName; }
    public function getAt(): \DateTimeImmutable { return $this->at; }
}
