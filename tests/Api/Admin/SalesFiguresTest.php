<?php

namespace App\Tests\Api\Admin;

use App\Entity\Admin;
use App\Tests\AdminReservationTestCase;

/**
 * Chiffres de ventes du tableau de bord et de la liste des vendeurs (P7) : ventes = paiements validés (annulées
 * après paiement exclues), CA hors frais de livraison, périodes en heure du Cameroun, périmètre des vendeurs.
 */
final class SalesFiguresTest extends AdminReservationTestCase
{
    private const PAUL = '+237690000002';
    private const AWA = '+237690000003';

    /**
     * Junior : retrait à Yaoundé payé par Orange Money (45 000) ; Paul : 2 t-shirts de Douala livrés par agence,
     * payés par MTN MoMo (36 000 + 1 000 de frais) ; Awa : réservation de Yaoundé non payée.
     *
     * @return array{owner: string, junior: string, paul: string, awa: string}
     */
    private function sales(): array
    {
        $owner = $this->ownerToken();
        ['ref' => $junior] = $this->reservationWithProof();

        $paulToken = $this->loginNamed(self::PAUL, 'Paul', 'Essomba');
        $paul = $this->reserve($paulToken, $this->pickup(self::DLA_VARIANT, 2, ['phone' => self::PAUL, 'receptionMode' => 'delivery', 'city' => 'Yaoundé', 'agencyId' => 'ag-yde-travel-lines']))['ref'];
        $this->uploadProof($paulToken, $paul, $this->file(self::pngWithText('Comment', 'paul')), ['operator' => 'mtn']);
        self::assertSame(201, $this->httpStatus());

        $awa = $this->reserve($this->loginNamed(self::AWA, 'Awa'), $this->pickup(override: ['phone' => self::AWA]))['ref'];
        foreach ([$junior, $paul] as $ref) {
            $this->adminApi('POST', "/api/admin/reservations/$ref/validate-payment", $owner, []);
            self::assertSame(200, $this->httpStatus());
        }

        return ['owner' => $owner, 'junior' => $junior, 'paul' => $paul, 'awa' => $awa];
    }

