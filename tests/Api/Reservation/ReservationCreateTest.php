<?php

namespace App\Tests\Api\Reservation;

use App\Api\ApiDate;
use App\Tests\ReservationTestCase;

/**
 * POST /api/reservations : retrait dans chaque ville, livraison à domicile ou par agence (prix de l'agence),
 * un panier = une ville, stock retiré sous verrou, idempotence.
 */
final class ReservationCreateTest extends ReservationTestCase
{
    public function testPickupInYaoundeLikeTheFrontSendsIt(): void
    {
        $token = $this->loginNamed();
        $before = (int) $this->em()->getConnection()->fetchOne("SELECT reservations_count FROM products WHERE id = 'prod-01'");

        $data = $this->reserve($token, $this->pickup(quantity: 2));

        self::assertSame(201, $this->httpStatus());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertMatchesRegularExpression('/^RES-\d{6}$/', $data['ref']);
        self::assertSame(['reserved', 'pickup', 'Yaoundé', 'Yaoundé'], [$data['status'], $data['receptionMode'], $data['city'], $data['shopCity']]);
        self::assertSame([90000, 0, 90000], [$data['subtotal'], $data['deliveryFee'], $data['total']]);
        self::assertSame(['subtotal' => 90000, 'deliveryFee' => 0, 'total' => 90000], $data['amount']);
        self::assertSame('upfront', $data['deliveryFeePayment']);
        self::assertSame(ApiDate::format($this->clock->now()->modify('+24 hours')), $data['expiresAt']);
        self::assertSame(ApiDate::format($this->clock->now()), $data['serverTime']);
        self::assertTrue($data['cancellable']);
        self::assertSame([], $data['packagePhotos']);
        self::assertSame('Yaoundé', $data['pickupShop']['city']);
        self::assertArrayNotHasKey('homeDeliveryFee', $data['pickupShop']);
        self::assertSame(['firstName' => 'Junior', 'lastName' => 'Mbarga', 'phone' => self::PHONE], $data['customer']);
        self::assertSame([['status' => 'reserved', 'at' => $data['createdAt'], 'note' => 'Réservation enregistrée : retrait à la boutique de Yaoundé.', 'actor' => 'client']], $data['timeline']);

        $item = $data['items'][0];
        self::assertSame(['prod-01', 'hoodie-barbele-noir', 'prod-01-noir-s', 'S', 'noir', 45000, 2, 'Yaoundé'], [
            $item['productId'], $item['slug'], $item['variantId'], $item['size'], $item['colorCode'], $item['unitPrice'], $item['quantity'], $item['city'],
        ]);
        self::assertNotSame('', $item['name']);
        self::assertNotSame('', $item['colorName']);
        self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $item['colorHex']);
        self::assertNotSame('', $item['image']);

