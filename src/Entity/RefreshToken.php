<?php

namespace App\Entity;

use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Jeton de rafraîchissement (session longue), stocké haché.
 *
 * Repris du backend de référence (jeton opaque de 64 caractères, hash SHA-256, usage unique,
 * révocation, IP et navigateur), avec en plus :
 *  - une famille (familyId) : tous les jetons issus d'une même connexion, sur un même appareil ;
 *  - la date d'utilisation (usedAt) : délai de grâce de 30 s quand deux onglets rafraîchissent ensemble ;
 *  - une fin de session absolue (sessionExpiresAt) : utilisée pour l'admin (12 h après la connexion).
 */
#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_tokens')]
#[ORM\Index(columns: ['user_type', 'user_id'], name: 'idx_refresh_user')]
#[ORM\Index(columns: ['family_id'], name: 'idx_refresh_family')]
class RefreshToken
{
    public const TYPE_USER  = 'user';
    public const TYPE_ADMIN = 'admin';

    // Délai pendant lequel un jeton déjà utilisé reste acceptable (rafraîchissements simultanés).
    public const GRACE_SECONDS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // On ne stocke JAMAIS le jeton en clair : seulement son empreinte SHA-256.
    #[ORM\Column(type: 'string', length: 128, unique: true)]
    private string $tokenHash;

    #[ORM\Column(type: 'string', length: 10)]
    private string $userType;

    #[ORM\Column(type: 'integer')]
    private int $userId;

    #[ORM\Column(type: 'string', length: 32)]
    private string $familyId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    // Fin absolue de la session (admin). Vide pour le client (session glissante).
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sessionExpiresAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $createdFromIp = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isUsed = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isRevoked = false;

    public function __construct(\DateTimeImmutable $now, \DateTimeImmutable $expiresAt)
    {
        $this->createdAt = $now;
        $this->expiresAt = $expiresAt;
    }

    // ── Helpers métier ────────────────────────────────────────────────────

    /** Génère un jeton aléatoire : le clair part dans le cookie, le hash va en base. */
    public static function generate(): array
    {
        $plain = bin2hex(random_bytes(32));

        return ['plain' => $plain, 'hash' => self::hash($plain)];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function newFamilyId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt
            || ($this->sessionExpiresAt !== null && $now >= $this->sessionExpiresAt);
    }

    /** Jeton déjà utilisé, mais il y a moins de 30 s : un autre onglet a rafraîchi en même temps. */
    public function isWithinGrace(\DateTimeImmutable $now): bool
    {
        return $this->isUsed
            && $this->usedAt !== null
            && ($now->getTimestamp() - $this->usedAt->getTimestamp()) <= self::GRACE_SECONDS;
    }

    public function markAsUsed(\DateTimeImmutable $now): static
    {
        if (!$this->isUsed) {
            $this->isUsed = true;
            $this->usedAt = $now;
        }

        return $this;
    }

    public function revoke(): static
    {
        $this->isRevoked = true;

        return $this;
    }

    // ── Getters / Setters ─────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getTokenHash(): string { return $this->tokenHash; }
    public function setTokenHash(string $v): static { $this->tokenHash = $v; return $this; }

    public function getUserType(): string { return $this->userType; }
    public function setUserType(string $v): static { $this->userType = $v; return $this; }

    public function getUserId(): int { return $this->userId; }
    public function setUserId(int $v): static { $this->userId = $v; return $this; }

    public function getFamilyId(): string { return $this->familyId; }
    public function setFamilyId(string $v): static { $this->familyId = $v; return $this; }

    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $v): static { $this->expiresAt = $v; return $this; }

    public function getSessionExpiresAt(): ?\DateTimeImmutable { return $this->sessionExpiresAt; }
    public function setSessionExpiresAt(?\DateTimeImmutable $v): static { $this->sessionExpiresAt = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getCreatedFromIp(): ?string { return $this->createdFromIp; }
    public function setCreatedFromIp(?string $v): static { $this->createdFromIp = $v; return $this; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $v): static { $this->userAgent = $v; return $this; }

    public function isUsed(): bool { return $this->isUsed; }
    public function getUsedAt(): ?\DateTimeImmutable { return $this->usedAt; }
    public function isRevoked(): bool { return $this->isRevoked; }
}
