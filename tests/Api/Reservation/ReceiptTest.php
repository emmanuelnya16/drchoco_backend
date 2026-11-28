<?php

namespace App\Tests\Api\Reservation;

use App\Tests\AdminReservationTestCase;
use App\Tests\Api\Push\PushSubscriptionTest;
use App\Tests\Fake\RecordingPushTransport;

/**
 * « J'ai bien reçu mon colis » : le client confirme la réception, la date est notée, le statut ne change pas (la boutique
 * confirme elle-même le retrait ou la livraison) ; l'équipe et le vendeur concerné sont prévenus.
 */
final class ReceiptTest extends AdminReservationTestCase
{
    private const OWNER_DEVICE = 'https://fcm.googleapis.com/fcm/send/proprietaire';
    private const SELLER_YDE_DEVICE = 'https://fcm.googleapis.com/fcm/send/vendeur-yaounde';
    private const SELLER_DLA_DEVICE = 'https://fcm.googleapis.com/fcm/send/vendeur-douala';

    private function transport(): RecordingPushTransport
    {
        return static::getContainer()->get('test.push_transport');
    }

    private function subscribe(string $token, string $endpoint): void
    {
        $this->client->request('POST', '/api/admin/push/subscribe', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(PushSubscriptionTest::subscription($endpoint)));
        self::assertSame(201, $this->httpStatus(), (string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, mixed> */
    private function received(string $token, string $ref): array
    {
        return $this->adminApi('POST', '/api/reservations/' . $ref . '/received', $token);
    }

    public function testTheClientConfirmsItsPickupAndTheShopStillClosesIt(): void
    {
        ['token' => $client, 'ref' => $ref] = $this->reservationWithProof();
        $owner = $this->ownerToken();
        $this->subscribe($owner, self::OWNER_DEVICE);
        $this->subscribe($this->sellerToken('Yaoundé'), self::SELLER_YDE_DEVICE);
        $this->subscribe($this->sellerToken('Douala'), self::SELLER_DLA_DEVICE);

        // Pas encore payé ni prêt : rien à confirmer.
        self::assertArrayNotHasKey('canConfirmReceipt', $this->clientDetail($client, $ref));
        $data = $this->received($client, $ref);
        self::assertSame([422, 'RECEIPT_NOT_ALLOWED', 'pending_validation'], [$this->httpStatus(), $data['code'], $data['details']['status']]);

        $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/validate-payment', $owner, []);
        self::assertSame(200, $this->httpStatus());
        $this->received($client, $ref);
        self::assertSame(422, $this->httpStatus(), 'paiement validé, colis pas encore prêt');

        $this->packageReady($owner, $ref, [$this->photo('face')]);
        self::assertSame(200, $this->httpStatus());
        $detail = $this->clientDetail($client, $ref);
        self::assertSame(['ready_for_pickup', true], [$detail['status'], $detail['canConfirmReceipt'] ?? null]);
        self::assertArrayNotHasKey('customerReceivedAt', $detail);

        // Le client confirme : date notée, statut inchangé, boutique prévenue (propriétaire et vendeur de la ville).
        $this->transport()->reset();
        $data = $this->received($client, $ref);
        self::assertSame(200, $this->httpStatus(), json_encode($data));
        self::assertSame('ready_for_pickup', $data['status']);
        $at = $data['reservation']['customerReceivedAt'] ?? null;
        self::assertNotNull($at);
        self::assertArrayNotHasKey('canConfirmReceipt', $data['reservation']);
        self::assertSame('ready_for_pickup', end($data['reservation']['timeline'])['status'], 'aucune étape ajoutée');

        $sent = array_column($this->transport()->sentTo(self::OWNER_DEVICE), 'payload');
        self::assertCount(1, $sent);
        self::assertSame(['type' => 'package_received', 'title' => 'Colis reçu par le client', 'body' => "$ref · Junior Mbarga confirme avoir reçu son colis : confirmez le retrait.", 'url' => "/admin/reservations/$ref", 'ref' => $ref, 'tag' => "admin-$ref"], $sent[0]);
        self::assertCount(1, $this->transport()->sentTo(self::SELLER_YDE_DEVICE), 'vendeur de la ville des articles');
        self::assertSame([], $this->transport()->sentTo(self::SELLER_DLA_DEVICE), 'pas l’autre ville');

        // Deuxième appui : sans effet, même date, aucune nouvelle notification.
        $this->transport()->reset();
        $again = $this->received($client, $ref);
        self::assertSame([200, $at], [$this->httpStatus(), $again['reservation']['customerReceivedAt']]);
        self::assertSame([], $this->transport()->sent());

        // L'équipe voit la confirmation (liste et fiche), puis marque le retrait comme d'habitude.
        $row = $this->adminApi('GET', '/api/admin/reservations?tab=all', $owner)['items'][0];
        self::assertSame([$ref, $at], [$row['ref'], $row['customerReceivedAt'] ?? null]);
        $admin = $this->adminDetail($owner, $ref)['reservation'];
        self::assertSame($at, $admin['customerReceivedAt'] ?? null);
        self::assertArrayNotHasKey('canConfirmReceipt', $admin, 'bouton du client seulement');

        $done = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/status', $owner, ['status' => 'picked_up']);
        self::assertSame(['picked_up', $at], [$done['reservation']['status'], $done['reservation']['customerReceivedAt'] ?? null]);
    }

    public function testAfterTheShopTheClientCanStillConfirmQuietly(): void
    {
        ['token' => $client, 'ref' => $ref] = $this->reservationWithProof();
        $owner = $this->ownerToken();
        $this->subscribe($owner, self::OWNER_DEVICE);
        $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/validate-payment', $owner, []);
        $this->packageReady($owner, $ref, [$this->photo('face')]);
        $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/status', $owner, ['status' => 'picked_up']);
        self::assertSame(200, $this->httpStatus());

        $this->transport()->reset();
        $data = $this->received($client, $ref);
        self::assertSame([200, 'picked_up'], [$this->httpStatus(), $data['status']]);
        self::assertNotNull($data['reservation']['customerReceivedAt'] ?? null);
        self::assertSame([], $this->transport()->sent(), 'déjà remis : rien à confirmer pour la boutique');
    }

    public function testOnlyTheOwnerOfTheReservation(): void
    {
        ['ref' => $ref] = $this->reservationWithProof();
        $other = $this->loginNamed('+237690000002', 'Paul');

        $data = $this->received($other, $ref);
        self::assertSame([404, 'RESERVATION_NOT_FOUND'], [$this->httpStatus(), $data['code']]);
        $this->client->request('POST', '/api/reservations/' . $ref . '/received', [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(401, $this->httpStatus());
    }
}
