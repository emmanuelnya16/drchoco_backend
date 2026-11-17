<?php

namespace App\Repository;

use App\Admin\Reservation\ReservationScope;
use App\Entity\Admin;
use App\Entity\Reservation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    /** Réservation par sa référence (administration), sans tenir compte de la casse. */
    public function findByRef(string $ref): ?Reservation
    {
        $ref = strtoupper(trim($ref));
        if (preg_match('/^RES-\d{1,12}\z/', $ref) !== 1) {
            return null;
        }

        return $this->findOneBy(['ref' => $ref]);
    }

    /**
     * Nombre de réservations par statut, dans le périmètre de l'admin.
     *
     * @return array<string, int>
     */
    public function adminStatusCounts(Admin $admin): array
    {
        $qb = $this->createQueryBuilder('r')->select('r.status AS status, COUNT(r.id) AS n')->groupBy('r.status');
        ReservationScope::restrict($qb, 'r', $admin);

        $counts = [];
        foreach ($qb->getQuery()->getScalarResult() as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /** Paiement attendu, échéance entre maintenant et `$within` (tableau de bord : « expirent bientôt »). */
    public function adminExpiringCount(Admin $admin, \DateTimeImmutable $now, \DateTimeImmutable $within): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.status IN (:statuses)')
            ->andWhere('r.expiresAt > :now')
            ->andWhere('r.expiresAt <= :within')
            ->setParameter('statuses', Reservation::AWAITING_PAYMENT)
            ->setParameter('now', $now)
            ->setParameter('within', $within);
        ReservationScope::restrict($qb, 'r', $admin);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Page de la liste de l'administration : preuves à vérifier d'abord (la plus ancienne en premier), puis
     * dernière mise à jour d'abord.
     *
     * @param array{statuses?: list<string>|null, receptionMode?: string|null, city?: string|null, shopCity?: string|null, operator?: string|null, q?: string|null} $filters
     *
     * @return array{ids: list<int>, total: int}
     */
    public function adminPage(Admin $admin, array $filters, int $offset, int $limit): array
    {
        $qb = $this->createQueryBuilder('r');
        ReservationScope::restrict($qb, 'r', $admin);

        if (!empty($filters['statuses'])) {
            $qb->andWhere('r.status IN (:statuses)')->setParameter('statuses', $filters['statuses']);
        }
        if (!empty($filters['receptionMode'])) {
            $qb->andWhere('r.receptionMode = :mode')->setParameter('mode', $filters['receptionMode']);
        }
        if (!empty($filters['city'])) {
            // Ville affichée : celle de la boutique pour un retrait, la destination pour une livraison.
            $qb->andWhere('(r.receptionMode = :pickupMode AND r.shopCity = :city) OR (r.receptionMode = :deliveryMode AND r.deliveryCityName = :city)')
                ->setParameter('pickupMode', Reservation::MODE_PICKUP)
                ->setParameter('deliveryMode', Reservation::MODE_DELIVERY)
                ->setParameter('city', $filters['city']);
        }
        if (!empty($filters['shopCity'])) {
            $qb->andWhere('r.shopCity = :shopCity')->setParameter('shopCity', $filters['shopCity']);
        }
        if (!empty($filters['operator'])) {
            $qb->andWhere('r.paymentOperator = :operator')->setParameter('operator', $filters['operator']);
        }
        if (!empty($filters['q'])) {
            self::matchText($qb, (string) $filters['q']);
        }

        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        $rows = $qb->select('r.id')
            ->addSelect('CASE WHEN r.status = :pending THEN 0 ELSE 1 END AS HIDDEN sortGroup')
            ->addSelect('CASE WHEN r.status = :pending THEN r.updatedAt ELSE :epoch END AS HIDDEN sortPending')
            ->setParameter('pending', Reservation::STATUS_PENDING_VALIDATION)
            ->setParameter('epoch', new \DateTimeImmutable('@0'))
            ->orderBy('sortGroup', 'ASC')
            ->addOrderBy('sortPending', 'ASC')
            ->addOrderBy('r.updatedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return ['ids' => array_map('intval', array_column($rows, 'id')), 'total' => $total];
    }

    /**
     * Recherche rapide (référence, nom, téléphone, ville), dernière mise à jour d'abord.
     *
     * @return list<int>
     */
    public function adminSearchIds(Admin $admin, string $query, int $limit): array
    {
        $qb = $this->createQueryBuilder('r')->select('r.id');
        ReservationScope::restrict($qb, 'r', $admin);
        self::matchText($qb, $query);

        $rows = $qb->orderBy('r.updatedAt', 'DESC')->addOrderBy('r.id', 'DESC')->setMaxResults($limit)->getQuery()->getScalarResult();

        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * File d'action du tableau de bord : preuves à vérifier (la plus ancienne d'abord), puis à préparer, puis
     * colis prêts.
     *
     * @return list<int>
     */
    public function adminUrgentIds(Admin $admin, int $limit): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.id')
            ->addSelect('CASE WHEN r.status = :pending THEN 0 WHEN r.status = :confirmed THEN 1 WHEN r.status = :preparing THEN 2 ELSE 3 END AS HIDDEN sortGroup')
            ->addSelect('CASE WHEN r.status = :pending THEN r.updatedAt ELSE :epoch END AS HIDDEN sortPending')
            ->where('r.status IN (:statuses)')
            ->setParameter('pending', Reservation::STATUS_PENDING_VALIDATION)
            ->setParameter('confirmed', Reservation::STATUS_CONFIRMED)
            ->setParameter('preparing', Reservation::STATUS_PREPARING)
            ->setParameter('epoch', new \DateTimeImmutable('@0'))
            ->setParameter('statuses', [
                Reservation::STATUS_PENDING_VALIDATION, Reservation::STATUS_CONFIRMED, Reservation::STATUS_PREPARING,
                Reservation::STATUS_READY_FOR_PICKUP, Reservation::STATUS_HANDED_TO_AGENCY,
            ]);
        ReservationScope::restrict($qb, 'r', $admin);

        $rows = $qb->orderBy('sortGroup', 'ASC')
            ->addOrderBy('sortPending', 'ASC')
            ->addOrderBy('r.updatedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * Réservations chargées avec leurs articles et leurs preuves (lignes de liste), dans l'ordre de `$ids`.
     *
     * @param list<int> $ids
     *
     * @return list<Reservation>
     */
    public function loadForRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $found = $this->createQueryBuilder('r')
            ->addSelect('i', 'p')
            ->leftJoin('r.items', 'i')
            ->leftJoin('r.proofs', 'p')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($found as $reservation) {
            $byId[(int) $reservation->getId()] = $reservation;
        }

        return array_values(array_filter(array_map(fn (int $id) => $byId[$id] ?? null, $ids)));
    }

    /**
     * Autres réservations qui ont reçu une preuve de même empreinte (contrôle anti-fraude de l'administration).
     *
     * @return list<string>
     */
    public function refsWithProof(string $sha256, int $exceptId, int $limit = 10): array
    {
        return array_map('strval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT r.ref FROM reservation_proofs p INNER JOIN reservations r ON r.id = p.reservation_id
             WHERE p.sha256 = ? AND p.reservation_id <> ? AND r.ref IS NOT NULL ORDER BY r.ref LIMIT ' . max(1, $limit),
            [$sha256, $exceptId],
        ));
    }

    /**
     * Texte libre : référence, prénom + nom, ville de la boutique ou de destination, destinataire ; téléphone
     * (chiffres) à partir de 2 chiffres, sauf pour une référence (« RES-000104 » ne cherche pas de numéro).
     */
    private static function matchText(QueryBuilder $qb, string $query): void
    {
        $query = trim(mb_substr($query, 0, 80));
        if ($query === '') {
            return;
        }
        $conditions = [
            'r.ref LIKE :text',
            "CONCAT(r.customerFirstName, ' ', r.customerLastName) LIKE :text",
            'r.shopCity LIKE :text',
            'r.deliveryCityName LIKE :text',
            'r.recipientName LIKE :text',
        ];
        $qb->setParameter('text', '%' . addcslashes($query, '%_\\') . '%');
        $digits = (string) preg_replace('/\D/', '', $query);
        if (\strlen($digits) >= 2 && preg_match('/^\s*RES/i', $query) !== 1) {
            $conditions[] = 'r.customerPhone LIKE :digits';
            $conditions[] = 'r.recipientPhone LIKE :digits';
            $qb->setParameter('digits', '%' . $digits . '%');
        }
        $qb->andWhere('(' . implode(' OR ', $conditions) . ')');
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
