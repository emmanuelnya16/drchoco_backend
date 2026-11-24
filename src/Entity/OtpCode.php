<?php

namespace App\Entity;

use App\Repository\OtpCodeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

/**
 * Code de connexion envoyé par SMS (contrat §4.1, §4.2).
 *
 * Une ligne par code envoyé. Seul le dernier code d'un numéro compte : un nouveau code annule le
 * précédent et remet les essais à 5. Le code n'est jamais stocké en clair (empreinte HMAC-SHA256).
 * Tant que le SMS n'est pas accepté par le fournisseur (sentAt vide), le code ne peut pas être saisi : personne ne
 * peut le deviner pendant l'envoi, ni après un envoi refusé (la ligne est alors effacée).
 * Les lignes servent aussi de compteur pour les limites (3 demandes en 10 min, 10 par jour) ;
 * elles sont effacées après 48 h (commande app:purge).
 */
#[ORM\Entity(repositoryClass: OtpCodeRepository::class)]
#[ORM\Table(name: 'otp_codes')]
#[ORM\Index(columns: ['phone', 'created_at'], name: 'idx_otp_phone_created')]
#[ORM\Index(columns: ['created_at'], name: 'idx_otp_created')]
class OtpCode
{
    public const MAX_ATTEMPTS = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $phone;

    // Empreinte du code : jamais renvoyée dans une réponse de l'API.
    #[ORM\Column(type: 'string', length: 64)]
    #[Ignore]
    private string $codeHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    // SMS accepté par le fournisseur : le code peut être saisi à partir de cette date.
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'smallint', options: ['default' => self::MAX_ATTEMPTS])]
    private int $attemptsLeft = self::MAX_ATTEMPTS;

    // Numéro verrouillé après 5 codes faux, jusqu'à cette date.
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    // Code utilisé (connexion réussie) : il ne peut plus servir.
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    // Adresse IP de la demande (enquête en cas d'abus), effacée avec la ligne après 48 h.
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $requestIp = null;

    public function __construct(string $phone, string $codeHash, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt, ?string $requestIp = null)
    {
        $this->phone = $phone;
        $this->codeHash = $codeHash;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
        $this->requestIp = $requestIp !== null ? mb_substr($requestIp, 0, 45) : null;
    }

    // ── Helpers métier ────────────────────────────────────────────────────

    public function isLocked(\DateTimeImmutable $now): bool
    {
        return $this->lockedUntil !== null && $now < $this->lockedUntil;
    }

    /** Code encore utilisable : ni utilisé, ni expiré. */
    public function isUsable(\DateTimeImmutable $now): bool
    {
        return $this->consumedAt === null && $now < $this->expiresAt;
    }

    /** Âge du code, en secondes. */
    public function ageInSeconds(\DateTimeImmutable $now): int
    {
        return $now->getTimestamp() - $this->createdAt->getTimestamp();
    }

    /** Enregistre un code faux ; au 5e, le numéro est verrouillé. Renvoie les essais restants. */
    public function registerFailure(\DateTimeImmutable $now, int $lockSeconds): int
    {
        $this->attemptsLeft = max(0, $this->attemptsLeft - 1);
        if ($this->attemptsLeft === 0) {
            $this->lockedUntil = $now->modify(sprintf('+%d seconds', $lockSeconds));
        }

        return $this->attemptsLeft;
    }

    public function consume(\DateTimeImmutable $now): void
    {
        $this->consumedAt = $now;
    }

    public function markSent(\DateTimeImmutable $now): void
    {
        $this->sentAt = $now;
    }

    // ── Getters ───────────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }
    public function getPhone(): string { return $this->phone; }

    #[Ignore]
    public function getCodeHash(): string { return $this->codeHash; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function getAttemptsLeft(): int { return $this->attemptsLeft; }
    public function getLockedUntil(): ?\DateTimeImmutable { return $this->lockedUntil; }
    public function getConsumedAt(): ?\DateTimeImmutable { return $this->consumedAt; }
    public function getRequestIp(): ?string { return $this->requestIp; }
}
