<?php

namespace App\Entity;

use App\Repository\DeliveryCityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ville desservie : livraison à domicile (ville locale) ou envoi par agence, avec ses frais.
 */
#[ORM\Entity(repositoryClass: DeliveryCityRepository::class)]
#[ORM\Table(name: 'delivery_cities')]
#[ORM\HasLifecycleCallbacks]
class DeliveryCity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 40)]
    private string $id;

    // Nom affiché et renvoyé par l'app (« Yaoundé ») : unique, comparé sans accents ni casse.
    #[ORM\Column(type: 'string', length: 80, unique: true)]
    private string $name;

    // Frais de livraison en FCFA.
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $fee = 0;

    // Livraison à domicile (quartier et point de repère demandés).
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isLocal = false;

    // Envoi par agence de voyage possible.
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $hasAgencies = true;

    #[ORM\Column(type: 'string', length: 80, options: ['default' => ''])]
    private string $estimatedDelay = '';

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getFee(): int { return $this->fee; }
    public function setFee(int $v): static { $this->fee = max(0, $v); return $this; }

    public function isLocal(): bool { return $this->isLocal; }
    public function setIsLocal(bool $v): static { $this->isLocal = $v; return $this; }

    public function hasAgencies(): bool { return $this->hasAgencies; }
    public function setHasAgencies(bool $v): static { $this->hasAgencies = $v; return $this; }

    public function getEstimatedDelay(): string { return $this->estimatedDelay; }
    public function setEstimatedDelay(string $v): static { $this->estimatedDelay = $v; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
