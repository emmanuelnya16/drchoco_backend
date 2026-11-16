<?php

namespace App\Repository;

use App\Entity\Address;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Address>
 */
class AddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Address::class);
    }

    /**
     * Carnet d'adresses du client : adresse par défaut en tête, puis dans l'ordre d'ajout.
     *
     * @return list<Address>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.isDefault', 'DESC')
            ->addOrderBy('a.sequence', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Adresse de CE client (celle d'un autre client est « introuvable », jamais « interdite »). */
    public function findOneForUser(User $user, string $id): ?Address
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * Adresses de plusieurs clients en une requête, regroupées par client (adresse par défaut en tête).
     *
     * @param list<User> $users
     *
     * @return array<int, list<Address>>
     */
    public function findForUsers(array $users): array
    {
        if ($users === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('a')
            ->where('a.user IN (:users)')
            ->setParameter('users', $users)
            ->orderBy('a.isDefault', 'DESC')
            ->addOrderBy('a.sequence', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()->getResult();

        $byUser = [];
        foreach ($rows as $address) {
            $byUser[(int) $address->getUser()->getId()][] = $address;
        }

        return $byUser;
    }
}
