<?php

namespace App\Tests\Api\Admin;

use App\Entity\Address;
use App\Tests\AdminTestCase;

/**
 * Villes de livraison et agences dans l'administration (P8) : création, modification partielle, suppression,
 * villes des boutiques protégées, effet immédiat pour les clients.
 */
final class DeliveryAdminTest extends AdminTestCase
{
    private function owner(): string
    {
        $this->account();

        return $this->adminToken('owner@drchoco.cm');
    }

    public function testCitiesCanBeAddedChangedAndDeleted(): void
    {
        $token = $this->owner();
        $list = $this->api('GET', '/api/admin/delivery/cities', $token)['cities'];
        self::assertCount(15, $list);
        $byName = array_column($list, null, 'name');
        self::assertSame(['id', 'name', 'fee', 'isLocal', 'hasAgencies', 'estimatedDelay', 'isActive', 'position', 'agenciesCount', 'isShopCity'], array_keys($byName['Douala']));
        self::assertSame([true, 6], [$byName['Douala']['isShopCity'], $byName['Douala']['agenciesCount']]);
        self::assertSame([false, 0], [$byName['Kribi']['isShopCity'], $byName['Kribi']['agenciesCount']]);

        $data = $this->api('POST', '/api/admin/delivery/cities', $token, ['name' => '  Foumban ', 'fee' => 2750, 'isLocal' => false, 'hasAgencies' => true, 'estimatedDelay' => '2 à 3 jours']);
        self::assertSame(201, $this->httpStatus(), json_encode($data));
        self::assertSame(['city-foumban', 'Foumban', 2750, true, 0, false], [$data['city']['id'], $data['city']['name'], $data['city']['fee'], $data['city']['isActive'], $data['city']['agenciesCount'], $data['city']['isShopCity']]);
        self::assertCount(16, $data['cities']);
        self::assertSame('Foumban', end($data['cities'])['name'], 'ajoutée à la fin');

        foreach ([['name' => 'FOUMBAN', 'fee' => 100], ['name' => 'garoua', 'fee' => 100], ['name' => 'X', 'fee' => 100], ['name' => 'Mbouda', 'fee' => -1], ['name' => 'Mbouda']] as $body) {
            $result = $this->api('POST', '/api/admin/delivery/cities', $token, $body);
            self::assertSame([422, 'VALIDATION_ERROR'], [$this->httpStatus(), $result['code']], json_encode($body));
        }

        // Adresses et profil d'un client à Foumban : ils suivent le nouveau nom de la ville.
        $em = $this->em();
        $user = $this->createUser('+237690000077')->setCity('Foumban');
        $home = (new Address($user, 1, new \DateTimeImmutable()))->setCity('Foumban')->setDistrict('Foumban')->setLandmark('Marché central');
        $byAgency = (new Address($user, 2, new \DateTimeImmutable()))->setCity('Foumban')->setDistrict('Foumban')->setLandmark('Agence')->setAgencyId('ag-x');
        $em->persist($home);
        $em->persist($byAgency);
        $em->flush();

        $data = $this->api('PATCH', '/api/admin/delivery/cities/city-foumban', $token, ['fee' => 3000, 'name' => 'Foumban (Noun)']);
        self::assertSame([200, 3000, 'Foumban (Noun)', '2 à 3 jours'], [$this->httpStatus(), $data['city']['fee'], $data['city']['name'], $data['city']['estimatedDelay']], 'modification partielle');
        $db = $em->getConnection();
        $rows = $db->fetchAllKeyValue('SELECT id, CONCAT(city, \' / \', district) FROM addresses WHERE id IN (?, ?)', [$home->getId(), $byAgency->getId()]);
        self::assertSame(['Foumban (Noun) / Foumban', 'Foumban (Noun) / Foumban (Noun)'], [$rows[$home->getId()], $rows[$byAgency->getId()]], 'quartier changé seulement pour l’adresse par agence');
        self::assertSame('Foumban (Noun)', $db->fetchOne('SELECT city FROM users WHERE id = ?', [$user->getId()]));
        $this->client->request('DELETE', '/api/admin/delivery/cities/city-foumban', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertSame([204, ''], [$this->httpStatus(), (string) $this->client->getResponse()->getContent()]);
        $data = $this->api('PATCH', '/api/admin/delivery/cities/city-foumban', $token, ['fee' => 1]);
        self::assertSame([404, 'CITY_NOT_FOUND'], [$this->httpStatus(), $data['code']]);
    }

    public function testShopCitiesAndCitiesWithAgenciesAreProtected(): void
    {
        $token = $this->owner();
        foreach ([['name' => 'Yaoundé Centre'], ['isActive' => false]] as $body) {
            $data = $this->api('PATCH', '/api/admin/delivery/cities/city-yde', $token, $body);
            self::assertSame([422, 'VALIDATION_ERROR'], [$this->httpStatus(), $data['code']], json_encode($body));
        }
        $data = $this->api('PATCH', '/api/admin/delivery/cities/city-yde', $token, ['name' => 'YAOUNDE', 'estimatedDelay' => 'Le jour même']);
        self::assertSame([200, 'Yaoundé', 'Le jour même'], [$this->httpStatus(), $data['city']['name'], $data['city']['estimatedDelay']], 'même ville écrite autrement : acceptée, nom gardé tel quel');

        $data = $this->api('DELETE', '/api/admin/delivery/cities/city-dla', $token);
        self::assertSame([409, 'CITY_IS_SHOP'], [$this->httpStatus(), $data['code']]);
        $data = $this->api('DELETE', '/api/admin/delivery/cities/city-baf', $token);
        self::assertSame([409, 'CITY_HAS_AGENCIES', 'Supprimez ou déplacez d’abord ses 4 agences de Bafoussam.'], [$this->httpStatus(), $data['code'], $data['message']]);
    }

    public function testAgenciesWithTheirOwnPriceAndDepartureShop(): void
    {
        $token = $this->owner();
        $list = $this->api('GET', '/api/admin/delivery/agencies', $token)['agencies'];
        self::assertCount(16, $list, 'masquées comprises');
        $mta = array_column($list, null, 'id')['ag-dla-mta'];
        self::assertSame(['id', 'cityId', 'city', 'name', 'address', 'phone', 'openingHours', 'fee', 'effectiveFee', 'departureCity', 'isActive', 'position'], array_keys($mta));
        self::assertSame(['Douala', false, null], [$mta['city'], $mta['isActive'], $mta['departureCity']]);

        // Nouvelle agence à Bamenda, seulement depuis Yaoundé : la ville apparaît pour les clients de Yaoundé.
        self::assertArrayNotHasKey('Bamenda', array_column($this->getJson('/api/delivery/cities?from=Yaound%C3%A9'), null, 'name'));
        $data = $this->api('POST', '/api/admin/delivery/agencies', $token, ['cityId' => 'city-bam', 'name' => 'Touristique Express', 'address' => 'Commercial Avenue', 'phone' => '650 62 38 83', 'openingHours' => 'Tous les jours', 'fee' => 3500, 'departureCity' => 'Yaoundé']);
        self::assertSame(201, $this->httpStatus(), json_encode($data));
        self::assertSame(['Bamenda', '+237 650 62 38 83', 3500, 3500, 'Yaoundé', true], [$data['agency']['city'], $data['agency']['phone'], $data['agency']['fee'], $data['agency']['effectiveFee'], $data['agency']['departureCity'], $data['agency']['isActive']]);
        $id = $data['agency']['id'];
        self::assertSame(3500, array_column($this->getJson('/api/delivery/cities?from=Yaound%C3%A9'), null, 'name')['Bamenda']['fee'] ?? null);
        self::assertArrayNotHasKey('Bamenda', array_column($this->getJson('/api/delivery/cities?from=Douala'), null, 'name'), 'pas depuis Douala');

        // Modification partielle : le prix, la boutique de départ et l'état restent.
        $data = $this->api('PATCH', "/api/admin/delivery/agencies/$id", $token, ['name' => 'Touristique Express Bamenda', 'address' => 'Commercial Avenue, face poste']);
        self::assertSame(['Touristique Express Bamenda', 3500, 'Yaoundé', true], [$data['agency']['name'], $data['agency']['fee'], $data['agency']['departureCity'], $data['agency']['isActive']]);

        // Masquée : plus proposée ; prix vide = prix de la ville.
        $data = $this->api('PATCH', "/api/admin/delivery/agencies/$id", $token, ['isActive' => false, 'fee' => null, 'departureCity' => null]);
        self::assertSame([null, 3500, null, false], [$data['agency']['fee'], $data['agency']['effectiveFee'], $data['agency']['departureCity'], $data['agency']['isActive']]);
        self::assertArrayNotHasKey('Bamenda', array_column($this->getJson('/api/delivery/cities?from=Yaound%C3%A9'), null, 'name'));

        // Agence connue activée avec son prix : proposée aux clients de Douala... vers Douala ? Non : Douala est
        // la ville de la boutique (livraison à domicile) ; depuis Yaoundé, oui.
        $this->api('PATCH', '/api/admin/delivery/agencies/ag-dla-mta', $token, ['fee' => 2000, 'isActive' => true]);
        $agencies = array_column($this->getJson('/api/delivery/agencies?city=Douala&from=Yaound%C3%A9'), 'fee', 'id');
        self::assertSame(2000, $agencies['ag-dla-mta'] ?? null);

        foreach ([
            ['cityId' => 'city-inconnue', 'name' => 'Agence'], ['cityId' => 'city-bam', 'name' => 'A'], ['cityId' => 'city-bam', 'name' => 'Agence', 'phone' => '12345'],
            ['cityId' => 'city-bam', 'name' => 'Agence', 'fee' => -1], ['cityId' => 'city-bam', 'name' => 'Agence', 'departureCity' => 'Kribi'],
            ['cityId' => 'city-bam', 'name' => 'touristique express bamenda'], ['cityId' => 'city-bam', 'name' => 'Agence', 'isActive' => 'oui'],
        ] as $body) {
            $result = $this->api('POST', '/api/admin/delivery/agencies', $token, $body);
            self::assertSame([422, 'VALIDATION_ERROR'], [$this->httpStatus(), $result['code']], json_encode($body));
        }

        $this->client->request('DELETE', "/api/admin/delivery/agencies/$id", [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertSame(204, $this->httpStatus());
        $data = $this->api('DELETE', "/api/admin/delivery/agencies/$id", $token);
        self::assertSame([404, 'AGENCY_NOT_FOUND'], [$this->httpStatus(), $data['code']]);
        $data = $this->api('DELETE', '/api/admin/delivery/cities/city-bam', $token);
        self::assertSame(204, $this->httpStatus(), 'plus d’agence : la ville peut être supprimée');
    }
}
