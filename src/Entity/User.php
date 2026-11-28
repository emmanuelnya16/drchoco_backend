<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Client DR Choco.
 *
 * Repris du backend de référence (identifiant = téléphone, statut actif/bloqué),
 * sans mot de passe : la connexion se fait par code SMS (phase P2).
 * Le compte est créé au premier code validé ; prénom et nom sont complétés ensuite (PATCH /me).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface
{
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_BLOCKED = 'blocked';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // Numéro au format E.164 camerounais : +2376XXXXXXXX
    #[ORM\Column(type: 'string', length: 20, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\+2376\d{8}$/', message: 'Numéro camerounais invalide.')]
    private string $phone;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'active'])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // Dernière connexion par code SMS (P2) : utile à l'administration (répertoire des clients).
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    // Portefeuille de pièces (App\Wallet\CoinWallet) : solde, l'historique est dans coin_movements.
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $coins = 0;

    public function __construct(?\DateTimeImmutable $createdAt = null)
    {
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ── Sécurité ──────────────────────────────────────────────────────────

    public function getUserIdentifier(): string { return $this->phone; }
    public function getRoles(): array { return ['ROLE_USER']; }
    public function eraseCredentials(): void {}

    // ── Getters / Setters ─────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getPhone(): string { return $this->phone; }
    public function setPhone(string $v): static { $this->phone = $v; return $this; }

    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(?string $v): static { $this->firstName = $v; return $this; }

    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(?string $v): static { $this->lastName = $v; return $this; }

    public function getFullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $v): static { $this->city = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }
    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function isBlocked(): bool { return $this->status === self::STATUS_BLOCKED; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeImmutable $v): static { $this->lastLoginAt = $v; return $this; }

    public function getCoins(): int { return $this->coins; }
    /** Seulement par App\Wallet\CoinWallet, qui garde l'historique. */
    public function setCoins(int $v): static { $this->coins = max(0, $v); return $this; }
}
