<?php

namespace App\Repository;

use App\Entity\Look;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Look>
 */
class LookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Look::class);
    }

    /** @return list<Look> */
    public function findVisible(): array
    {
        return $this->findBy(['isActive' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function findVisibleById(string $id): ?Look
    {
        return $this->findOneBy(['id' => $id, 'isActive' => true]);
    }
}
