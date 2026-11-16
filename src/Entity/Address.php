<?php

namespace App\Entity;

use App\Repository\AddressRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Adresse du carnet d'un client (contrat §5.3 à §5.6, annexe A.7).
 *
 * - Ville locale (Yaoundé, livraison à domicile) : quartier + point de repère.
 * - Ville desservie par agence : district = nom de la ville, landmark = nom de l'agence, agencyId.
 * Une seule adresse par défaut par client ; la première adresse l'est d'office.
 */
#[ORM\Entity(repositoryClass: AddressRepository::class)]
#[ORM\Table(name: 'addresses')]
#[ORM\Index(columns: ['user_id', 'is_default', 'sequence'], name: 'idx_address_user_order')]
#[ORM\HasLifecycleCallbacks]
class Address
{
    public const LABELS = ['Maison', 'Bureau', 'Autre'];
    public const RECEPTION_MODES = ['delivery', 'pickup'];

    // Identifiant aléatoire (« addr-3f9c0a1b2c4d5e6f ») : impossible à deviner.
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 40)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 20)]
    private string $label = 'Maison';

    #[ORM\Column(type: 'string', length: 100)]
    private string $city = '';

    #[ORM\Column(type: 'string', length: 120)]
    private string $district = '';

    #[ORM\Column(type: 'string', length: 255)]
    private string $landmark = '';

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'delivery'])]
    private string $receptionMode = 'delivery';

    // Agence de transport (ville non locale). Simple identifiant : l'adresse reste lisible même si
    // l'agence est retirée plus tard (elle sera revérifiée au moment de la réservation).
    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $agencyId = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $recipientName = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $recipientPhone = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isDefault = false;

    // Ordre d'ajout dans le carnet (l'adresse par défaut est toujours affichée en tête).
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sequence = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(User $user, int $sequence, \DateTimeImmutable $createdAt)
    {
        $this->id = self::newId();
        $this->user = $user;
        $this->sequence = $sequence;
        $this->createdAt = $createdAt;
    }

    public static function newId(): string
    {
        return 'addr-' . bin2hex(random_bytes(8));
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Contenu comparable (dédoublonnage d'un double appui sur « Enregistrer »). */
    public function contentKey(): string
    {
        return implode("\u{1F}", [
            $this->label, $this->city, $this->district, $this->landmark, $this->receptionMode,
            $this->agencyId ?? '', $this->recipientName ?? '', $this->recipientPhone ?? '',
        ]);
    }

    // ── Getters / Setters ─────────────────────────────────────────────────

    public function getId(): string { return $this->id; }
    public function getUser(): User { return $this->user; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $v): static { $this->label = $v; return $this; }

    public function getCity(): string { return $this->city; }
    public function setCity(string $v): static { $this->city = $v; return $this; }

    public function getDistrict(): string { return $this->district; }
    public function setDistrict(string $v): static { $this->district = $v; return $this; }

    public function getLandmark(): string { return $this->landmark; }
    public function setLandmark(string $v): static { $this->landmark = $v; return $this; }

    public function getReceptionMode(): string { return $this->receptionMode; }
    public function setReceptionMode(string $v): static { $this->receptionMode = $v; return $this; }

    public function getAgencyId(): ?string { return $this->agencyId; }
    public function setAgencyId(?string $v): static { $this->agencyId = $v; return $this; }

    public function getRecipientName(): ?string { return $this->recipientName; }
    public function setRecipientName(?string $v): static { $this->recipientName = $v; return $this; }

    public function getRecipientPhone(): ?string { return $this->recipientPhone; }
    public function setRecipientPhone(?string $v): static { $this->recipientPhone = $v; return $this; }

    public function isDefault(): bool { return $this->isDefault; }
    public function setIsDefault(bool $v): static { $this->isDefault = $v; return $this; }

    public function getSequence(): int { return $this->sequence; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
