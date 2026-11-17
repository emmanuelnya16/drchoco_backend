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

        // Sans boutique (profil, adresses) : toutes les villes.
        $all = $byName($this->getJson('/api/delivery/cities'));
        self::assertCount(15, $all);
        self::assertSame([true, false, 1500], [$all['Yaoundé']['isLocal'], $all['Yaoundé']['hasAgencies'], $all['Yaoundé']['fee']]);
        self::assertSame([false, true, 1000], [$all['Douala']['isLocal'], $all['Douala']['hasAgencies'], $all['Douala']['fee']], 'à partir du prix le plus bas');
        self::assertSame([false, false], [$all['Bamenda']['isLocal'], $all['Bamenda']['hasAgencies']]);

        // Avec la boutique de départ (réservation) : seulement les villes où le colis peut arriver.
        $yde = $byName($this->getJson('/api/delivery/cities?from=Yaound%C3%A9'));
        self::assertSame(['Yaoundé', 'Douala', 'Bafoussam'], array_keys($yde));
        self::assertSame([$all['Yaoundé'], $all['Douala']], [$yde['Yaoundé'], $yde['Douala']]);
        $dla = $byName($this->getJson('/api/delivery/cities?from=douala'));
        self::assertSame(['Yaoundé', 'Douala', 'Bafoussam'], array_keys($dla));
        self::assertSame([true, false, 1500], [$dla['Douala']['isLocal'], $dla['Douala']['hasAgencies'], $dla['Douala']['fee']]);
        self::assertSame([false, true, 1000], [$dla['Yaoundé']['isLocal'], $dla['Yaoundé']['hasAgencies'], $dla['Yaoundé']['fee']]);

        // Agence réservée à une boutique : seulement proposée depuis celle-ci ; prix le plus bas affiché.
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE agencies SET is_active = 1, departure_city = 'Douala', fee = 2000 WHERE id = 'ag-baf-mta'");
        $fromDla = array_column($this->getJson('/api/delivery/agencies?city=Bafoussam&from=Douala'), 'fee', 'id');
        $fromYde = array_column($this->getJson('/api/delivery/agencies?city=Bafoussam&from=Yaound%C3%A9'), 'fee', 'id');
        self::assertSame(['ag-baf-touristique' => 3000, 'ag-baf-mta' => 2000], $fromDla);
        self::assertSame(['ag-baf-touristique' => 3000], $fromYde);
        self::assertCount(2, $this->getJson('/api/delivery/agencies?city=Bafoussam'), 'sans boutique : toutes');
        self::assertSame(2000, $byName($this->getJson('/api/delivery/cities?from=Douala'))['Bafoussam']['fee']);
        self::assertSame(3000, $byName($this->getJson('/api/delivery/cities?from=Yaound%C3%A9'))['Bafoussam']['fee']);

        // Plus aucune agence vers Bafoussam depuis Yaoundé : la ville n'est plus proposée depuis Yaoundé.
        $connection->executeStatement("UPDATE agencies SET is_active = 0 WHERE id = 'ag-baf-touristique'");
        self::assertArrayNotHasKey('Bafoussam', $byName($this->getJson('/api/delivery/cities?from=Yaound%C3%A9')));
        self::assertArrayHasKey('Bafoussam', $byName($this->getJson('/api/delivery/cities?from=Douala')));
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
