<?php

namespace App\Entity;

use App\Repository\AgencyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Agence de voyage d'une ville non locale, où le client récupère son colis.
 */
#[ORM\Entity(repositoryClass: AgencyRepository::class)]
#[ORM\Table(name: 'agencies')]
#[ORM\HasLifecycleCallbacks]
class Agency
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 40)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: DeliveryCity::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DeliveryCity $city;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $address = '';

    // Texte affiché tel quel (« +237 690 10 11 01 »), contrat §1.3.
    #[ORM\Column(type: 'string', length: 40, options: ['default' => ''])]
    private string $phone = '';

    #[ORM\Column(type: 'string', length: 120, options: ['default' => ''])]
    private string $openingHours = '';

    /**
     * Prix de l'envoi par cette agence (FCFA), fixé quand l'agence est enregistrée (décision du 2026-11-16).
     * Vide : prix de la ville de livraison.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $fee = null;

    /** Boutique de départ desservie (Yaoundé ou Douala) ; vide : les deux boutiques. */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $departureCity = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $id, DeliveryCity $city, string $name)
    {
        $this->id = $id;
        $this->city = $city;
        $this->name = $name;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }

    public function getCity(): DeliveryCity { return $this->city; }
    public function setCity(DeliveryCity $v): static { $this->city = $v; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getAddress(): string { return $this->address; }
    public function setAddress(string $v): static { $this->address = $v; return $this; }

    public function getPhone(): string { return $this->phone; }
    public function setPhone(string $v): static { $this->phone = $v; return $this; }

    public function getOpeningHours(): string { return $this->openingHours; }
    public function setOpeningHours(string $v): static { $this->openingHours = $v; return $this; }

    public function getFee(): ?int { return $this->fee; }
    public function setFee(?int $v): static { $this->fee = $v !== null ? max(0, $v) : null; return $this; }
    /** Prix appliqué : celui de l'agence, sinon celui de sa ville. */
    public function effectiveFee(): int { return $this->fee ?? $this->city->getFee(); }

    public function getDepartureCity(): ?string { return $this->departureCity; }
    public function setDepartureCity(?string $v): static { $this->departureCity = $v; return $this; }
    /** Envoi possible depuis la boutique de cette ville. */
    public function servesFrom(string $shopCity): bool { return $this->departureCity === null || $this->departureCity === $shopCity; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
