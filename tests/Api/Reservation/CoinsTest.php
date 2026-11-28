<?php

namespace App\Tests\Api\Reservation;

use App\Repository\UserRepository;
use App\Reservation\ReservationLifecycle;
use App\Tests\ReservationTestCase;

/**
 * Portefeuille de pièces (App\Wallet\CoinWallet) : 100 pièces offertes à la création du compte, 1 pièce = 20 FCFA de
 * réduction sur les articles d'une réservation, pièces rendues si elle expire ou est annulée.
 * hoodie-barbele-noir : 45 000 FCFA (Yaoundé, retrait sans frais).
 */
final class CoinsTest extends ReservationTestCase
{
    public function testANewAccountReceivesTheWelcomeCoinsOnce(): void
    {
        $first = $this->login();
        self::assertTrue($first['isNewUser']);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(100, $response['welcomeCoins']);
        self::assertSame(100, $first['user']['coins']);

        // Deuxième connexion : pas de nouveau bonus
        $second = $this->login();
        self::assertFalse($second['isNewUser']);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(0, $response['welcomeCoins']);
        self::assertSame(100, $this->api('GET', '/api/me', $second['token'])['coins'] ?? null);

        $movements = $this->em()->getConnection()->fetchAllAssociative('SELECT amount, balance_after, reason FROM coin_movements');
        self::assertSame([['amount' => 100, 'balance_after' => 100, 'reason' => 'welcome']], array_map(fn ($m) => array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $m), $movements));

        // Valeur d'une pièce et bonus annoncés à l'app
        self::assertSame(['valueFcfa' => 20, 'welcomeBonus' => 100], $this->getJson('/api/settings/public')['coins'] ?? null);
    }

    public function testCoinsReduceTheAmountToPay(): void
    {
        $token = $this->loginNamed();

        // Montant affiché sans tenir compte des pièces : refusé, avec le bon montant
        $refused = $this->reserve($token, $this->pickup() + ['useCoins' => true, 'expectedTotal' => 45000]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('PRICE_CHANGED', $refused['code']);
        self::assertSame(43000, $refused['details']['total']);
        self::assertSame(2000, $refused['details']['coinsDiscount']);

        $data = $this->reserve($token, $this->pickup() + ['useCoins' => true, 'expectedTotal' => 43000]);
        self::assertSame(201, $this->httpStatus(), json_encode($data));
        self::assertSame('reserved', $data['status']);
        self::assertSame(45000, $data['subtotal']);
        self::assertSame(43000, $data['total']);
        self::assertSame(100, $data['coinsUsed']);
        self::assertSame(2000, $data['coinsDiscount']);
        self::assertSame(['subtotal' => 45000, 'deliveryFee' => 0, 'total' => 43000, 'coinsDiscount' => 2000], $data['amount']);
        self::assertSame(0, $this->api('GET', '/api/me', $token)['coins']);

        // Plus de pièces : la réservation suivante est au prix plein, même en demandant les pièces
        $next = $this->reserve($token, $this->pickup() + ['useCoins' => true, 'expectedTotal' => 45000]);
        self::assertSame(201, $this->httpStatus(), json_encode($next));
        self::assertSame(45000, $next['total']);
        self::assertArrayNotHasKey('coinsUsed', $next);

        $spent = $this->em()->getConnection()->fetchAssociative("SELECT amount, balance_after FROM coin_movements WHERE reason = 'spent'");
        self::assertSame(['amount' => -100, 'balance_after' => 0], array_map('intval', $spent));

        // Choix invalide
        $this->reserve($token, $this->pickup() + ['useCoins' => 'oui']);
        self::assertSame(422, $this->httpStatus());
    }

    public function testWithoutTheChoiceTheCoinsAreKept(): void
    {
        $token = $this->loginNamed();
        $data = $this->reserve($token, $this->pickup() + ['expectedTotal' => 45000]);
        self::assertSame(201, $this->httpStatus());
        self::assertSame(45000, $data['total']);
        self::assertSame(100, $this->api('GET', '/api/me', $token)['coins']);
    }

    public function testCoinsComeBackWhenTheReservationIsCancelledOrExpires(): void
    {
        $token = $this->loginNamed();

        $cancelled = $this->reserve($token, $this->pickup() + ['useCoins' => true]);
        self::assertSame(0, $this->api('GET', '/api/me', $token)['coins']);
        $this->api('POST', '/api/reservations/' . $cancelled['ref'] . '/cancel', $token, ['reason' => 'Je change d’avis']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(100, $this->api('GET', '/api/me', $token)['coins']);
        // Une seule fois
        $this->api('POST', '/api/reservations/' . $cancelled['ref'] . '/cancel', $token, []);
        self::assertSame(100, $this->api('GET', '/api/me', $token)['coins']);

        $expiring = $this->reserve($token, $this->pickup() + ['useCoins' => true]);
        self::assertSame(0, $this->api('GET', '/api/me', $token)['coins']);
        $this->em()->getConnection()->executeStatement("UPDATE reservations SET expires_at = '2020-01-01 00:00:00' WHERE ref = ?", [$expiring['ref']]);
        $this->em()->clear();
        self::assertSame(1, static::getContainer()->get(ReservationLifecycle::class)->expireDue());
        self::assertSame(100, $this->api('GET', '/api/me', $token)['coins']);

        $refunds = $this->em()->getConnection()->fetchFirstColumn("SELECT amount FROM coin_movements WHERE reason = 'refund' ORDER BY id");
        self::assertSame([100, 100], array_map('intval', $refunds));
    }

    public function testCoinsCanPayAWholeCheapReservation(): void
    {
        $token = $this->loginNamed();
        $this->em()->getConnection()->executeStatement("UPDATE products SET price = 1500 WHERE id = 'prod-01'");

        $data = $this->reserve($token, $this->pickup() + ['useCoins' => true, 'expectedTotal' => 0]);
        self::assertSame(201, $this->httpStatus(), json_encode($data));
        self::assertSame(0, $data['total']);
        self::assertSame(75, $data['coinsUsed']);
        self::assertSame(1500, $data['coinsDiscount']);
        // Rien à payer : confirmée tout de suite
        self::assertSame('confirmed', $data['status']);
        self::assertSame(25, static::getContainer()->get(UserRepository::class)->findByPhone(self::PHONE)?->getCoins());
    }
}
