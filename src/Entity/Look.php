<?php

namespace App\Entity;

use App\Repository\LookRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Look de l'accueil (« Total black barbelé ») : une photo et les articles qui le composent,
 * désignés par leur slug, dans l'ordre d'affichage.
 */
#[ORM\Entity(repositoryClass: LookRepository::class)]
#[ORM\Table(name: 'looks')]
#[ORM\HasLifecycleCallbacks]
class Look
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 40)]
    private string $id;

    #[ORM\Column(type: 'string', length: 120)]
    private string $title;

    #[ORM\Column(type: 'string', length: 200, options: ['default' => ''])]
    private string $subtitle = '';

    #[ORM\Column(type: 'string', length: 500, options: ['default' => ''])]
    private string $image = '';

    #[ORM\Column(type: 'string', length: 500, options: ['default' => ''])]
    private string $fallback = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $productSlugs = [];

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $id, string $title)
    {
        $this->id = $id;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): static { $this->title = $v; return $this; }

    public function getSubtitle(): string { return $this->subtitle; }
    public function setSubtitle(string $v): static { $this->subtitle = $v; return $this; }

    public function getImage(): string { return $this->image; }
    public function setImage(string $v): static { $this->image = $v; return $this; }

    public function getFallback(): string { return $this->fallback; }
    public function setFallback(string $v): static { $this->fallback = $v; return $this; }

    /** @return list<string> */
    public function getProductSlugs(): array { return $this->productSlugs; }
    /** @param list<string> $v */
    public function setProductSlugs(array $v): static { $this->productSlugs = array_values(array_unique($v)); return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
