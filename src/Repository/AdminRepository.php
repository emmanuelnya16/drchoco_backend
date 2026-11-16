<?php

namespace App\Repository;

use App\Entity\Admin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Admin>
 */
class AdminRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Admin::class);
    }

    public function findByEmail(string $email): ?Admin
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public function findByPhone(string $phone): ?Admin
    {
        return $this->findOneBy(['phone' => $phone]);
    }

    public function findOwner(): ?Admin
    {
        return $this->findOneBy(['role' => Admin::ROLE_OWNER]);
    }

    public function save(Admin $admin, bool $flush = false): void
    {
        $this->getEntityManager()->persist($admin);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<Admin> Vendeurs, dans l'ordre de création. */
    public function findSellers(): array
    {
        return $this->findBy(['role' => Admin::ROLE_SELLER], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    /** Vendeur désigné par son identifiant (un super admin n'est jamais renvoyé ici). */
    public function findSeller(string $id): ?Admin
    {
        if (!ctype_digit($id) || \strlen($id) > 10) {
            return null;
        }

        return $this->findOneBy(['id' => (int) $id, 'role' => Admin::ROLE_SELLER]);
    }

    /** Un autre compte utilise-t-il déjà cet e-mail ou ce téléphone ? */
    public function isTaken(?string $email, ?string $phone, ?Admin $except = null): bool
    {
        $qb = $this->createQueryBuilder('a')->select('COUNT(a.id)');
        $or = [];
        if ($email !== null) {
            $or[] = 'a.email = :email';
            $qb->setParameter('email', mb_strtolower($email));
        }
        if ($phone !== null) {
            $or[] = 'a.phone = :phone';
            $qb->setParameter('phone', $phone);
        }
        if ($or === []) {
            return false;
        }
        $qb->where(implode(' OR ', $or));
        if ($except !== null) {
            $qb->andWhere('a.id <> :except')->setParameter('except', $except->getId());
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
