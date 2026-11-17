<?php

namespace App\Admin\Reservation;

use App\Entity\Admin;
use App\Entity\Reservation;
use App\Entity\ReservationItem;
use Doctrine\ORM\QueryBuilder;

/**
 * Réservations qu'un membre de l'équipe peut voir (P6), même règle que pour les articles (P3) :
 *  - super admin (owner, manager) : toutes ;
 *  - vendeur : celles qui contiennent au moins un de ses articles, ou un article sans vendeur de sa ville.
 *
 * Chaque ligne de réservation garde le vendeur et la ville de l'article au moment de la réservation : un
 * article qui change ensuite de vendeur ne change pas qui voit les anciennes réservations.
 */
final class ReservationScope
{
    public static function canSee(Admin $admin, Reservation $reservation): bool
    {
        if ($admin->isSuperAdmin()) {
            return true;
        }
        foreach ($reservation->getItems() as $item) {
            if (self::owns($admin, $item)) {
                return true;
            }
        }

        return false;
    }

    public static function owns(Admin $admin, ReservationItem $item): bool
    {
        if ($item->getSellerId() !== null) {
            return $item->getSellerId() === $admin->getId();
        }

        return $admin->getCity() !== null && $item->getCity() === $admin->getCity();
    }

    /** Ajoute la condition du vendeur à une requête DQL sur les réservations (alias `$alias`). */
    public static function restrict(QueryBuilder $qb, string $alias, Admin $admin): QueryBuilder
    {
        if ($admin->isSuperAdmin()) {
            return $qb;
        }

        return $qb
            ->andWhere(sprintf(
                'EXISTS (SELECT scope_item.id FROM %s scope_item WHERE scope_item.reservation = %s AND (scope_item.sellerId = :scopeSeller OR (scope_item.sellerId IS NULL AND scope_item.city = :scopeCity)))',
                ReservationItem::class,
                $alias,
            ))
            ->setParameter('scopeSeller', (int) $admin->getId())
            ->setParameter('scopeCity', (string) $admin->getCity());
    }

    /**
     * Même condition en SQL (requêtes DBAL), sur la table `reservations` d'alias `$alias`.
     *
     * @return array{0: string, 1: list<int|string>} condition (vide pour un super admin) et ses paramètres
     */
    public static function sql(string $alias, Admin $admin): array
    {
        if ($admin->isSuperAdmin()) {
            return ['', []];
        }

        return [
            sprintf(' AND EXISTS (SELECT 1 FROM reservation_items scope_item WHERE scope_item.reservation_id = %s.id AND (scope_item.seller_id = ? OR (scope_item.seller_id IS NULL AND scope_item.city = ?)))', $alias),
            [(int) $admin->getId(), (string) $admin->getCity()],
        ];
    }
}
