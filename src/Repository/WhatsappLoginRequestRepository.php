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

    /**
     * Demande qui porte cette référence, verrouillée jusqu'à la fin de la transaction : deux messages traités en même
     * temps passent l'un après l'autre (un seul code envoyé).
     */
    public function findByReferenceForUpdate(string $reference): ?WhatsappLoginRequest
    {
        return $this->createQueryBuilder('w')
            ->where('w.reference = :reference')
            ->setParameter('reference', $reference)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    public function isReferenceTaken(string $reference): bool
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->where('w.reference = :reference')
            ->setParameter('reference', $reference)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** Message WhatsApp qui a déjà obtenu un code (lecture verrouillante : à jour même en transaction). */
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
