<?php

namespace App\Tests\Api\Admin;

use App\Tests\AdminReservationTestCase;

/**
 * Liste et fiche des réservations de l'administration (P6), recherche rapide, répertoire des clients et
 * compteurs du tableau de bord, avec le périmètre des vendeurs.
 */
final class AdminReservationsListTest extends AdminReservationTestCase
{
    private const PAUL = '+237690000002';

    /**
     * Junior : retrait à Yaoundé avec preuve (à vérifier) ; Paul : livraison depuis Douala, non payée.
     *
     * @return array{yde: string, dla: string}
     */
    private function twoReservations(): array
    {
        ['ref' => $yde] = $this->reservationWithProof();
        $this->wait(60);
        $paul = $this->loginNamed(self::PAUL, 'Paul', 'Essomba');
        $dla = $this->reserve($paul, $this->pickup(self::DLA_VARIANT, 2, ['phone' => self::PAUL, 'firstName' => 'Paul', 'lastName' => 'Essomba', 'receptionMode' => 'delivery', 'city' => 'Douala', 'district' => 'Akwa', 'landmark' => 'Face à la station']));
        self::assertSame(201, $this->httpStatus(), json_encode($dla));

        return ['yde' => $yde, 'dla' => $dla['ref']];
    }

    public function testListWithTabsCountsAndRows(): void
    {
        ['yde' => $yde, 'dla' => $dla] = $this->twoReservations();
        $owner = $this->ownerToken();

        $data = $this->adminApi('GET', '/api/admin/reservations', $owner);
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));
        self::assertSame(['items', 'page', 'pageSize', 'total', 'totalPages', 'counts'], array_keys($data));
        self::assertSame([1, 20, 2, 1], [$data['page'], $data['pageSize'], $data['total'], $data['totalPages']]);
        self::assertSame(['all' => 2, 'toValidate' => 1, 'toPrepare' => 0, 'ready' => 0, 'inDelivery' => 0, 'attention' => 1, 'done' => 0], $data['counts']);
        self::assertSame([$yde, $dla], array_column($data['items'], 'ref'), 'preuve à vérifier d’abord');

        [$first, $second] = $data['items'];
        self::assertSame(['ref', 'status', 'createdAt', 'updatedAt', 'expiresAt', 'total', 'itemsCount', 'firstItemSummary', 'receptionMode', 'city', 'shopCity', 'customer', 'operator', 'hasProof', 'hasPackagePhotos', 'refundDue'], array_keys($first));
        self::assertSame(['pending_validation', 'pickup', 'Yaoundé', 'Yaoundé', 'orange', true, false], [$first['status'], $first['receptionMode'], $first['city'], $first['shopCity'], $first['operator'], $first['hasProof'], $first['hasPackagePhotos']]);
        self::assertSame(['firstName' => 'Junior', 'lastName' => 'Mbarga', 'phone' => self::PHONE], $first['customer']);
        self::assertSame('Hoodie Barbelé Noir (S · Noir Charbon)', $first['firstItemSummary']);
        self::assertSame(['delivery', 'Douala', 'Douala', 2, false], [$second['receptionMode'], $second['city'], $second['shopCity'], $second['itemsCount'], $second['hasProof']]);
        self::assertArrayNotHasKey('operator', $second);

        // Onglets, filtres et recherche.
        $refs = fn (string $query) => array_column($this->adminApi('GET', '/api/admin/reservations?' . $query, $owner)['items'], 'ref');
        self::assertSame([$yde], $refs('tab=to_validate'));
        self::assertSame([$dla], $refs('tab=attention'));
        self::assertSame([], $refs('tab=done'));
        self::assertSame([$dla], $refs('tab=to_validate&status=reserved'), 'statut prioritaire sur l’onglet');
        self::assertSame([$yde, $dla], $refs('tab=inconnu'));
        self::assertSame([$dla], $refs('receptionMode=delivery'));
        self::assertSame([$yde], $refs('city=yaounde'), 'ville sans accent ni casse');
        self::assertSame([$dla], $refs('shopCity=Douala'));
        self::assertSame([$yde], $refs('operator=orange'));
        self::assertSame([$dla], $refs('q=' . strtolower($dla)));
        self::assertSame([$dla], $refs('q=essomba'));
        self::assertSame([$dla], $refs('q=690000002'));

        $page = $this->adminApi('GET', '/api/admin/reservations?pageSize=1&page=9', $owner);
        self::assertSame([2, 1, 2, [$dla]], [$page['page'], $page['pageSize'], $page['totalPages'], array_column($page['items'], 'ref')], 'page bornée à la dernière');
        self::assertSame(50, $this->adminApi('GET', '/api/admin/reservations?pageSize=500', $owner)['pageSize']);
    }

    public function testDetailForTheTeam(): void
    {
        ['yde' => $yde, 'dla' => $dla] = $this->twoReservations();
        $owner = $this->ownerToken();

        $data = $this->adminDetail($owner, strtolower($yde));
        self::assertSame(['reservation', 'duplicateProofRefs', 'duplicateTransactionRefs', 'internalNotes', 'availableAgencies'], array_keys($data));
        self::assertSame([[], [], []], [$data['duplicateProofRefs'], $data['internalNotes'], $data['availableAgencies']]);
        $reservation = $data['reservation'];
        self::assertSame([$yde, 'pending', 'Yaoundé'], [$reservation['ref'], $reservation['proof']['status'], $reservation['pickupShop']['city']]);
        self::assertSame($this->clock->now()->getTimestamp(), strtotime($reservation['serverTime']), 'heure du serveur à jour');
        self::assertSame(['firstName' => 'Junior', 'lastName' => 'Mbarga', 'phone' => self::PHONE], $reservation['customer']);
        self::assertSame([], $reservation['packagePhotos']);

        // Article d'un vendeur : son nom figure sur la ligne (équipe seulement).
        $seller = null;
        $this->sellerToken('Douala', $seller);
        $this->em()->getConnection()->executeStatement('UPDATE reservation_items SET seller_id = ?, seller_name = ? WHERE variant_id = ?', [$seller->getId(), 'Boutique Akwa', self::DLA_VARIANT]);
        $line = $this->adminDetail($owner, $dla)['reservation']['items'][0];
        self::assertSame([(string) $seller->getId(), 'Boutique Akwa'], [$line['sellerId'], $line['sellerName']]);

        foreach (['RES-999999', 'pas-une-ref'] as $ref) {
            $data = $this->adminApi('GET', '/api/admin/reservations/' . $ref, $owner);
            self::assertSame([404, 'RESERVATION_NOT_FOUND'], [$this->httpStatus(), $data['code']]);
        }

        // Jeton client : refusé.
        $this->adminApi('GET', '/api/admin/reservations', $this->tokenFor($this->createUser('+237690000009')));
        self::assertSame(403, $this->httpStatus());
    }

    public function testOverdueReservationsExpireBeforeTheListIsShown(): void
    {
        ['yde' => $yde, 'dla' => $dla] = $this->twoReservations();
        $owner = $this->ownerToken();
        $this->wait(24 * 3600);

        $data = $this->adminApi('GET', '/api/admin/reservations?tab=done', $owner);
        self::assertSame([[$dla], 'expired'], [array_column($data['items'], 'ref'), $data['items'][0]['status']]);
        self::assertSame(8, $this->stock(self::DLA_VARIANT), 'articles remis en vente');
        self::assertSame('pending_validation', $this->adminDetail($owner, $yde)['reservation']['status'], 'preuve envoyée : n’expire pas');
    }

    public function testDashboardShowsStockAfterExpiringOverdueReservations(): void
    {
        $owner = $this->ownerToken();
        $baseline = $this->adminApi('GET', '/api/admin/dashboard', $owner)['counts']['outOfStockVariants'];
        $token = $this->loginNamed();
        $this->reserve($token, $this->pickup(quantity: 4)); // toutes les pièces de prod-01-noir-s
        self::assertSame(0, $this->stock(self::YDE_VARIANT));
        self::assertSame($baseline + 1, $this->adminApi('GET', '/api/admin/dashboard', $owner)['counts']['outOfStockVariants']);

        $this->wait(24 * 3600);
        $dashboard = $this->adminApi('GET', '/api/admin/dashboard', $owner);
        self::assertSame([$baseline, 0], [$dashboard['counts']['outOfStockVariants'], $dashboard['counts']['activeTotal']], 'réservation échue : pièces remises en vente dans la même réponse');
        self::assertNotContains(self::YDE_VARIANT, array_column($dashboard['lowStockItems'], 'variantId'));
    }

    public function testSellersSeeOnlyTheirScope(): void
    {
        ['yde' => $yde, 'dla' => $dla] = $this->twoReservations();
        $douala = $this->sellerToken('Douala');

        $data = $this->adminApi('GET', '/api/admin/reservations', $douala);
        self::assertSame([[$dla], 1, 0], [array_column($data['items'], 'ref'), $data['counts']['all'], $data['counts']['toValidate']]);
        self::assertSame([], array_column($this->adminApi('GET', '/api/admin/reservations?q=' . $yde, $douala)['items'], 'ref'));
        $this->adminApi('GET', '/api/admin/reservations/' . $yde, $douala);
        self::assertSame(404, $this->httpStatus());

        // Recherche rapide, clients, tableau de bord : même périmètre.
        self::assertSame([], $this->adminApi('GET', '/api/admin/search?q=Junior', $douala)['reservations']);
        self::assertSame([$dla], array_column($this->adminApi('GET', '/api/admin/search?q=Paul', $douala)['reservations'], 'ref'));
        $customers = $this->adminApi('GET', '/api/admin/customers', $douala);
        self::assertSame([1, ['Paul Essomba'], []], [$customers['total'], array_column($customers['items'], 'fullName'), $customers['items'][0]['addresses']]);
        $dashboard = $this->adminApi('GET', '/api/admin/dashboard', $douala);
        self::assertSame([0, 1], [$dashboard['counts']['pendingValidation'], $dashboard['counts']['activeTotal']]);
        self::assertSame([], $dashboard['urgentReservations']);

        // Un article du vendeur dans une autre réservation de Yaoundé : elle entre dans son périmètre.
        $seller = null;
        $yaounde = $this->sellerToken('Yaoundé', $seller);
        self::assertSame([$yde], array_column($this->adminApi('GET', '/api/admin/reservations', $yaounde)['items'], 'ref'));
        $this->em()->getConnection()->executeStatement('UPDATE reservation_items SET seller_id = ? WHERE variant_id = ?', [$seller->getId() + 1000, self::YDE_VARIANT]);
        self::assertSame([], array_column($this->adminApi('GET', '/api/admin/reservations', $yaounde)['items'], 'ref'), 'article d’un autre vendeur');
    }

    public function testSearchCustomersAndDashboardForTheSuperAdmin(): void
    {
        ['yde' => $yde, 'dla' => $dla] = $this->twoReservations();
        $owner = $this->ownerToken();
        $this->wait(60);
        $this->adminApi('POST', '/api/admin/reservations/' . $yde . '/validate-payment', $owner, []);

        $search = $this->adminApi('GET', '/api/admin/search?q=' . $yde, $owner);
        self::assertSame([$yde], array_column($search['reservations'], 'ref'));
        self::assertSame([$yde, $dla], array_column($this->adminApi('GET', '/api/admin/search?q=6900000', $owner)['reservations'], 'ref'), 'téléphone, la plus récemment mise à jour d’abord');

        $customers = $this->adminApi('GET', '/api/admin/customers', $owner);
        $byName = array_column($customers['items'], null, 'fullName');
        self::assertSame([1, 45000, [$yde]], [$byName['Junior Mbarga']['reservationsCount'], $byName['Junior Mbarga']['confirmedTotalFcfa'], array_column($byName['Junior Mbarga']['reservations'], 'ref')]);
        self::assertSame([1, 0], [$byName['Paul Essomba']['reservationsCount'], $byName['Paul Essomba']['confirmedTotalFcfa']], 'non payée : pas dans le total');
        self::assertSame('Junior Mbarga', $customers['items'][0]['fullName'], 'validation récente : client le plus récemment actif');
        self::assertSame($byName['Junior Mbarga']['reservations'][0]['updatedAt'], $byName['Junior Mbarga']['lastActivityAt']);

        $dashboard = $this->adminApi('GET', '/api/admin/dashboard', $owner);
        self::assertSame([0, 1, 0, 2], [$dashboard['counts']['pendingValidation'], $dashboard['counts']['toPrepare'], $dashboard['counts']['readyForHandover'], $dashboard['counts']['activeTotal']]);
        self::assertSame([$yde], array_column($dashboard['urgentReservations'], 'ref'));
        $this->wait(22 * 3600);
        self::assertSame(1, $this->adminApi('GET', '/api/admin/dashboard', $owner)['counts']['expiringSoon'], 'non payée, échéance dans moins de 3 h');
    }
}