    private function totalStock(string $productId): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT SUM(stock) FROM product_variants WHERE product_id = ?', [$productId]);
    }

    public function testDashboardFiguresForTheSuperAdmin(): void
    {
        ['owner' => $owner, 'junior' => $junior] = $this->sales();

        $kpis = $this->adminApi('GET', '/api/admin/dashboard', $owner)['kpis'];
        self::assertSame(['today', '7d', '30d', 'year'], array_keys($kpis));
        $today = $kpis['today'];
        self::assertSame([81000, 3, 3, 2, 67, 40500], [$today['revenueConfirmed'], $today['unitsSold'], $today['reservationsCreated'], $today['reservationsConfirmed'], $today['conversionRate'], $today['averageBasket']], 'CA hors frais de livraison');
        self::assertSame(['orange' => 1, 'mtn' => 1], $today['byOperator']);
        self::assertSame(['delivery' => 1, 'pickup' => 1], $today['byReception']);
        self::assertSame($today, $kpis['year']);

        self::assertSame(['prod-01', 'prod-02'], array_column($today['productSales'], 'productId'), 'CA décroissant');
        self::assertSame(
            ['productId' => 'prod-01', 'slug' => 'hoodie-barbele-noir', 'name' => 'Hoodie Barbelé Noir', 'image' => $today['productSales'][0]['image'], 'city' => 'Yaoundé', 'unitPrice' => 45000, 'unitsSold' => 1, 'revenue' => 45000, 'stock' => $this->totalStock('prod-01')],
            $today['productSales'][0],
        );
        self::assertSame([2, 36000, 'Douala'], [$today['productSales'][1]['unitsSold'], $today['productSales'][1]['revenue'], $today['productSales'][1]['city']]);

        // Payée puis annulée par la boutique : ce n'est plus une vente.
        $this->adminApi('POST', "/api/admin/reservations/$junior/cancel", $owner, ['reason' => 'Article abîmé']);
        $today = $this->adminApi('GET', '/api/admin/dashboard', $owner)['kpis']['today'];
        self::assertSame([36000, 2, 1, ['orange' => 0, 'mtn' => 1], ['prod-02']], [$today['revenueConfirmed'], $today['unitsSold'], $today['reservationsConfirmed'], $today['byOperator'], array_column($today['productSales'], 'productId')]);
    }

    public function testPeriodsFollowCameroonTime(): void
    {
        ['owner' => $owner, 'paul' => $paul] = $this->sales();
        // Minuit (heure du Cameroun) aujourd'hui, en UTC : la vente de Paul passe à hier 23 h 59, il y a 10 jours, puis il y a 2 ans.
        $midnight = (new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp()))->setTimezone(new \DateTimeZone('Africa/Douala'))->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
        $connection = $this->em()->getConnection();
        $move = fn (string $modifier) => $connection->executeStatement('UPDATE reservations SET paid_at = ?, created_at = ? WHERE ref = ?', [$midnight->modify($modifier)->format('Y-m-d H:i:s'), $midnight->modify($modifier)->format('Y-m-d H:i:s'), $paul]);

        $move('-1 minute');
        $kpis = $this->adminApi('GET', '/api/admin/dashboard', $owner)['kpis'];
        self::assertSame([45000, 81000, 81000, 81000], array_column($kpis, 'revenueConfirmed'), 'hier soir : pas aujourd’hui, mais dans les 7 jours');
        self::assertSame([2, 3, 3, 3], array_column($kpis, 'reservationsCreated'));

        $move('-9 days');
        self::assertSame([45000, 45000, 81000, 81000], array_column($this->adminApi('GET', '/api/admin/dashboard', $owner)['kpis'], 'revenueConfirmed'));
        $move('-730 days');
        self::assertSame([45000, 45000, 45000, 45000], array_column($this->adminApi('GET', '/api/admin/dashboard', $owner)['kpis'], 'revenueConfirmed'));
    }

    public function testSellersSeeTheirOwnFigures(): void
    {
        ['owner' => $owner] = $this->sales();
        $douala = null;
        $doualaToken = $this->sellerToken('Douala', $douala);
        $yaounde = null;
        $this->sellerToken('Yaoundé', $yaounde);

        $today = $this->adminApi('GET', '/api/admin/dashboard', $doualaToken)['kpis']['today'];
        self::assertSame([36000, 2, 1, 1, 100], [$today['revenueConfirmed'], $today['unitsSold'], $today['reservationsCreated'], $today['reservationsConfirmed'], $today['conversionRate']]);
        self::assertSame([['orange' => 0, 'mtn' => 1], ['prod-02']], [$today['byOperator'], array_column($today['productSales'], 'productId')]);

        $sellers = array_column($this->adminApi('GET', '/api/admin/sellers', $owner)['sellers'], null, 'email');
        $dla = $sellers[$douala->getEmail()];
        self::assertSame([2, 36000, ['unitsSold' => 2, 'revenueConfirmed' => 36000, 'reservationsConfirmed' => 1]], [$dla['unitsSoldTotal'], $dla['revenueConfirmedTotal'], $dla['kpis']['30d']]);
        self::assertSame(45000, $sellers[$yaounde->getEmail()]['revenueConfirmedTotal'], 'articles sans vendeur de sa ville');

        // Une ligne attribuée à un autre vendeur ne compte plus pour lui.
        $other = $this->createAdmin(Admin::ROLE_SELLER, 'autre.vendeur@drchoco.cm', 'Douala');
        $this->em()->getConnection()->executeStatement('UPDATE reservation_items SET seller_id = ?, seller_name = ? WHERE variant_id = ?', [$other->getId(), 'Autre', self::DLA_VARIANT]);
        $today = $this->adminApi('GET', '/api/admin/dashboard', $doualaToken)['kpis']['today'];
        self::assertSame([0, 0, []], [$today['revenueConfirmed'], $today['reservationsConfirmed'], $today['productSales']]);
        $sellers = array_column($this->adminApi('GET', '/api/admin/sellers', $owner)['sellers'], null, 'email');
        self::assertSame([0, 36000], [$sellers[$douala->getEmail()]['revenueConfirmedTotal'], $sellers['autre.vendeur@drchoco.cm']['revenueConfirmedTotal']]);
        $line = $this->adminApi('GET', '/api/admin/dashboard', $owner)['kpis']['today']['productSales'][1];
        self::assertSame(['productId' => 'prod-02', 'unitsSold' => 2], ['productId' => $line['productId'], 'unitsSold' => $line['unitsSold']]);
    }

    public function testManualValidationWithoutProofCountsInNoOperator(): void
    {
        $owner = $this->ownerToken();
        $ref = $this->reserve($this->loginNamed(), $this->pickup())['ref'];
        $this->adminApi('POST', "/api/admin/reservations/$ref/validate-payment", $owner, ['note' => 'Paiement constaté sur le compte marchand.']);

        $today = $this->adminApi('GET', '/api/admin/dashboard', $owner)['kpis']['today'];
        self::assertSame([1, ['orange' => 0, 'mtn' => 0]], [$today['reservationsConfirmed'], $today['byOperator']]);
    }
}