        // Stock retiré, popularité comptée ; la fiche se relit telle quelle.
        self::assertSame(2, $this->stock(self::YDE_VARIANT));
        self::assertSame($before + 1, (int) $this->em()->getConnection()->fetchOne("SELECT reservations_count FROM products WHERE id = 'prod-01'"));
        $again = $this->api('GET', '/api/reservations/' . strtolower($data['ref']), $token);
        self::assertSame(200, $this->httpStatus());
        unset($again['serverTime'], $data['serverTime']);
        self::assertSame($data, $again);
    }

    public function testPickupInDoualaUsesTheCityOfTheArticles(): void
    {
        $data = $this->reserve($this->loginNamed(), $this->pickup(self::DLA_VARIANT, 3));

        self::assertSame(201, $this->httpStatus());
        self::assertSame(['Douala', 'Douala', 54000], [$data['shopCity'], $data['city'], $data['total']]);
        self::assertSame('Douala', $data['pickupShop']['city']);
        self::assertStringContainsString('Douala', $data['timeline'][0]['note']);
        self::assertSame(5, $this->stock(self::DLA_VARIANT));
    }

    public function testProfileWithoutNameIsCompletedByTheReservation(): void
    {
        // Client qui a choisi « Plus tard » à la connexion : le nom de sa réservation remplit son profil.
        $token = $this->login()['token'];
        $this->reserve($token, $this->pickup(override: ['firstName' => 'Awa', 'lastName' => 'Ndzie']));
        self::assertSame(201, $this->httpStatus());
        $me = $this->api('GET', '/api/me', $token);
        self::assertSame(['Awa', 'Ndzie'], [$me['firstName'] ?? null, $me['lastName'] ?? null]);

        // Un nom déjà enregistré n'est jamais remplacé.
        $this->reserve($token, $this->pickup(override: ['firstName' => 'Paul', 'lastName' => 'Essomba']));
        self::assertSame(201, $this->httpStatus());
        self::assertSame('Awa', $this->api('GET', '/api/me', $token)['firstName'] ?? null);
    }

    public function testHomeDeliveryInsideTheShopCity(): void
    {
        $token = $this->loginNamed();
        $body = $this->pickup(override: ['receptionMode' => 'delivery', 'city' => 'Yaoundé', 'district' => 'Bastos', 'landmark' => 'Portail noir après la pharmacie']);

        $data = $this->reserve($token, $body);
        self::assertSame(201, $this->httpStatus());
        self::assertSame([45000, 1500, 46500], [$data['subtotal'], $data['deliveryFee'], $data['total']]);
        self::assertSame(['district' => 'Bastos', 'landmark' => 'Portail noir après la pharmacie'], $data['address']);
        self::assertArrayNotHasKey('agency', $data);
        self::assertArrayNotHasKey('pickupShop', $data);
        self::assertSame(['city' => 'Yaoundé', 'quarter' => 'Bastos', 'district' => 'Bastos', 'landmark' => 'Portail noir après la pharmacie', 'recipientName' => 'Junior Mbarga', 'recipientPhone' => self::PHONE], $data['deliveryAddress']);

        // Article de Douala livré à Douala : à domicile aussi (prix de la boutique de Douala).
        $data = $this->reserve($token, $this->pickup(self::DLA_VARIANT, override: ['receptionMode' => 'delivery', 'city' => 'Douala', 'district' => 'Akwa', 'landmark' => 'Face à la station Total']));
        self::assertSame(201, $this->httpStatus());
        self::assertSame([18000, 1500, 'Douala'], [$data['subtotal'], $data['deliveryFee'], $data['city']]);

        $data = $this->reserve($token, $this->pickup(override: ['receptionMode' => 'delivery', 'city' => 'Yaoundé', 'district' => 'Bastos', 'landmark' => 'Ici']));
        self::assertSame(422, $this->httpStatus());
        self::assertSame(['VALIDATION_ERROR', 'Indiquez un point de repère précis (5 caractères au moins).'], [$data['code'], $data['message']]);
    }

    public function testAgencyDeliveryUsesThePriceOfTheAgency(): void
    {
        $token = $this->loginNamed();
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE agencies SET fee = 2200 WHERE id = 'ag-dla-travel-lines'");

        $data = $this->reserve($token, $this->pickup(override: ['receptionMode' => 'delivery', 'city' => 'Douala', 'agencyId' => 'ag-dla-travel-lines']));
        self::assertSame(201, $this->httpStatus());
        self::assertSame([2200, 47200, 'Douala'], [$data['deliveryFee'], $data['total'], $data['city']]);
        self::assertSame(['ag-dla-travel-lines', 2200, 'Douala'], [$data['agency']['id'], $data['agency']['fee'], $data['agency']['city']]);
        self::assertSame('ag-dla-travel-lines', $data['deliveryAddress']['agencyId']);
        self::assertSame($data['agency']['name'], $data['deliveryAddress']['agencyName']);
        self::assertArrayNotHasKey('address', $data);

        // Article de Douala envoyé à Yaoundé : agence de Yaoundé.
        $data = $this->reserve($token, $this->pickup(self::DLA_VARIANT, override: ['receptionMode' => 'delivery', 'city' => 'Yaoundé', 'agencyId' => 'ag-yde-touristique']));
        self::assertSame(201, $this->httpStatus());
        self::assertSame(['Douala', 'Yaoundé', 3000], [$data['shopCity'], $data['city'], $data['deliveryFee']]);

        // Agence d'une autre ville, agence sans prix confirmé (cachée), agence qui ne part que de Douala, agence
        // absente : refusées.
        $connection->executeStatement("UPDATE agencies SET departure_city = 'Douala' WHERE id = 'ag-baf-touristique'");
        foreach ([
            ['Douala', 'ag-baf-touristique', 'Choisissez une agence de Douala dans la liste.'],
            ['Douala', 'ag-dla-mta', 'Choisissez une agence de Douala dans la liste.'],
            ['Bafoussam', 'ag-baf-touristique', 'Choisissez une agence de Bafoussam dans la liste.'],
            ['Bafoussam', null, 'Choisissez une agence de Bafoussam dans la liste.'],
            ['Paris', 'ag-dla-travel-lines', 'Choisissez une ville de livraison de la liste.'],
        ] as [$city, $agencyId, $message]) {
            $data = $this->reserve($token, $this->pickup(override: array_filter(['receptionMode' => 'delivery', 'city' => $city, 'agencyId' => $agencyId])));
            self::assertSame(422, $this->httpStatus(), $message);
            self::assertSame($message, $data['message']);
        }
        self::assertSame(3, $this->stock(self::YDE_VARIANT), 'refus : stock intact');
    }

    public function testOneCityPerCart(): void
    {
        $data = $this->reserve($this->loginNamed(), $this->pickup(override: ['items' => [
            ['productId' => 'prod-01', 'variantId' => self::YDE_VARIANT, 'quantity' => 1],
            ['productId' => 'prod-02', 'variantId' => self::DLA_VARIANT, 'quantity' => 1],
        ]]));

        self::assertSame(422, $this->httpStatus(), 'jamais 409 : le front le prendrait pour un conflit de stock');
        self::assertSame('MIXED_CITIES', $data['code']);
        self::assertSame('Votre panier contient des articles de Yaoundé et de Douala. Réservez les articles d’une seule ville à la fois.', $data['message']);
        self::assertSame(['Yaoundé', 'Douala'], $data['details']['cities']);
        self::assertSame([['variantId' => self::YDE_VARIANT, 'city' => 'Yaoundé'], ['variantId' => self::DLA_VARIANT, 'city' => 'Douala']], $data['details']['items']);
        self::assertSame([4, 8], [$this->stock(self::YDE_VARIANT), $this->stock(self::DLA_VARIANT)]);
    }

    public function testStockConflictGivesTheCartLines(): void
    {
        $token = $this->loginNamed();

        $data = $this->reserve($token, $this->pickup(quantity: 5));
        self::assertSame(409, $this->httpStatus());
        self::assertSame('STOCK_CONFLICT', $data['code']);
        self::assertSame([['variantId' => self::YDE_VARIANT, 'status' => 'reduced', 'availableQuantity' => 4, 'unitPrice' => 45000, 'city' => 'Yaoundé']], $data['details']['items']);

        // Lignes en double : additionnées (2 + 3 > 4).
        $data = $this->reserve($token, $this->pickup(override: ['items' => [
            ['productId' => 'prod-01', 'variantId' => self::YDE_VARIANT, 'quantity' => 2],
            ['productId' => 'prod-01', 'variantId' => self::YDE_VARIANT, 'quantity' => 3],
        ]]));
        self::assertSame(409, $this->httpStatus());

        $data = $this->reserve($token, $this->pickup(override: ['items' => [
            ['productId' => 'prod-01', 'variantId' => self::YDE_VARIANT, 'quantity' => 1],
            ['productId' => 'x', 'variantId' => 'variante-disparue', 'quantity' => 1],
        ]]));
        self::assertSame(409, $this->httpStatus());
        self::assertSame(['ok', 'unavailable'], array_column($data['details']['items'], 'status'));
        self::assertSame(4, $this->stock(self::YDE_VARIANT));

        // La dernière pièce : le premier l'a, le second reçoit le stock à jour.
        $this->reserve($token, $this->pickup(quantity: 4));
        self::assertSame(201, $this->httpStatus());
        $data = $this->reserve($this->loginNamed('+237690000002', 'Paul'), $this->pickup(quantity: 1));
        self::assertSame(409, $this->httpStatus());
        self::assertSame(['unavailable', 0], [$data['details']['items'][0]['status'], $data['details']['items'][0]['availableQuantity']]);
    }

    public function testSameRequestSentTwiceGivesTheSameReservation(): void
    {
        $token = $this->loginNamed();
        $first = $this->reserve($token, $this->pickup(), 'idem-1731700000000-a1b2c3');
        $second = $this->reserve($token, $this->pickup(), 'idem-1731700000000-a1b2c3');

        self::assertSame(201, $this->httpStatus());
        self::assertSame($first['ref'], $second['ref']);
        self::assertSame(3, $this->stock(self::YDE_VARIANT), 'stock retiré une seule fois');

        $data = $this->reserve($token, $this->pickup(quantity: 2), 'idem-1731700000000-a1b2c3');
        self::assertSame(422, $this->httpStatus());
        self::assertSame('IDEMPOTENCY_KEY_REUSED', $data['code']);

        // Même clé pour un autre client : une autre réservation.
        $other = $this->reserve($this->loginNamed('+237690000002', 'Paul'), $this->pickup(), 'idem-1731700000000-a1b2c3');
        self::assertSame(201, $this->httpStatus());
        self::assertNotSame($first['ref'], $other['ref']);

        foreach (['', 'court', 'clé avec espaces !'] as $key) {
            $this->reserve($token, $this->pickup(), $key);
            self::assertSame(422, $this->httpStatus(), $key);
        }
    }

    public function testValidationMessages(): void
    {
        $token = $this->loginNamed();
        foreach ([
            [['items' => []], 'Votre panier est vide.'],
            [['items' => [['variantId' => self::YDE_VARIANT, 'quantity' => 0]]], 'Une quantité du panier est invalide.'],
            [['receptionMode' => 'drone'], 'Choisissez le retrait en boutique ou la livraison.'],
            [['firstName' => '  '], 'Indiquez votre prénom.'],
            [['notes' => str_repeat('a', 201)], 'La remarque ne doit pas dépasser 200 caractères.'],
            [['receptionMode' => 'delivery', 'city' => 'Douala', 'agencyId' => 'ag-dla-travel-lines', 'recipientType' => 'other', 'recipientName' => 'Paul Essomba', 'recipientPhone' => '0612'], 'Numéro du destinataire invalide : un mobile camerounais (6XX XX XX XX).'],
            [['receptionMode' => 'delivery', 'city' => 'Douala', 'agencyId' => 'ag-dla-travel-lines', 'recipientType' => 'other', 'recipientName' => '', 'recipientPhone' => '677889900'], 'Indiquez le nom de la personne qui récupérera le colis à l’agence.'],
        ] as [$override, $message]) {
            $data = $this->reserve($token, $this->pickup(override: $override));
            self::assertSame(422, $this->httpStatus(), $message);
            self::assertSame($message, $data['message']);
        }

        // Le téléphone envoyé ne compte pas : c'est celui du compte. Un ancien choix « quelqu'un d'autre » laissé
        // dans le formulaire (champs cachés hors envoi par agence) ne bloque pas un retrait.
        $data = $this->reserve($token, $this->pickup(override: ['phone' => '+237699999999', 'lastName' => '', 'notes' => '  Appeler avant  ', 'recipientType' => 'other', 'recipientName' => '', 'recipientPhone' => '06']));
        self::assertSame(201, $this->httpStatus());
        self::assertSame([self::PHONE, '', 'Appeler avant'], [$data['customer']['phone'], $data['customer']['lastName'], $data['notes']]);

        // Envoi par agence pour quelqu'un d'autre : son nom et son numéro sont gardés.
        $data = $this->reserve($token, $this->pickup(override: ['receptionMode' => 'delivery', 'city' => 'Douala', 'agencyId' => 'ag-dla-travel-lines', 'recipientType' => 'other', 'recipientName' => 'Paul Essomba', 'recipientPhone' => '677 88 99 00']));
        self::assertSame(201, $this->httpStatus());
        self::assertSame(['Paul Essomba', '+237677889900'], [$data['deliveryAddress']['recipientName'], $data['deliveryAddress']['recipientPhone']]);

        // Prénom et nom très longs : le nom du destinataire (120 caractères au plus) est raccourci, sans erreur.
        $data = $this->reserve($token, $this->pickup(override: ['firstName' => str_repeat('é', 70), 'lastName' => str_repeat('a', 70), 'receptionMode' => 'delivery', 'city' => 'Yaoundé', 'district' => 'Bastos', 'landmark' => 'Portail noir']));
        self::assertSame(201, $this->httpStatus());
        self::assertSame(120, mb_strlen($data['deliveryAddress']['recipientName']));

        $this->reserve('jeton-invalide', $this->pickup());
        self::assertSame(401, $this->httpStatus());
    }
}
