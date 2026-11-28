<?php

namespace App\Wallet;

use App\Entity\CoinMovement;
use App\Entity\Reservation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Portefeuille de pièces du client (27/11/2026) :
 *  - 100 pièces offertes à la création du compte (une seule fois : le compte est créé une seule fois par numéro) ;
 *  - 1 pièce = 20 FCFA de réduction sur les articles d'une réservation, au choix du client (jamais sur les frais de
 *    livraison) ; autant de pièces que le prix des articles le permet ;
 *  - pièces rendues si la réservation expire ou est annulée.
 * Le solde est dans users.coins, chaque mouvement dans coin_movements. Toujours appelé dans la transaction de
 * l'appelant, la ligne du client verrouillée (pas de double dépense entre deux réservations simultanées).
 */
final class CoinWallet
{
    public const COIN_VALUE_FCFA = 20;
    public const WELCOME_BONUS = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Pièces utilisables pour des articles de ce montant, et la réduction correspondante.
     *
     * @return array{coins: int, discount: int}
     */
    public static function discountFor(int $articlesAmount, int $balance): array
    {
        $coins = max(0, min($balance, intdiv(max(0, $articlesAmount), self::COIN_VALUE_FCFA)));

        return ['coins' => $coins, 'discount' => $coins * self::COIN_VALUE_FCFA];
    }

    /** Bonus de bienvenue d'un compte qui vient d'être créé. @return int pièces offertes */
    public function grantWelcome(User $user, \DateTimeImmutable $now): int
    {
        $user->setCoins($user->getCoins() + self::WELCOME_BONUS);
        $this->em->persist(new CoinMovement($user, self::WELCOME_BONUS, $user->getCoins(), CoinMovement::REASON_WELCOME, $now));

        return self::WELCOME_BONUS;
    }

    /**
     * Pièces retirées pour une réservation. `$lockedBalance` : solde relu sous le verrou de la ligne du client.
     */
    public function spend(User $user, Reservation $reservation, int $lockedBalance, \DateTimeImmutable $now): void
    {
        $coins = $reservation->getCoinsUsed();
        if ($coins <= 0) {
            return;
        }
        $user->setCoins($lockedBalance - $coins);
        $this->em->persist(new CoinMovement($user, -$coins, $user->getCoins(), CoinMovement::REASON_SPENT, $now, $reservation));
    }

    /** Pièces rendues au client d'une réservation expirée ou annulée (une seule fois). */
    public function refund(Reservation $reservation, \DateTimeImmutable $now): void
    {
        $coins = $reservation->getCoinsUsed();
        if ($coins <= 0 || $reservation->getCoinsRefundedAt() !== null) {
            return;
        }
        $user = $reservation->getUser();
        $balance = (int) $this->em->getConnection()->fetchOne('SELECT coins FROM users WHERE id = ? FOR UPDATE', [$user->getId()]);
        $user->setCoins($balance + $coins);
        $reservation->setCoinsRefundedAt($now);
        $this->em->persist(new CoinMovement($user, $coins, $user->getCoins(), CoinMovement::REASON_REFUND, $now, $reservation));
    }
}
