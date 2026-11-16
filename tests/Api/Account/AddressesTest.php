<?php

namespace App\Tests\Api\Account;

use App\Account\AddressBook;
use App\Tests\AccountTestCase;

/**
 * Carnet d'adresses : GET, POST, PATCH, DELETE /api/me/addresses (contrat §5.3 à §5.6).
 */
final class AddressesTest extends AccountTestCase
{
    private const HOME = [
        'label'         => 'Maison',
        'city'          => 'Yaoundé',
        'district'      => 'Bastos',
        'landmark'      => 'Derrière l’ambassade, portail noir',
        'receptionMode' => 'delivery',
        'agencyId'      => null,
    ];

    public function testFirstAddressIsTheDefaultOne(): void
    {
        $token = $this->loginNamed();
        self::assertSame([], $this->api('GET', '/api/me/addresses', $token));
        self::assertStringContainsString('no-cache', (string) $this->header('Cache-Control'));

        $data = $this->api('POST', '/api/me/addresses', $token, self::HOME);
        self::assertSame(201, $this->httpStatus());
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));
        self::assertMatchesRegularExpression('/^addr-[0-9a-f]{16}$/', $data['id']);
        self::assertSame([
            'id'             => $data['id'],
            'label'          => 'Maison',
            'city'           => 'Yaoundé',
            'district'       => 'Bastos',
            'quarter'        => 'Bastos',
            'landmark'       => 'Derrière l’ambassade, portail noir',
            'details'        => 'Derrière l’ambassade, portail noir',
            'receptionMode'  => 'delivery',
            'recipientName'  => 'Junior Mbarga',
            'recipientPhone' => self::PHONE,
            'phone'          => self::PHONE,
            'isDefault'      => true,
        ], $data);

        self::assertSame([$data], $this->api('GET', '/api/me/addresses', $token));
    }

    public function testAgencyAddressForANonLocalCity(): void
    {
        $token = $this->loginNamed();
        $data = $this->api('POST', '/api/me/addresses', $token, [
            'label'          => 'Autre',
            'city'           => 'douala',
            'district'       => 'Douala',
            'landmark'       => 'Agence Express Akwa',
            'receptionMode'  => 'delivery',
            'agencyId'       => 'ag-dla-1',
            'recipientName'  => 'Paul Essomba',
            'recipientPhone' => '677 88 99 00',
        ]);

        self::assertSame(201, $this->httpStatus());
        self::assertSame(['Douala', 'ag-dla-1', 'Paul Essomba', '+237677889900'], [$data['city'], $data['agencyId'], $data['recipientName'], $data['recipientPhone']]);

        // Agence d'une autre ville, ou pas d'agence : refusé.
        $data = $this->api('POST', '/api/me/addresses', $token, ['label' => 'Autre', 'city' => 'Douala', 'district' => 'Douala', 'landmark' => 'x', 'receptionMode' => 'delivery', 'agencyId' => 'ag-yde-1']);
        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('agencyId', $data['details']);
        $data = $this->api('POST', '/api/me/addresses', $token, ['label' => 'Autre', 'city' => 'Douala', 'district' => 'Douala', 'landmark' => 'x', 'receptionMode' => 'delivery']);
        self::assertArrayHasKey('agencyId', $data['details']);
    }

    public function testAgencyNameAndCityAreFilledInWhenMissing(): void
    {
        $token = $this->loginNamed();
        $agencies = $this->getJson('/api/delivery/agencies?city=Bafoussam');
        $data = $this->api('POST', '/api/me/addresses', $token, ['label' => 'Bureau', 'city' => 'Bafoussam', 'agencyId' => $agencies[0]['id']]);

        self::assertSame(201, $this->httpStatus());
        self::assertSame(['Bafoussam', $agencies[0]['name']], [$data['district'], $data['landmark']]);
    }

    public function testValidation(): void
    {
        $token = $this->loginNamed();

        $data = $this->api('POST', '/api/me/addresses', $token, ['label' => 'Chalet', 'city' => 'Yaoundé', 'district' => 'B', 'landmark' => 'abc', 'receptionMode' => 'livraison', 'recipientPhone' => '612']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('VALIDATION_ERROR', $data['code']);
        self::assertSame(['label', 'receptionMode', 'district', 'landmark', 'recipientPhone'], array_keys($data['details']));

        $data = $this->api('POST', '/api/me/addresses', $token, ['label' => 'Maison', 'city' => 'Paris', 'district' => 'Bastos', 'landmark' => 'Tour Eiffel']);
        self::assertSame(['city'], array_keys($data['details']));

        $this->api('POST', '/api/me/addresses', $token, 'pas du json');
        self::assertSame(422, $this->httpStatus());

        self::assertSame([], $this->api('GET', '/api/me/addresses', $token));
    }

    public function testSetAsDefaultMovesTheAddressFirst(): void
    {
        $token = $this->loginNamed();
        $first = $this->api('POST', '/api/me/addresses', $token, self::HOME);
        $second = $this->api('POST', '/api/me/addresses', $token, ['label' => 'Bureau', 'district' => 'Omnisports', 'landmark' => 'Entrée du stade'] + self::HOME);
        $third = $this->api('POST', '/api/me/addresses', $token, ['label' => 'Autre', 'district' => 'Mvog-Mbi', 'landmark' => 'Carrefour Mvog-Mbi'] + self::HOME);
        self::assertFalse($second['isDefault']);

        $data = $this->api('PATCH', '/api/me/addresses/' . $third['id'], $token, ['isDefault' => true]);
        self::assertSame(200, $this->httpStatus());
        self::assertTrue($data['isDefault']);
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));

        $list = $this->api('GET', '/api/me/addresses', $token);
        self::assertSame([$third['id'], $first['id'], $second['id']], array_column($list, 'id'));
        self::assertSame([true, false, false], array_column($list, 'isDefault'));

        // Créée « par défaut » : passe en tête.
        $fourth = $this->api('POST', '/api/me/addresses', $token, ['district' => 'Essos', 'landmark' => 'Marché d’Essos', 'isDefault' => true] + self::HOME);
        self::assertSame([$fourth['id'], $first['id'], $second['id'], $third['id']], array_column($this->api('GET', '/api/me/addresses', $token), 'id'));
    }

    public function testPatchWithNullAgencySwitchesToHomeDelivery(): void
    {
        $token = $this->loginNamed();
        $address = $this->api('POST', '/api/me/addresses', $token, ['label' => 'Autre', 'city' => 'Douala', 'district' => 'Douala', 'landmark' => 'Agence', 'receptionMode' => 'delivery', 'agencyId' => 'ag-dla-1']);

        $data = $this->api('PATCH', '/api/me/addresses/' . $address['id'], $token, ['city' => 'Yaoundé', 'district' => 'Bastos', 'landmark' => 'Immeuble Mirage 3e étage', 'agencyId' => null]);
        self::assertSame(200, $this->httpStatus());
        self::assertArrayNotHasKey('agencyId', $data);
        self::assertSame(['Yaoundé', 'Bastos'], [$data['city'], $data['district']]);

        // Un champ absent ne change pas.
        $data = $this->api('PATCH', '/api/me/addresses/' . $address['id'], $token, ['label' => 'Bureau']);
        self::assertSame(['Bureau', 'Bastos', 'Immeuble Mirage 3e étage'], [$data['label'], $data['district'], $data['landmark']]);

        // Passage à une ville par agence sans agence : refusé.
        $data = $this->api('PATCH', '/api/me/addresses/' . $address['id'], $token, ['city' => 'Douala']);
        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('agencyId', $data['details']);
    }

    public function testDeletingTheDefaultPromotesTheFirstRemaining(): void
    {
        $token = $this->loginNamed();
        $first = $this->api('POST', '/api/me/addresses', $token, self::HOME);
        $second = $this->api('POST', '/api/me/addresses', $token, ['district' => 'Omnisports', 'landmark' => 'Entrée du stade'] + self::HOME);
        $third = $this->api('POST', '/api/me/addresses', $token, ['district' => 'Essos', 'landmark' => 'Marché d’Essos'] + self::HOME);

        $this->client->request('DELETE', '/api/me/addresses/' . $first['id'], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertSame(204, $this->httpStatus());

        $list = $this->api('GET', '/api/me/addresses', $token);
        self::assertSame([$second['id'], $third['id']], array_column($list, 'id'));
        self::assertSame([true, false], array_column($list, 'isDefault'));

        $data = $this->api('DELETE', '/api/me/addresses/' . $first['id'], $token);
        self::assertSame(404, $this->httpStatus());
        self::assertSame(['code' => 'ADDRESS_NOT_FOUND', 'message' => 'Cette adresse est introuvable.'], $data);
    }

    public function testAnotherClientsAddressIsNotFound(): void
    {
        $tokenA = $this->loginNamed();
        $address = $this->api('POST', '/api/me/addresses', $tokenA, self::HOME);

        $tokenB = $this->loginNamed('+237690000002', 'Paul');
        self::assertSame([], $this->api('GET', '/api/me/addresses', $tokenB));
        self::assertSame('ADDRESS_NOT_FOUND', $this->api('PATCH', '/api/me/addresses/' . $address['id'], $tokenB, ['isDefault' => true])['code']);
        self::assertSame(404, $this->httpStatus());
        self::assertSame('ADDRESS_NOT_FOUND', $this->api('DELETE', '/api/me/addresses/' . $address['id'], $tokenB)['code']);

        self::assertCount(1, $this->api('GET', '/api/me/addresses', $tokenA));
    }

    public function testDoubleSubmitDoesNotCreateADuplicate(): void
    {
        $token = $this->loginNamed();
        $first = $this->api('POST', '/api/me/addresses', $token, self::HOME);
        $again = $this->api('POST', '/api/me/addresses', $token, self::HOME);

        self::assertSame(200, $this->httpStatus());
        self::assertSame($first['id'], $again['id']);
        self::assertCount(1, $this->api('GET', '/api/me/addresses', $token));
    }

    public function testAddressBookIsLimited(): void
    {
        $token = $this->loginNamed();
        for ($i = 1; $i <= AddressBook::MAX_ADDRESSES; ++$i) {
            $this->api('POST', '/api/me/addresses', $token, ['landmark' => "Point de repère n°$i"] + self::HOME);
            self::assertSame(201, $this->httpStatus());
        }

        $data = $this->api('POST', '/api/me/addresses', $token, ['landmark' => 'Une adresse de trop'] + self::HOME);
        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('addresses', $data['details']);
    }

    public function testAddressesRequireLogin(): void
    {
        $this->api('GET', '/api/me/addresses', null);
        self::assertSame(401, $this->httpStatus());
        $this->api('POST', '/api/me/addresses', null, self::HOME);
        self::assertSame(401, $this->httpStatus());
    }

    public function testShortProfileNameIsAcceptedAsRecipient(): void
    {
        // Prénom d'une lettre, sans nom : le front l'envoie comme destinataire (« pour moi »).
        $token = $this->loginNamed(self::PHONE, 'J', null);
        $data = $this->api('POST', '/api/me/addresses', $token, ['recipientName' => 'J', 'recipientPhone' => self::PHONE] + self::HOME);

        self::assertSame(201, $this->httpStatus());
        self::assertSame('J', $data['recipientName']);
    }

    public function testExactlyOneDefaultAfterEveryChange(): void
    {
        $token = $this->loginNamed();
        $ids = [];
        foreach (['Bastos', 'Omnisports', 'Essos', 'Mvog-Mbi'] as $district) {
            $ids[] = $this->api('POST', '/api/me/addresses', $token, ['district' => $district, 'landmark' => "Carrefour $district"] + self::HOME)['id'];
        }
        $assertOneDefault = function () use ($token): void {
            $list = $this->api('GET', '/api/me/addresses', $token);
            self::assertCount(1, array_filter(array_column($list, 'isDefault')), 'une seule adresse par défaut');
            self::assertTrue($list[0]['isDefault'], 'adresse par défaut en tête');
        };

        $this->api('PATCH', '/api/me/addresses/' . $ids[2], $token, ['isDefault' => true]);
        $assertOneDefault();
        $this->api('PATCH', '/api/me/addresses/' . $ids[2], $token, ['isDefault' => false]);
        $assertOneDefault();
        $this->api('DELETE', '/api/me/addresses/' . $ids[2], $token);
        $assertOneDefault();
        $this->api('DELETE', '/api/me/addresses/' . $ids[1], $token);
        $assertOneDefault();
        self::assertSame([$ids[0], $ids[3]], array_column($this->api('GET', '/api/me/addresses', $token), 'id'));
    }
}
