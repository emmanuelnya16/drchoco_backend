<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByPhone(string $phone): ?User
    {
        return $this->findOneBy(['phone' => $phone]);
    }

    public function save(User $user, bool $flush = false): void
    {
        $this->getEntityManager()->persist($user);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Répertoire des clients pour l'administration : recherche sur le nom, la ville ou le téléphone
     * (chiffres), les plus récemment actifs d'abord.
     *
     * @return array{items: list<User>, total: int}
     */
    public function adminList(string $query, int $limit): array
    {
        $qb = $this->createQueryBuilder('u');
        $query = trim($query);
        if ($query !== '') {
            $conditions = ["CONCAT(COALESCE(u.firstName, ''), ' ', COALESCE(u.lastName, '')) LIKE :q", 'u.city LIKE :q', 'u.phone LIKE :q'];
            $qb->setParameter('q', '%' . addcslashes($query, '%_\\') . '%');
            $digits = (string) preg_replace('/\D/', '', $query);
            if (\strlen($digits) >= 2) {
                $conditions[] = 'u.phone LIKE :digits';
                $qb->setParameter('digits', '%' . $digits . '%');
            }
            $qb->where(implode(' OR ', $conditions));
        }

        $total = (int) (clone $qb)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->addSelect('COALESCE(u.updatedAt, u.createdAt) AS HIDDEN lastActivity')
            ->orderBy('lastActivity', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
