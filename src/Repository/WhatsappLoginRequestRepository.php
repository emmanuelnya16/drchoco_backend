<?php

namespace App\Repository;

use App\Entity\WhatsappLoginRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WhatsappLoginRequest>
 */
class WhatsappLoginRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhatsappLoginRequest::class);
    }

    /** Demande désignée par l'empreinte du jeton de l'app ; avec $forUpdate, verrouillée jusqu'à la fin de la transaction. */
    public function findByTokenHash(string $hash, bool $forUpdate = false): ?WhatsappLoginRequest
    {
        $query = $this->createQueryBuilder('w')
            ->where('w.requestTokenHash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true);
        if ($forUpdate) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        return $query->getOneOrNullResult();
    }

    /**
     * Demandes en attente qui portent ce code, verrouillées (deux messages traités en même temps passent l'un après
     * l'autre). En principe une seule ; deux en cas de tirage identique, départagées par le numéro de l'expéditeur.
     *
     * @return list<WhatsappLoginRequest>
     */
    public function findPendingByCode(string $code, \DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.code = :code')
            ->andWhere('w.status = :pending')
            ->andWhere('w.expiresAt > :now')
            ->setParameter('code', $code)
            ->setParameter('pending', WhatsappLoginRequest::STATUS_PENDING)
            ->setParameter('now', $now)
            ->orderBy('w.id', 'DESC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    public function isCodeInUse(string $code, \DateTimeImmutable $now): bool
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->where('w.code = :code')
            ->andWhere('w.status IN (:open)')
            ->andWhere('w.expiresAt > :now')
            ->setParameter('code', $code)
            ->setParameter('open', [WhatsappLoginRequest::STATUS_PENDING, WhatsappLoginRequest::STATUS_CONFIRMED])
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** Message WhatsApp déjà utilisé pour confirmer une demande (lecture verrouillante : à jour même en transaction). */
    public function isMessageKnown(string $waMessageId): bool
    {
        return $this->createQueryBuilder('w')
            ->where('w.waMessageId = :id')
            ->setParameter('id', $waMessageId)
            ->setMaxResults(1)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult() !== null;
    }

    /** Une nouvelle demande remplace les précédentes encore en attente pour ce numéro. */
    public function cancelPendingForPhone(string $phone): int
    {
        return $this->createQueryBuilder('w')
            ->update()
            ->set('w.status', ':cancelled')
            ->where('w.phone = :phone')
            ->andWhere('w.status = :pending')
            ->setParameter('cancelled', WhatsappLoginRequest::STATUS_CANCELLED)
            ->setParameter('pending', WhatsappLoginRequest::STATUS_PENDING)
            ->setParameter('phone', $phone)
            ->getQuery()
            ->execute();
    }

    /**
     * Nombre de demandes de ce numéro depuis $since, et date de la plus ancienne.
     *
     * @return array{count: int, oldest: ?\DateTimeImmutable}
     */
    public function statsForPhoneSince(string $phone, \DateTimeImmutable $since): array
    {
        $row = $this->createQueryBuilder('w')
            ->select('COUNT(w.id) AS n, MIN(w.createdAt) AS oldest')
            ->where('w.phone = :phone')
            ->andWhere('w.createdAt > :since')
            ->setParameter('phone', $phone)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleResult();

        $oldest = $row['oldest'] ?? null;

        return [
            'count'  => (int) $row['n'],
            'oldest' => \is_string($oldest) ? new \DateTimeImmutable($oldest, new \DateTimeZone('UTC')) : ($oldest instanceof \DateTimeImmutable ? $oldest : null),
        ];
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('w')
            ->delete()
            ->where('w.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
