<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Article du catalogue, rattaché à une ville (Yaoundé ou Douala) et au vendeur qui l'a créé.
 *
 * Le stock est porté par les variantes (couleur × taille). Les photos, couleurs, tailles, conseils
 * d'entretien et guide des tailles sont gardés tels que le front les affiche (colonnes JSON).
 * Badges (« Nouveau », « Plus que N », « Épuisé ») : calculés, jamais stockés.
 */
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
#[ORM\Index(columns: ['is_active', 'city'], name: 'idx_product_active_city')]
#[ORM\Index(columns: ['price'], name: 'idx_product_price')]
#[ORM\Index(columns: ['created_at'], name: 'idx_product_created')]
#[ORM\Index(columns: ['reservations_count'], name: 'idx_product_popular')]
#[ORM\HasLifecycleCallbacks]
class Product
{
    // Seuil du badge « Plus que N » (stock total de 1 à 3).
    public const LOW_STOCK_MAX = 3;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 40)]
    private string $id;

    #[ORM\Column(type: 'string', length: 160, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $description = '';

    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $composition = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $careInstructions = [];

    /** @var array{type: string, title: string, tip: string, headers: list<string>, rows: list<list<string>>} */
    #[ORM\Column(type: 'json')]
    private array $sizeGuide = ['type' => 'one-size', 'title' => '', 'tip' => '', 'headers' => [], 'rows' => []];

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Category $category;

    #[ORM\ManyToOne(targetEntity: SubCategory::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SubCategory $subCategory = null;

    // Prix entier en FCFA.
    #[ORM\Column(type: 'integer')]
    private int $price = 0;

    /**
     * Galerie : { url, fallbackUrl, colorCode?, alt? } ; la première photo est la photo principale.
     *
     * @var list<array{url: string, fallbackUrl: string, colorCode?: string, alt?: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $mediaGallery = [];

    /** @var list<array{code: string, name: string, hex: string}> */
    #[ORM\Column(type: 'json')]
    private array $colors = [];

    /** @var list<string> tailles proposées, dans l'ordre d'affichage */
    #[ORM\Column(type: 'json')]
    private array $sizes = [];

    /** @var Collection<int, ProductVariant> */
    #[ORM\OneToMany(targetEntity: ProductVariant::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $variants;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isNew = false;

    // Popularité (tri « popular ») : nombre de réservations de l'article.
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $reservationsCount = 0;

    // Ville du stock et du vendeur : Yaoundé ou Douala (voir App\Catalog\ShopCity).
    #[ORM\Column(type: 'string', length: 60)]
    private string $city;

    // Vendeur qui a créé l'article (vide pour les articles de démonstration ou d'un compte supprimé).
    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Admin $seller = null;

    // Article retiré de la vente : invisible du client (une fiche retirée répond 404).
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $id, string $slug, string $name, Category $category, string $city)
    {
        $this->id = $id;
        $this->slug = $slug;
        $this->name = $name;
        $this->category = $category;
        $this->city = $city;
        $this->variants = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ── Helpers métier ────────────────────────────────────────────────────

    public function getTotalStock(): int
    {
        $total = 0;
        foreach ($this->variants as $variant) {
            $total += max(0, $variant->getStock());
        }

        return $total;
    }

    /** @return list<string> « sold-out » (stock 0), « low-stock » (1 à 3), « new » */
    public function getBadges(): array
    {
        $badges = [];
        $stock = $this->getTotalStock();
        if ($stock === 0) {
            $badges[] = 'sold-out';
        } elseif ($stock <= self::LOW_STOCK_MAX) {
            $badges[] = 'low-stock';
        }
        if ($this->isNew) {
            $badges[] = 'new';
        }

        return $badges;
    }

    /** Visible du client : article en vente dans une catégorie active. */
    public function isVisible(): bool
    {
        return $this->isActive && $this->category->isActive();
    }

    // ── Getters / Setters ─────────────────────────────────────────────────

    public function getId(): string { return $this->id; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $v): static { $this->slug = $v; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $v): static { $this->description = $v; return $this; }

    public function getComposition(): string { return $this->composition; }
    public function setComposition(string $v): static { $this->composition = $v; return $this; }

    /** @return list<string> */
    public function getCareInstructions(): array { return $this->careInstructions; }
    /** @param list<string> $v */
    public function setCareInstructions(array $v): static { $this->careInstructions = array_values($v); return $this; }

    /** @return array<string, mixed> */
    public function getSizeGuide(): array { return $this->sizeGuide; }
    /** @param array<string, mixed> $v */
    public function setSizeGuide(array $v): static { $this->sizeGuide = $v; return $this; }

    public function getCategory(): Category { return $this->category; }
    public function setCategory(Category $v): static { $this->category = $v; return $this; }

    public function getSubCategory(): ?SubCategory { return $this->subCategory; }
    public function setSubCategory(?SubCategory $v): static { $this->subCategory = $v; return $this; }

    public function getPrice(): int { return $this->price; }
    public function setPrice(int $v): static { $this->price = $v; return $this; }

    /** @return list<array<string, string>> */
    public function getMediaGallery(): array { return $this->mediaGallery; }
    /** @param list<array<string, string>> $v */
    public function setMediaGallery(array $v): static { $this->mediaGallery = array_values($v); return $this; }

    /** @return list<array{code: string, name: string, hex: string}> */
    public function getColors(): array { return $this->colors; }
    /** @param list<array{code: string, name: string, hex: string}> $v */
    public function setColors(array $v): static { $this->colors = array_values($v); return $this; }

    /** @return list<string> */
    public function getSizes(): array { return $this->sizes; }
    /** @param list<string> $v */
    public function setSizes(array $v): static { $this->sizes = array_values($v); return $this; }

    /** @return Collection<int, ProductVariant> */
    public function getVariants(): Collection { return $this->variants; }

    public function addVariant(ProductVariant $variant): static
    {
        if (!$this->variants->contains($variant)) {
            $this->variants->add($variant);
        }

        return $this;
    }

    public function removeVariant(ProductVariant $variant): static
    {
        $this->variants->removeElement($variant);

        return $this;
    }

    public function isNew(): bool { return $this->isNew; }
    public function setIsNew(bool $v): static { $this->isNew = $v; return $this; }

    public function getReservationsCount(): int { return $this->reservationsCount; }
    public function setReservationsCount(int $v): static { $this->reservationsCount = $v; return $this; }

    public function getCity(): string { return $this->city; }
    public function setCity(string $v): static { $this->city = $v; return $this; }

    public function getSeller(): ?Admin { return $this->seller; }
    public function setSeller(?Admin $v): static { $this->seller = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $v): static { $this->createdAt = $v; return $this; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
