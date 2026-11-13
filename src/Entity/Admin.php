<?php

namespace App\Entity;

use App\Repository\AdminRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compte de l'équipe DR Choco : super admin (owner, manager) ou vendeur (seller).
 *
 * Repris du backend de référence (e-mail + mot de passe bcrypt, actif/inactif, dernière connexion),
 * adapté au front DR Choco (AdminUser : name, phone, role owner|manager|seller, city).
 * Le parcours d'activation par jeton du backend de référence n'existe pas chez DR Choco : retiré.
 */
#[ORM\Entity(repositoryClass: AdminRepository::class)]
#[ORM\Table(name: 'admins')]
#[ORM\HasLifecycleCallbacks]
class Admin implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_OWNER   = 'owner';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_SELLER  = 'seller';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_MANAGER, self::ROLE_SELLER];

    // Villes possibles pour un vendeur (référentiel complet en base à partir de P1/P3).
    public const CITIES = ['Yaoundé', 'Douala'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    // Téléphone E.164 (+2376XXXXXXXX), utilisable aussi comme identifiant de connexion.
    #[ORM\Column(type: 'string', length: 20, unique: true, nullable: true)]
    #[Assert\Regex(pattern: '/^\+2376\d{8}$/', message: 'Numéro camerounais invalide.')]
    private ?string $phone = null;

    #[ORM\Column(type: 'string')]
    private string $password;

    #[ORM\Column(type: 'string', length: 20)]
    #[Assert\Choice(choices: self::ROLES)]
    private string $role = self::ROLE_SELLER;

    // Obligatoire pour un vendeur, vide pour un super admin (qui voit toutes les villes).
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ── Sécurité ──────────────────────────────────────────────────────────

    public function getUserIdentifier(): string { return $this->email; }

    public function getRoles(): array
    {
        return ['ROLE_' . strtoupper($this->role), 'ROLE_ADMIN'];
    }

    public function eraseCredentials(): void {}

    // ── Helpers métier ────────────────────────────────────────────────────

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_OWNER || $this->role === self::ROLE_MANAGER;
    }

    public function isSeller(): bool
    {
        return $this->role === self::ROLE_SELLER;
    }

    // ── Getters / Setters ─────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $v): static { $this->email = mb_strtolower(trim($v)); return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $v): static { $this->phone = $v; return $this; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $v): static { $this->password = $v; return $this; }

    public function getRole(): string { return $this->role; }
    public function setRole(string $v): static { $this->role = $v; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $v): static { $this->city = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeImmutable $v): static { $this->lastLoginAt = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
