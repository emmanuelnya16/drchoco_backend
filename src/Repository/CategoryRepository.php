<?php

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Catégories actives avec leurs sous-catégories, dans l'ordre d'affichage.
     *
     * @return list<Category>
     */
    public function findVisible(): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('s')
            ->leftJoin('c.subCategories', 's')
            ->where('c.isActive = true')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->addOrderBy('s.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
