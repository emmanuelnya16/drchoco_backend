<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * File d'attente des notifications push (P7) : une ligne par appareil. Écrite après l'enregistrement de l'action
 * (une panne d'envoi n'annule jamais une réservation), envoyée juste après la réponse puis par la tâche
 * `app:push:send` (chaque minute), avec de nouveaux essais en cas d'erreur passagère du service de push.
 * Lue et mise à jour en SQL (App\Push\PushSender) ; l'entité sert au schéma.
 */
#[ORM\Entity]
#[ORM\Table(name: 'push_outbox')]
#[ORM\Index(name: 'idx_push_outbox_due', columns: ['status', 'next_attempt_at'])]
#[ORM\Index(name: 'idx_push_outbox_claim', columns: ['claim'])]
class PushOutbox
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DROPPED = 'dropped';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: PushSubscription::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PushSubscription $subscription;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Reservation $reservation = null;

    #[ORM\Column(type: 'string', length: 30)]
    private string $type;

    /** Contenu JSON envoyé tel quel au service worker (type, title, body, url, ref, image, tag). */
    #[ORM\Column(type: 'text')]
    private string $payload;

    #[ORM\Column(type: 'integer')]
    private int $ttl;

    #[ORM\Column(type: 'string', length: 8)]
    private string $urgency;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $topic = null;

    #[ORM\Column(type: 'string', length: 10)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $nextAttemptAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $claim = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $lastStatus = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    private function __construct() {}

    public function getId(): ?string { return $this->id; }
}
