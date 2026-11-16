<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne d'une réservation : copie de l'article au moment de la réservation (nom, couleur, taille, prix, photo,
 * ville, vendeur). Les identifiants d'article et de variante ne sont pas des clés étrangères : la ligne reste
 * lisible si l'article est retiré ou la variante supprimée ensuite.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_items')]
#[ORM\Index(name: 'idx_reservation_item_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_reservation_item_seller', columns: ['seller_id'])]
class ReservationItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Reservation $reservation;

    #[ORM\Column(type: 'smallint')]
    private int $position;

    #[ORM\Column(type: 'string', length: 40)]
    private string $productId;

    #[ORM\Column(type: 'string', length: 160)]
    private string $productSlug;

    #[ORM\Column(type: 'string', length: 160)]
    private string $productName;

    #[ORM\Column(type: 'string', length: 500, options: ['default' => ''])]
    private string $image = '';

    #[ORM\Column(type: 'string', length: 80)]
    private string $variantId;

    #[ORM\Column(type: 'string', length: 20)]
    private string $size;

    #[ORM\Column(type: 'string', length: 40)]
    private string $colorCode;

    #[ORM\Column(type: 'string', length: 60, options: ['default' => ''])]
    private string $colorName = '';

    #[ORM\Column(type: 'string', length: 7, options: ['default' => ''])]
    private string $colorHex = '';

    #[ORM\Column(type: 'integer')]
    private int $unitPrice;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(type: 'string', length: 20)]
    private string $city;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sellerId = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $sellerName = null;

    public function __construct(Reservation $reservation, int $position, ProductVariant $variant, int $quantity)
    {
        $product = $variant->getProduct();
        $this->reservation = $reservation;
        $this->position = $position;
        $this->productId = $product->getId();
        $this->productSlug = $product->getSlug();
        $this->productName = $product->getName();
        $this->variantId = $variant->getId();
        $this->size = $variant->getSize();
        $this->colorCode = $variant->getColorCode();
        foreach ($product->getColors() as $color) {
            if (($color['code'] ?? null) === $variant->getColorCode()) {
                $this->colorName = (string) ($color['name'] ?? '');
                $this->colorHex = (string) ($color['hex'] ?? '');
                break;
            }
        }
        // Photo de la couleur choisie, sinon la photo de couverture.
        $gallery = $product->getMediaGallery();
        foreach ($gallery as $media) {
            if (($media['colorCode'] ?? null) === $variant->getColorCode() && \is_string($media['url'] ?? null)) {
                $this->image = $media['url'];
                break;
            }
        }
        if ($this->image === '' && \is_string($gallery[0]['url'] ?? null)) {
            $this->image = $gallery[0]['url'];
        }
        $this->image = mb_substr($this->image, 0, 500);
        $this->unitPrice = $product->getPrice();
        $this->quantity = $quantity;
        $this->city = $product->getCity();
        $this->sellerId = $product->getSeller()?->getId();
        $this->sellerName = $product->getSeller()?->getName();
    }

    public function getId(): ?int { return $this->id; }
    public function getReservation(): Reservation { return $this->reservation; }
    public function getPosition(): int { return $this->position; }
    public function getProductId(): string { return $this->productId; }
    public function getProductSlug(): string { return $this->productSlug; }
    public function getProductName(): string { return $this->productName; }
    public function getImage(): string { return $this->image; }
    public function getVariantId(): string { return $this->variantId; }
    public function getSize(): string { return $this->size; }
    public function getColorCode(): string { return $this->colorCode; }
    public function getColorName(): string { return $this->colorName; }
    public function getColorHex(): string { return $this->colorHex; }
    public function getUnitPrice(): int { return $this->unitPrice; }
    public function getQuantity(): int { return $this->quantity; }
    public function getLineTotal(): int { return $this->unitPrice * $this->quantity; }
    public function getCity(): string { return $this->city; }
    public function getSellerId(): ?int { return $this->sellerId; }
    public function getSellerName(): ?string { return $this->sellerName; }
}
