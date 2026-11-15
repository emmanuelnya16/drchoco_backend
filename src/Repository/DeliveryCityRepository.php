<?php

namespace App\Repository;

use App\Entity\DeliveryCity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeliveryCity>
 */
class DeliveryCityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliveryCity::class);
    }

    /** @return list<DeliveryCity> */
    public function findVisible(): array
    {
        return $this->findBy(['isActive' => true], ['position' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * Ville active désignée par son identifiant (« city-yde ») ou son nom, sans tenir compte
     * des accents ni de la casse (« yaounde » = « Yaoundé », collation utf8mb4_unicode_ci).
     */
    public function findVisibleByIdOrName(string $value): ?DeliveryCity
    {
        return $this->createQueryBuilder('d')
            ->where('d.isActive = true')
            ->andWhere('d.id = :value OR d.name = :value')
            ->setParameter('value', $value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
