<?php

namespace App\Entity;

use App\Repository\WhatsappLoginRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

/**
 * Connexion par WhatsApp (« code inversé ») : c'est le client qui envoie le code DRC-XXXXXX à la boutique, depuis le
 * WhatsApp du numéro saisi. Aucun SMS ni message payant n'est envoyé pour se connecter.
 *
 * Une ligne par demande. Le jeton remis à l'app (`requestId`) n'est jamais gardé en clair (empreinte HMAC) : lui seul
 * permet de récupérer la session une fois le message reçu. Une nouvelle demande du même numéro annule la précédente.
 * Un message venu d'un autre numéro ne confirme rien (la demande reste en attente, l'app prévient le client).
 * Les lignes servent aussi de compteur pour les limites ; elles sont effacées après 48 h (commande app:purge).
 */
#[ORM\Entity(repositoryClass: WhatsappLoginRequestRepository::class)]
#[ORM\Table(name: 'whatsapp_login_requests')]
#[ORM\Index(columns: ['code', 'status'], name: 'idx_wa_login_code')]
#[ORM\Index(columns: ['phone', 'created_at'], name: 'idx_wa_login_phone')]
#[ORM\Index(columns: ['created_at'], name: 'idx_wa_login_created')]
#[ORM\UniqueConstraint(name: 'uniq_wa_login_token', columns: ['request_token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_wa_login_message', columns: ['wa_message_id'])]
class WhatsappLoginRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CONSUMED = 'consumed';
    /** Remplacée par une demande plus récente du même numéro. */
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // Numéro saisi dans l'app, au format +2376XXXXXXXX : seul son WhatsApp peut confirmer la demande.
    #[ORM\Column(type: 'string', length: 20)]
    private string $phone;

    // Code montré à l'écran et prérempli dans WhatsApp : DRC- suivi de 6 chiffres.
    #[ORM\Column(type: 'string', length: 12)]
    private string $code;

    // Empreinte du jeton remis à l'app : jamais renvoyée dans une réponse de l'API.
    #[ORM\Column(type: 'string', length: 64)]
    #[Ignore]
    private string $requestTokenHash;

    #[ORM\Column(type: 'string', length: 12)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    // Message WhatsApp reçu du bon numéro, avec le bon code.
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    // Session ouverte : la demande ne peut plus servir.
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    // Identifiant du message chez Meta (wamid) : un même message reçu deux fois ne confirme qu'une fois.
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $waMessageId = null;

    // Le code a été envoyé depuis un autre numéro WhatsApp que celui saisi : l'app explique quoi faire.
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $wrongSenderAt = null;

    // Adresse IP de la demande (enquête en cas d'abus), effacée avec la ligne après 48 h.
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $requestIp = null;

    public function __construct(string $phone, string $code, string $requestTokenHash, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt, ?string $requestIp = null)
    {
        $this->phone = $phone;
        $this->code = $code;
        $this->requestTokenHash = $requestTokenHash;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
        $this->requestIp = $requestIp !== null ? mb_substr($requestIp, 0, 45) : null;
    }

    public function isPending(\DateTimeImmutable $now): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expiresAt > $now;
    }

    public function isConfirmed(\DateTimeImmutable $now): bool
    {
        return $this->status === self::STATUS_CONFIRMED && $this->expiresAt > $now;
    }

    /** Bon message reçu : l'app a encore $graceSeconds pour récupérer la session (retour depuis WhatsApp). */
    public function confirm(\DateTimeImmutable $now, string $waMessageId, int $graceSeconds): void
    {
        $this->status = self::STATUS_CONFIRMED;
        $this->confirmedAt = $now;
        $this->waMessageId = mb_substr($waMessageId, 0, 128);
        $grace = $now->modify(sprintf('+%d seconds', $graceSeconds));
        if ($grace > $this->expiresAt) {
            $this->expiresAt = $grace;
        }
    }

    public function markWrongSender(\DateTimeImmutable $now): void
    {
        $this->wrongSenderAt = $now;
    }

    public function consume(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_CONSUMED;
        $this->consumedAt = $now;
    }

    public function secondsLeft(\DateTimeImmutable $now): int
    {
        return max(0, $this->expiresAt->getTimestamp() - $now->getTimestamp());
    }

    public function getId(): ?int { return $this->id; }
    public function getPhone(): string { return $this->phone; }
    public function getCode(): string { return $this->code; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }
    public function getConsumedAt(): ?\DateTimeImmutable { return $this->consumedAt; }
    public function getWaMessageId(): ?string { return $this->waMessageId; }
    public function hasWrongSender(): bool { return $this->wrongSenderAt !== null; }
    public function getRequestIp(): ?string { return $this->requestIp; }
}
