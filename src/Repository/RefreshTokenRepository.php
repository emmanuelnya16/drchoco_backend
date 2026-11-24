<?php

namespace App\Repository;

use App\Entity\RefreshToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 */
class RefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    /**
     * Cherche un jeton par son hash (même déjà utilisé : nécessaire pour le délai de grâce
     * et pour détecter une réutilisation), en le verrouillant pour la durée de la transaction.
     */
    public function findByHashForUpdate(string $hash): ?RefreshToken
    {
        return $this->createQueryBuilder('rt')
            ->where('rt.tokenHash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            // Relit l'état réel en base (une révocation de famille passe par une requête UPDATE directe).
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    public function findByHash(string $hash): ?RefreshToken
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    /** Révoque toute une famille de jetons (une connexion sur un appareil). */
    public function revokeFamily(string $familyId): int
    {
        return $this->createQueryBuilder('rt')
            ->update()
            ->set('rt.isRevoked', ':revoked')
            ->where('rt.familyId = :family')
            ->andWhere('rt.isRevoked = :notRevoked')
            ->setParameter('revoked', true)
            ->setParameter('notRevoked', false)
            ->setParameter('family', $familyId)
            ->getQuery()
            ->execute();
    }

    /** Révoque toutes les sessions d'un compte (ex. vendeur désactivé, client bloqué). */
    public function revokeAllForUser(int $userId, string $userType): int
    {
        return $this->createQueryBuilder('rt')
            ->update()
            ->set('rt.isRevoked', ':revoked')
            ->where('rt.userId = :userId')
            ->andWhere('rt.userType = :userType')
            ->andWhere('rt.isRevoked = :notRevoked')
            ->setParameter('revoked', true)
            ->setParameter('notRevoked', false)
            ->setParameter('userId', $userId)
            ->setParameter('userType', $userType)
            ->getQuery()
            ->execute();
    }

    /**
     * Supprime les jetons expirés, et ceux des sessions fermées depuis $doneBefore (appelé par la tâche planifiée de purge).
     * Les jetons déjà utilisés d'une session ouverte restent jusqu'à leur expiration : présentés à nouveau, ils révèlent
     * un cookie volé et ferment la session.
     */
    public function deleteExpired(\DateTimeImmutable $now, \DateTimeImmutable $doneBefore): int
    {
        return $this->createQueryBuilder('rt')
            ->delete()
            ->where('rt.expiresAt < :now')
            ->orWhere('rt.isRevoked = true AND rt.createdAt < :done')
            ->setParameter('now', $now)
            ->setParameter('done', $doneBefore)
            ->getQuery()
            ->execute();
    }

    public function save(RefreshToken $token, bool $flush = false): void
    {
        $this->getEntityManager()->persist($token);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
