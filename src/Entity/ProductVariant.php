<?php

namespace App\Entity;

use App\Repository\ProductVariantRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Variante d'un article : une couleur × une taille, avec son stock.
 * Son identifiant (« prod-01-noir-l ») est celui que le panier et la réservation envoient.
 */
#[ORM\Entity(repositoryClass: ProductVariantRepository::class)]
#[ORM\Table(name: 'product_variants')]
#[ORM\UniqueConstraint(name: 'uniq_variant_color_size', columns: ['product_id', 'color_code', 'size'])]
#[ORM\Index(columns: ['size', 'stock'], name: 'idx_variant_size_stock')]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 80)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(type: 'string', length: 20)]
    private string $size;

    #[ORM\Column(type: 'string', length: 40)]
    private string $colorCode;

    // Quantité encore réservable (jamais négative).
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $stock = 0;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $position = 0;

    public function __construct(string $id, Product $product, string $colorCode, string $size, int $stock = 0)
    {
        $this->id = $id;
        $this->product = $product;
        $this->colorCode = $colorCode;
        $this->size = $size;
        $this->stock = max(0, $stock);
        $product->addVariant($this);
    }

    public function getId(): string { return $this->id; }
    public function getProduct(): Product { return $this->product; }

    public function getSize(): string { return $this->size; }
    public function setSize(string $v): static { $this->size = $v; return $this; }

    public function getColorCode(): string { return $this->colorCode; }
    public function setColorCode(string $v): static { $this->colorCode = $v; return $this; }

    public function getStock(): int { return $this->stock; }
    public function setStock(int $v): static { $this->stock = max(0, $v); return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }
}
