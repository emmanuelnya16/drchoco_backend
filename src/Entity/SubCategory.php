<?php

namespace App\Entity;

use App\Repository\SubCategoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sous-catégorie (T-shirts, Hoodies, Sneakers…). Son slug est unique dans tout le catalogue :
 * le front filtre par « sub » sans toujours préciser la catégorie.
 */
#[ORM\Entity(repositoryClass: SubCategoryRepository::class)]
#[ORM\Table(name: 'sub_categories')]
class SubCategory
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 40)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'subCategories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Category $category;

    #[ORM\Column(type: 'string', length: 80, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 80)]
    private string $name;

    #[ORM\Column(type: 'string', length: 500, options: ['default' => ''])]
    private string $coverImage = '';

    #[ORM\Column(type: 'string', length: 500, options: ['default' => ''])]
    private string $fallbackImage = '';

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $position = 0;

    public function __construct(string $id, Category $category, string $slug, string $name)
    {
        $this->id = $id;
        $this->category = $category;
        $this->slug = $slug;
        $this->name = $name;
        $category->addSubCategory($this);
    }

    public function getId(): string { return $this->id; }

    public function getCategory(): Category { return $this->category; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $v): static { $this->slug = $v; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getCoverImage(): string { return $this->coverImage; }
    public function setCoverImage(string $v): static { $this->coverImage = $v; return $this; }

    public function getFallbackImage(): string { return $this->fallbackImage; }
    public function setFallbackImage(string $v): static { $this->fallbackImage = $v; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }
}
