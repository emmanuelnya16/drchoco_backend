<?php

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public const PAGE_SIZE = 10;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /** Réservation du client (référence sans tenir compte de la casse) ; celle d'un autre client : null. */
    public function findOwned(User $user, string $ref): ?Reservation
    {
        $ref = strtoupper(trim($ref));
        if (preg_match('/^RES-\d{1,12}\z/', $ref) !== 1) {
            return null;
        }

        return $this->findOneBy(['user' => $user, 'ref' => $ref]);
    }

    public function findByIdempotencyKey(User $user, string $key): ?Reservation
    {
        return $this->findOneBy(['user' => $user, 'idempotencyKey' => $key]);
    }

    /**
     * Identifiants des réservations arrivées à échéance sans paiement (toutes, ou celles d'un client).
     *
     * @return list<int>
     */
    public function dueIds(\DateTimeImmutable $now, ?User $user = null, int $limit = 500): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.id')
            ->where('r.status IN (:statuses)')
            ->andWhere('r.expiresAt <= :now')
            ->setParameter('statuses', Reservation::AWAITING_PAYMENT)
            ->setParameter('now', $now)
            ->orderBy('r.expiresAt', 'ASC')
            ->setMaxResults($limit);
        if ($user !== null) {
            $qb->andWhere('r.user = :user')->setParameter('user', $user);
        }

        return array_map('intval', array_column($qb->getQuery()->getScalarResult(), 'id'));
    }

    /**
     * Réservations à rappeler : paiement attendu, échéance dans moins de `$within`, pas encore rappelées.
     *
     * @return list<Reservation>
     */
    public function dueForReminder(\DateTimeImmutable $now, \DateTimeImmutable $within, int $limit = 500): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status IN (:statuses)')
            ->andWhere('r.expiresAt > :now')
            ->andWhere('r.expiresAt <= :within')
            ->andWhere('r.expiringNotifiedAt IS NULL')
            ->setParameter('statuses', Reservation::AWAITING_PAYMENT)
            ->setParameter('now', $now)
            ->setParameter('within', $within)
            ->orderBy('r.expiresAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Réservations d'un client à un statut donné, les plus récentes d'abord.
     *
     * @param list<string> $statuses
     *
     * @return list<Reservation>
     */
    public function findForUserByStatus(User $user, array $statuses): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', $statuses)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Page de l'historique : en cours (`active`) ou terminées (`done`), dernière mise à jour d'abord.
     *
     * @return array{items: list<Reservation>, total: int}
     */
    public function pageForUser(User $user, bool $done, int $page): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere($done ? 'r.status IN (:final)' : 'r.status NOT IN (:final)')
            ->setParameter('user', $user)
            ->setParameter('final', Reservation::FINAL_STATUSES);

        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->orderBy('r.updatedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /** @return array{active: int, done: int} */
    public function countsForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.status, COUNT(r.id) AS n')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->groupBy('r.status')
            ->getQuery()
            ->getScalarResult();

        $counts = ['active' => 0, 'done' => 0];
        foreach ($rows as $row) {
            $counts[\in_array($row['status'], Reservation::FINAL_STATUSES, true) ? 'done' : 'active'] += (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Réservation (autre que `$exceptId`) qui a déjà reçu une preuve de ce contenu.
     */
    public function refWithProof(string $sha256, int $exceptId): ?string
    {
        $ref = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT r.ref FROM reservation_proofs p INNER JOIN reservations r ON r.id = p.reservation_id
             WHERE p.sha256 = ? AND p.reservation_id <> ? LIMIT 1',
            [$sha256, $exceptId],
        );

        return $ref !== false ? (string) $ref : null;
    }
}
