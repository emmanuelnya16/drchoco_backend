<?php

namespace App\Repository;

use App\Entity\Favorite;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Favorite>
 */
class FavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Favorite::class);
    }

    /**
     * Identifiants des articles favoris du client encore visibles (article en vente, catégorie active),
     * dans l'ordre où ils ont été ajoutés.
     *
     * @return list<string>
     */
    public function visibleProductIdsFor(User $user): array
    {
        return array_map('strval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT f.product_id FROM favorites f
             INNER JOIN products p ON p.id = f.product_id
             INNER JOIN categories c ON c.id = p.category_id
             WHERE f.user_id = :user AND p.is_active = 1 AND c.is_active = 1
             ORDER BY f.created_at ASC, f.id ASC',
            ['user' => $user->getId()],
        ));
    }

    public function countFor(User $user): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM favorites WHERE user_id = :user',
            ['user' => $user->getId()],
        );
    }

    /**
     * Ajoute des favoris sans doublon. « INSERT IGNORE » : deux fusions simultanées du même compte
     * (deux onglets) ne provoquent jamais d'erreur sur la contrainte d'unicité.
     *
     * @param list<string> $productIds identifiants d'articles existants
     */
    public function addMany(User $user, array $productIds, \DateTimeImmutable $now): void
    {
        $connection = $this->getEntityManager()->getConnection();
        foreach (array_values(array_unique($productIds)) as $productId) {
            $connection->executeStatement(
                'INSERT IGNORE INTO favorites (user_id, product_id, created_at) VALUES (:user, :product, :createdAt)',
                ['user' => $user->getId(), 'product' => $productId, 'createdAt' => $now->format('Y-m-d H:i:s')],
            );
        }
    }

    public function remove(User $user, string $productId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM favorites WHERE user_id = :user AND product_id = :product',
            ['user' => $user->getId(), 'product' => $productId],
        );
    }

    /** @param list<string> $productIds */
    public function existingProductIds(User $user, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return array_map('strval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT product_id FROM favorites WHERE user_id = :user AND product_id IN (:ids)',
            ['user' => $user->getId(), 'ids' => $productIds],
            ['ids' => ArrayParameterType::STRING],
        ));
    }
}
