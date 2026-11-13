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
}
