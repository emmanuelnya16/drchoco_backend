<?php

namespace App\Repository;

use App\Entity\CoinMovement;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CoinMovement> */
final class CoinMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoinMovement::class);
    }

    /** @return list<CoinMovement> les plus récents d'abord */
    public function latestFor(User $user, int $limit = 20): array
    {
        /** @var list<CoinMovement> $movements */
        $movements = $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $movements;
    }
}
