<?php

namespace App\Repository;

use App\Entity\OtpCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OtpCode>
 */
class OtpCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OtpCode::class);
    }

    /**
     * Dernier code envoyé à ce numéro (le seul qui compte).
     * Avec $forUpdate, la ligne est verrouillée jusqu'à la fin de la transaction : deux vérifications
     * simultanées du même numéro passent l'une après l'autre (pas d'essai gratuit, pas de double compte).
     */
    public function findLatestForPhone(string $phone, bool $forUpdate = false): ?OtpCode
    {
        $query = $this->createQueryBuilder('o')
            ->where('o.phone = :phone')
            ->setParameter('phone', $phone)
            ->orderBy('o.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery();

        if ($forUpdate) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        return $query->getOneOrNullResult();
    }

    /**
     * Nombre de codes envoyés à ce numéro depuis $since, et date du plus ancien d'entre eux.
     *
     * @return array{count: int, oldest: ?\DateTimeImmutable}
     */
    public function statsForPhoneSince(string $phone, \DateTimeImmutable $since): array
    {
        $row = $this->createQueryBuilder('o')
            ->select('COUNT(o.id) AS n, MIN(o.createdAt) AS oldest')
            ->where('o.phone = :phone')
            ->andWhere('o.createdAt > :since')
            ->setParameter('phone', $phone)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleResult();

        return [
            'count'  => (int) $row['n'],
            'oldest' => $row['oldest'] !== null ? new \DateTimeImmutable((string) $row['oldest'], new \DateTimeZone('UTC')) : null,
        ];
    }

    /** Nombre total de codes envoyés depuis $since (plafond quotidien de SMS, tous numéros confondus). */
    public function countAllSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.createdAt > :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Efface les codes plus anciens que $before (commande app:purge). */
    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('o')
            ->delete()
            ->where('o.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
