<?php

namespace App\Entity;

use App\Repository\WhatsappLoginRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande de code par WhatsApp (WHATSAPP_OTP.md) : l'app enregistre le numéro saisi et une référence (6 caractères) ;
 * le client envoie la référence à la boutique sur WhatsApp, la boutique lui répond avec un code à 6 chiffres.
 *
 * Le code n'est envoyé que si le message vient du WhatsApp du numéro saisi : la référence seule ne prouve rien (elle
 * est donnée à quiconque tape un numéro). Un message venu d'un autre WhatsApp reçoit une explication, rien d'autre.
 * Une nouvelle demande du même numéro annule la précédente ; une demande ne donne qu'un code.
 * Les lignes servent aussi de compteur pour les limites ; elles sont effacées après 48 h (commande app:purge).
 */
#[ORM\Entity(repositoryClass: WhatsappLoginRequestRepository::class)]
#[ORM\Table(name: 'whatsapp_login_requests')]
#[ORM\Index(columns: ['phone', 'created_at'], name: 'idx_wa_login_phone')]
#[ORM\Index(columns: ['created_at'], name: 'idx_wa_login_created')]
#[ORM\UniqueConstraint(name: 'uniq_wa_login_reference', columns: ['reference'])]
#[ORM\UniqueConstraint(name: 'uniq_wa_login_message', columns: ['wa_message_id'])]
class WhatsappLoginRequest
{
    public const STATUS_PENDING = 'pending';
    /** Code envoyé au client sur WhatsApp : la demande ne sert plus. */
    public const STATUS_CODE_SENT = 'code_sent';
    /** Remplacée par une demande plus récente du même numéro. */
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // Numéro saisi dans l'app, au format +2376XXXXXXXX : seul son WhatsApp reçoit le code.
    #[ORM\Column(type: 'string', length: 20)]
    private string $phone;

    // Référence écrite dans le message WhatsApp prérempli (6 caractères, sans I, O, 0 ni 1).
    #[ORM\Column(type: 'string', length: 8)]
    private string $reference;

    #[ORM\Column(type: 'string', length: 12)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $codeSentAt = null;

    // Identifiant chez Meta (wamid) du message qui a obtenu le code : un même message reçu deux fois ne compte qu'une fois.
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $waMessageId = null;

    // Référence envoyée depuis un autre WhatsApp que celui du numéro saisi (aucun code envoyé).
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $wrongSenderAt = null;

    // Adresse IP de la demande (enquête en cas d'abus), effacée avec la ligne après 48 h.
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $requestIp = null;

    public function __construct(string $phone, string $reference, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt, ?string $requestIp = null)
    {
        $this->phone = $phone;
        $this->reference = $reference;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
        $this->requestIp = $requestIp !== null ? mb_substr($requestIp, 0, 45) : null;
    }

    public function isPending(\DateTimeImmutable $now): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expiresAt > $now;
    }

    public function isCodeSent(): bool
    {
        return $this->status === self::STATUS_CODE_SENT;
    }

    public function markCodeSent(\DateTimeImmutable $now, string $waMessageId): void
    {
        $this->status = self::STATUS_CODE_SENT;
        $this->codeSentAt = $now;
        $this->waMessageId = mb_substr($waMessageId, 0, 128);
    }

    public function markWrongSender(\DateTimeImmutable $now): void
    {
        $this->wrongSenderAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getPhone(): string { return $this->phone; }
    public function getReference(): string { return $this->reference; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getCodeSentAt(): ?\DateTimeImmutable { return $this->codeSentAt; }
    public function getWaMessageId(): ?string { return $this->waMessageId; }
    public function hasWrongSender(): bool { return $this->wrongSenderAt !== null; }
    public function getRequestIp(): ?string { return $this->requestIp; }
}
