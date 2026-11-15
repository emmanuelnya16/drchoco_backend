<?php

namespace App\Repository;

use App\Entity\Agency;
use App\Entity\DeliveryCity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Agency>
 */
class AgencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agency::class);
    }

    /**
     * Agences actives, d'une ville ou de toutes les villes actives.
     *
     * @return list<Agency>
     */
    public function findVisible(?DeliveryCity $city = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->addSelect('d')
            ->innerJoin('a.city', 'd')
            ->where('a.isActive = true')
            ->andWhere('d.isActive = true')
            ->orderBy('d.position', 'ASC')
            ->addOrderBy('a.position', 'ASC')
            ->addOrderBy('a.name', 'ASC');

        if ($city !== null) {
            $qb->andWhere('d.id = :city')->setParameter('city', $city->getId());
        }

        return $qb->getQuery()->getResult();
    }
}
