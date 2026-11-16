<?php

namespace App\Tests\Api\Reservation;

use App\Tests\CatalogTestCase;

/**
 * GET /api/delivery/cities?from= et /api/delivery/agencies?from= : livraison selon la boutique de départ ;
 * GET /api/settings/public : une boutique par ville.
 */
final class DeliveryFromShopTest extends CatalogTestCase
{
    public function testCitiesDependOnTheShop(): void
    {
        $byName = fn (array $list) => array_column($list, null, 'name');

        $yde = $byName($this->getJson('/api/delivery/cities'));
        self::assertSame([true, false, 1500], [$yde['Yaoundé']['isLocal'], $yde['Yaoundé']['hasAgencies'], $yde['Yaoundé']['fee']]);
        self::assertSame([false, true, 2500], [$yde['Douala']['isLocal'], $yde['Douala']['hasAgencies'], $yde['Douala']['fee']]);
        self::assertSame($yde, $byName($this->getJson('/api/delivery/cities?from=Yaound%C3%A9')));

        $dla = $byName($this->getJson('/api/delivery/cities?from=douala'));
        self::assertSame([true, false, 1500], [$dla['Douala']['isLocal'], $dla['Douala']['hasAgencies'], $dla['Douala']['fee']]);
        self::assertSame([false, true, 1500], [$dla['Yaoundé']['isLocal'], $dla['Yaoundé']['hasAgencies'], $dla['Yaoundé']['fee']], 'Yaoundé : par agence, à partir du prix le plus bas');

        // Agence réservée à une boutique : seulement proposée depuis celle-ci ; prix le plus bas affiché.
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE agencies SET departure_city = 'Douala', fee = 2000 WHERE id = 'ag-baf-1'");
        $fromDla = array_column($this->getJson('/api/delivery/agencies?city=Bafoussam&from=Douala'), 'fee', 'id');
        $fromYde = array_column($this->getJson('/api/delivery/agencies?city=Bafoussam&from=Yaound%C3%A9'), 'fee', 'id');
        self::assertSame(['ag-baf-1' => 2000, 'ag-baf-2' => 3000], $fromDla);
        self::assertSame(['ag-baf-2' => 3000], $fromYde);
        self::assertCount(2, $this->getJson('/api/delivery/agencies?city=Bafoussam'), 'sans boutique : toutes');
        self::assertSame(2000, $byName($this->getJson('/api/delivery/cities?from=Douala'))['Bafoussam']['fee']);
        self::assertSame(3000, $byName($this->getJson('/api/delivery/cities?from=Yaound%C3%A9'))['Bafoussam']['fee']);
    }

    public function testSettingsListOneShopPerCity(): void
    {
        $settings = $this->getJson('/api/settings/public');

        self::assertSame(['Yaoundé', 'Douala'], array_column($settings['shops'], 'city'));
        self::assertSame(['city', 'name', 'address', 'openingHours', 'mapsUrl', 'homeDeliveryFee'], array_keys($settings['shops'][0]));
        self::assertSame([1500, 1500], array_column($settings['shops'], 'homeDeliveryFee'));
        self::assertSame('Yaoundé', $settings['shop']['city'], 'boutique principale inchangée');
    }
}
