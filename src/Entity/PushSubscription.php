<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Appareil abonné aux notifications Web Push (P7) : d'un client (`user`) ou d'un membre de l'équipe (`admin`).
 * Le même navigateur peut être abonné des deux côtés (une ligne par côté) ; de chaque côté, un endpoint appartient
 * au dernier compte qui l'a envoyé. L'endpoint et les clés ne sont jamais écrits dans le journal.
 */
#[ORM\Entity]
#[ORM\Table(name: 'push_subscriptions')]
#[ORM\UniqueConstraint(name: 'uniq_push_owner_endpoint', columns: ['owner_kind', 'endpoint_hash'])]
#[ORM\Index(name: 'idx_push_last_seen', columns: ['last_seen_at'])]
class PushSubscription
{
    public const KIND_USER = 'user';
    public const KIND_ADMIN = 'admin';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 5)]
    private string $ownerKind;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Admin $admin = null;

    #[ORM\Column(type: 'string', length: 1000)]
    private string $endpoint;

    #[ORM\Column(type: 'string', length: 64)]
    private string $endpointHash;

    #[ORM\Column(type: 'string', length: 120)]
    private string $p256dh;

    #[ORM\Column(type: 'string', length: 40)]
    private string $auth;

    #[ORM\Column(type: 'string', length: 10, options: ['default' => 'aes128gcm'])]
    private string $contentEncoding = 'aes128gcm';

    #[ORM\Column(type: 'string', length: 10)]
    private string $platform = 'web';

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSuccessAt = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $failureCount = 0;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $lastStatus = null;

    public function __construct(User|Admin $owner, string $endpoint, \DateTimeImmutable $now)
    {
        $this->setOwner($owner);
        $this->endpoint = $endpoint;
        $this->endpointHash = self::hash($endpoint);
        $this->createdAt = $now;
        $this->lastSeenAt = $now;
    }

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function setOwner(User|Admin $owner): static
    {
        $this->ownerKind = $owner instanceof Admin ? self::KIND_ADMIN : self::KIND_USER;
        $this->user = $owner instanceof User ? $owner : null;
        $this->admin = $owner instanceof Admin ? $owner : null;

        return $this;
    }

    public function isOwnedBy(User|Admin $owner): bool
    {
        return $owner instanceof Admin ? $this->admin?->getId() === $owner->getId() : $this->user?->getId() === $owner->getId();
    }

    /** Clés envoyées par le navigateur ; un nouvel enregistrement remet le compteur d'échecs à zéro. */
    public function refresh(string $p256dh, string $auth, string $platform, ?string $userAgent, \DateTimeImmutable $now): static
    {
        $this->p256dh = $p256dh;
        $this->auth = $auth;
        // Le service worker envoie toujours « web » : il n'écrase pas un « ios » ou « android » déjà connu.
        if ($platform !== 'web' || !isset($this->platform) || $this->platform === '') {
            $this->platform = $platform;
        }
        if ($userAgent !== null) {
            $this->userAgent = $userAgent;
        }
        $this->lastSeenAt = $now;
        $this->failureCount = 0;

        return $this;
    }

    public function getId(): ?int { return $this->id; }
    public function getOwnerKind(): string { return $this->ownerKind; }
    public function getUser(): ?User { return $this->user; }
    public function getAdmin(): ?Admin { return $this->admin; }
    public function getEndpoint(): string { return $this->endpoint; }
    public function getEndpointHash(): string { return $this->endpointHash; }
    public function getP256dh(): string { return $this->p256dh; }
    public function getAuth(): string { return $this->auth; }
    public function getContentEncoding(): string { return $this->contentEncoding; }
    public function getPlatform(): string { return $this->platform; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function getLastSuccessAt(): ?\DateTimeImmutable { return $this->lastSuccessAt; }
    public function getFailureCount(): int { return $this->failureCount; }
    public function getLastStatus(): ?int { return $this->lastStatus; }
}
