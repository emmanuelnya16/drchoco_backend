<?php

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Catégorie racine du catalogue (Vêtements, Chaussures, Babouches…), avec ses sous-catégories.
 * Créée et modifiée par le super admin (P4). Identifiant opaque lisible : « cat-vetements ».
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'categories')]
#[ORM\HasLifecycleCallbacks]
class Category
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 40)]
    private string $id;

    #[ORM\Column(type: 'string', length: 80, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 80)]
    private string $name;

    #[ORM\Column(type: 'string', length: 500, options: ['default' => ''])]
    private string $description = '';

    // Visuel de la carte de catégorie, visuel large (accueil) et visuel de repli livré avec l'app.
    #[ORM\Column(type: 'string', length: 500, options: ['default' => ''])]
    private string $image = '';

    #[ORM\Column(type: 'string', length: 500, options: ['default' => ''])]
    private string $coverImage = '';

    #[ORM\Column(type: 'string', length: 500, options: ['default' => ''])]
    private string $fallbackImage = '';

    // Ordre d'affichage (croissant).
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $position = 0;

    // Catégorie masquée : ni elle ni ses articles ne sont visibles du client.
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    /** @var Collection<int, SubCategory> */
    #[ORM\OneToMany(targetEntity: SubCategory::class, mappedBy: 'category', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $subCategories;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $id, string $slug, string $name)
    {
        $this->id = $id;
        $this->slug = $slug;
        $this->name = $name;
        $this->subCategories = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $v): static { $this->slug = $v; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $v): static { $this->description = $v; return $this; }

    public function getImage(): string { return $this->image; }
    public function setImage(string $v): static { $this->image = $v; return $this; }

    public function getCoverImage(): string { return $this->coverImage; }
    public function setCoverImage(string $v): static { $this->coverImage = $v; return $this; }

    public function getFallbackImage(): string { return $this->fallbackImage; }
    public function setFallbackImage(string $v): static { $this->fallbackImage = $v; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    /** @return Collection<int, SubCategory> */
    public function getSubCategories(): Collection { return $this->subCategories; }

    public function addSubCategory(SubCategory $sub): static
    {
        if (!$this->subCategories->contains($sub)) {
            $this->subCategories->add($sub);
        }

        return $this;
    }

    public function removeSubCategory(SubCategory $sub): static
    {
        $this->subCategories->removeElement($sub);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
