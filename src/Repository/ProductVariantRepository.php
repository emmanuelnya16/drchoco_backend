<?php

namespace App\Repository;

use App\Entity\ProductVariant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductVariant>
 */
class ProductVariantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductVariant::class);
    }

    /**
     * Variantes avec leur article et sa catégorie, indexées par identifiant.
     *
     * @param list<string> $ids
     *
     * @return array<string, ProductVariant>
     */
    public function findWithProducts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $variants = $this->createQueryBuilder('v')
            ->addSelect('p', 'c')
            ->innerJoin('v.product', 'p')
            ->innerJoin('p.category', 'c')
            ->where('v.id IN (:ids)')
            // Toujours du texte : un identifiant « 1 » passé en nombre ferait comparer toute la colonne à un nombre.
            ->setParameter('ids', array_values(array_unique(array_map('strval', $ids))), ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($variants as $variant) {
            $byId[$variant->getId()] = $variant;
        }

        return $byId;
    }
}
