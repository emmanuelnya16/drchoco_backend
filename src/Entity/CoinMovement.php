<?php

namespace App\Entity;

use App\Repository\CoinMovementRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mouvement du portefeuille de pièces d'un client (App\Wallet\CoinWallet) : bonus de bienvenue, pièces utilisées pour
 * une réservation, pièces rendues (réservation expirée ou annulée). Jamais modifié ni supprimé.
 */
#[ORM\Entity(repositoryClass: CoinMovementRepository::class)]
#[ORM\Table(name: 'coin_movements')]
#[ORM\Index(name: 'idx_coin_movements_user', columns: ['user_id', 'created_at'])]
#[ORM\Index(name: 'idx_coin_movements_reservation', columns: ['reservation_id'])]
class CoinMovement
{
    public const REASON_WELCOME = 'welcome';
    public const REASON_SPENT = 'spent';
    public const REASON_REFUND = 'refund';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(name: 'reservation_id', nullable: true, onDelete: 'SET NULL')]
    private ?Reservation $reservation;

    /** Pièces ajoutées (positif) ou retirées (négatif). */
    #[ORM\Column(type: 'integer')]
    private int $amount;

    #[ORM\Column(type: 'integer')]
    private int $balanceAfter;

    #[ORM\Column(type: 'string', length: 16)]
    private string $reason;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, int $amount, int $balanceAfter, string $reason, \DateTimeImmutable $createdAt, ?Reservation $reservation = null)
    {
        $this->user = $user;
        $this->amount = $amount;
        $this->balanceAfter = $balanceAfter;
        $this->reason = $reason;
        $this->createdAt = $createdAt;
        $this->reservation = $reservation;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getReservation(): ?Reservation { return $this->reservation; }
    public function getAmount(): int { return $this->amount; }
    public function getBalanceAfter(): int { return $this->balanceAfter; }
    public function getReason(): string { return $this->reason; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
