<?php

namespace App\Entity;

use App\Repository\FavoriteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Article favori d'un client (contrat §5.7). Les favoris vivent sur le téléphone ; le serveur
 * reçoit ceux du téléphone à la connexion et renvoie la liste fusionnée.
 */
#[ORM\Entity(repositoryClass: FavoriteRepository::class)]
#[ORM\Table(name: 'favorites')]
#[ORM\UniqueConstraint(name: 'uniq_favorite_user_product', columns: ['user_id', 'product_id'])]
class Favorite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, Product $product, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->product = $product;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getProduct(): Product { return $this->product; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
