<?php

namespace App\Tests\Api\Admin;

use App\Tests\AdminReservationTestCase;

/**
 * Préparation, photos du colis, remise, livraison et retrait (P6), annulation par la boutique et notes internes.
 */
final class AdminFulfilmentTest extends AdminReservationTestCase
{
    /** @return array{client: string, owner: string, ref: string} réservation payée (confirmée) */
    private function paid(array $body = []): array
    {
        ['token' => $client, 'ref' => $ref] = $this->reservationWithProof($body);
        $owner = $this->ownerToken();
        $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/validate-payment', $owner, []);
        self::assertSame(200, $this->httpStatus());

        return ['client' => $client, 'owner' => $owner, 'ref' => $ref];
    }

    public function testPickupWithPackagePhotos(): void
    {
        ['client' => $client, 'owner' => $owner, 'ref' => $ref] = $this->paid();

        $data = $this->packageReady($owner, $ref, [$this->photo('face'), $this->photo('dos')], ['note' => '']);
        self::assertSame(200, $this->httpStatus(), json_encode($data));
        $reservation = $data['reservation'];
        self::assertSame('ready_for_pickup', $reservation['status']);
        $steps = \array_slice($reservation['timeline'], -2);
        self::assertSame(['preparing', 'ready_for_pickup'], array_column($steps, 'status'), 'préparation ajoutée depuis « confirmée »');
        self::assertSame('Colis prêt à la boutique de Yaoundé.', $steps[1]['note']);
        self::assertCount(2, $reservation['packagePhotos']);
        self::assertSame(['id', 'url', 'thumbnailUrl', 'width', 'height', 'takenAt'], array_keys($reservation['packagePhotos'][0]));
        self::assertSame([1, 1], [$reservation['packagePhotos'][0]['width'], $reservation['packagePhotos'][0]['height']]);
        self::assertNotNull($reservation['packageReadyAt']);

        // Photo servie par lien signé, sans ses métadonnées ; lien modifié : refusé.
        $url = $reservation['packagePhotos'][0]['url'];
        self::assertStringContainsString('/api/media/private/package-photos/', $url);
        $this->client->request('GET', $url);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('image/png', $this->header('Content-Type'));
        $bytes = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith("\x89PNG", $bytes);
        self::assertStringNotContainsString('GPS', $bytes);
        $this->client->request('GET', str_replace('signature=', 'signature=0', $url));
        self::assertSame(403, $this->httpStatus());
        $this->client->request('GET', preg_replace('#package-photos/\d+#', 'proofs/' . preg_replace('#.*package-photos/(\d+).*#', '$1', $url), $url));
        self::assertSame(403, $this->httpStatus(), 'la signature d’une photo ne vaut pas pour une preuve');

        // Le client voit le colis prêt et ses photos.
        $detail = $this->clientDetail($client, $ref);
        self::assertSame(['ready_for_pickup', 2], [$detail['status'], \count($detail['packagePhotos'])]);
        self::assertSame($reservation['packageReadyAt'], $detail['packageReadyAt']);
        $summary = $this->getJson('/api/reservations/summary', $client);
        self::assertTrue($summary['items'][0]['hasPackagePhotos']);
        $list = $this->getJson('/api/reservations?scope=active&page=1', $client);
        self::assertSame($detail['packagePhotos'][0]['thumbnailUrl'], $list['items'][0]['packagePhotoThumb']);

        // Retrait au comptoir, puis plus rien à faire.
        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/status', $owner, ['status' => 'picked_up']);
        self::assertSame(['picked_up', 'Colis retiré à la boutique.'], [$data['reservation']['status'], end($data['reservation']['timeline'])['note']]);
        $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/status', $owner, ['status' => 'picked_up']);
        self::assertSame(200, $this->httpStatus(), 'double clic');
        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/status', $owner, ['status' => 'preparing']);
        self::assertSame([409, 'ALREADY_FINAL'], [$this->httpStatus(), $data['code']]);
        self::assertSame(3, $this->stock(self::YDE_VARIANT), 'article vendu : pas remis en stock');
    }

    public function testDeliveryThroughAnAgency(): void
    {
        ['client' => $client, 'owner' => $owner, 'ref' => $ref] = $this->paid($this->pickup(override: ['receptionMode' => 'delivery', 'city' => 'Douala', 'agencyId' => 'ag-dla-travel-lines']));

        $detail = $this->adminDetail($owner, $ref);
        $paidFee = $detail['reservation']['deliveryFee'];
        self::assertSame(['ag-dla-travel-lines', 'ag-dla-united-express', 'ag-dla-touristique'], array_column($detail['availableAgencies'], 'id'), 'agences actives seulement');
        self::assertSame(['id', 'cityId', 'city', 'name', 'address', 'phone', 'openingHours', 'fee', 'departureCity'], array_keys($detail['availableAgencies'][0]));

        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/status', $owner, ['status' => 'preparing']);
        self::assertSame(['preparing', 'Préparation du colis en cours.'], [$data['reservation']['status'], end($data['reservation']['timeline'])['note']]);

        // Colis remis à une autre agence de Douala : fiche de l'agence changée, prix payé inchangé.
        $data = $this->packageReady($owner, $ref, [$this->photo()], ['agencyId' => 'ag-dla-united-express', 'note' => 'Bordereau 4521']);
        self::assertSame(200, $this->httpStatus(), json_encode($data));
        $reservation = $data['reservation'];
        self::assertSame(['handed_to_agency', 'Bordereau 4521'], [$reservation['status'], end($reservation['timeline'])['note']]);
        self::assertSame(['ag-dla-united-express', $paidFee], [$reservation['agency']['id'], $reservation['agency']['fee']]);
        self::assertSame(['ag-dla-united-express', $reservation['agency']['name']], [$reservation['deliveryAddress']['agencyId'], $reservation['deliveryAddress']['agencyName']]);
        self::assertSame([$paidFee, $detail['reservation']['total']], [$reservation['deliveryFee'], $reservation['total']]);

        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/status', $owner, ['status' => 'in_delivery']);
        self::assertSame(['in_delivery', 'En route vers Douala.'], [$data['reservation']['status'], end($data['reservation']['timeline'])['note']]);
        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/status', $owner, ['status' => 'delivered']);
        self::assertSame('delivered', $data['reservation']['status']);
        self::assertSame('delivered', $this->clientDetail($client, $ref)['status']);
    }

    public function testHomeDeliveryHasNoAgencyToChoose(): void
    {
        ['owner' => $owner, 'ref' => $ref] = $this->paid($this->pickup(override: ['receptionMode' => 'delivery', 'city' => 'Yaoundé', 'district' => 'Bastos', 'landmark' => 'Portail noir']));
        self::assertSame([], $this->adminDetail($owner, $ref)['availableAgencies']);

        $data = $this->packageReady($owner, $ref, [$this->photo()], ['agencyId' => 'ag-yde-travel-lines']);
        self::assertSame([422, 'VALIDATION_ERROR'], [$this->httpStatus(), $data['code']]);
        self::assertSame([], glob(static::getContainer()->getParameter('app.private_media_dir') . '/packages/*/*') ?: [], 'photo refusée : aucun fichier gardé');

        $data = $this->packageReady($owner, $ref, [$this->photo()]);
        self::assertSame(['handed_to_agency', 'Colis prêt, en attente du livreur.'], [$data['reservation']['status'], end($data['reservation']['timeline'])['note']]);
        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/status', $owner, ['status' => 'in_delivery']);
        self::assertSame('Le livreur est en route.', end($data['reservation']['timeline'])['note']);
        // Livrée directement depuis « remis » aussi possible (front : « Confirmer la livraison »).
    }

    public function testStatusMachineRefusesWrongSteps(): void
    {
        ['owner' => $owner, 'ref' => $ref] = $this->paid();
        $post = fn (array $body) => $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/status', $owner, $body);

        foreach ([
            [['status' => 'ready_for_pickup'], 422, 'INVALID_STATUS'],
            [['status' => 'confirmed'], 422, 'INVALID_STATUS'],
            [['status' => 'cancelled'], 422, 'INVALID_STATUS'],
            [['status' => 'n_importe_quoi'], 422, 'INVALID_STATUS'],
            [['status' => 'in_delivery'], 422, 'INVALID_RECEPTION_MODE'],
            [['status' => 'picked_up'], 422, 'INVALID_STATUS'],
            [[], 422, 'VALIDATION_ERROR'],
            [['status' => 'preparing', 'note' => str_repeat('n', 201)], 422, 'VALIDATION_ERROR'],
        ] as [$body, $status, $code]) {
            $data = $post($body);
            self::assertSame([$status, $code], [$this->httpStatus(), $data['code'] ?? null], json_encode($body));
        }
        self::assertSame('confirmed', $this->adminDetail($owner, $ref)['reservation']['status']);

        // Photos : 1 à 3 fichiers image ; pas avant le paiement.
        foreach ([
            [[], 422, 'PHOTOS_REQUIRED'],
            [[$this->photo('1'), $this->photo('2'), $this->photo('3'), $this->photo('4')], 422, 'PHOTOS_REQUIRED'],
            [[$this->file('pas une image', 'colis.png')], 415, 'UNSUPPORTED_MEDIA_TYPE'],
        ] as [$files, $status, $code]) {
            $data = $this->packageReady($owner, $ref, $files);
            self::assertSame([$status, $code], [$this->httpStatus(), $data['code'] ?? null]);
        }
        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/package-ready', $owner, ['photos' => [['url' => 'blob:http://localhost/x', 'width' => 1, 'height' => 1]]]);
        self::assertSame([422, 'PHOTOS_REQUIRED'], [$this->httpStatus(), $data['code']], 'liens blob: en JSON refusés');
        $data = $this->packageReady($owner, $ref, [$this->photo()], ['agencyId' => 'ag-yde-travel-lines']);
        self::assertSame([422, 'INVALID_RECEPTION_MODE'], [$this->httpStatus(), $data['code']]);

        ['ref' => $waiting] = $this->reservationWithProof($this->pickup(self::DLA_VARIANT, override: ['phone' => '+237690000002']), '+237690000002');
        $data = $this->packageReady($owner, $waiting, [$this->photo()]);
        self::assertSame([422, 'INVALID_STATUS', 'pending_validation'], [$this->httpStatus(), $data['code'], $data['details']['status']]);
        $data = $this->adminApi('POST', '/api/admin/reservations/' . $waiting . '/status', $owner, ['status' => 'preparing']);
        self::assertSame([422, 'INVALID_STATUS'], [$this->httpStatus(), $data['code']]);
    }

    public function testSellersWorkOnlyOnTheirReservations(): void
    {
        ['ref' => $ref] = $this->paid();
        $yaounde = $this->sellerToken('Yaoundé');
        $douala = $this->sellerToken('Douala');

        foreach (['GET' => '', 'POST' => '/status'] as $method => $suffix) {
            $data = $this->adminApi($method, '/api/admin/reservations/' . $ref . $suffix, $douala, $method === 'POST' ? ['status' => 'preparing'] : null);
            self::assertSame([404, 'RESERVATION_NOT_FOUND'], [$this->httpStatus(), $data['code']], 'hors périmètre : introuvable');
        }

        $data = $this->packageReady($yaounde, $ref, [$this->photo()]);
        self::assertSame(['ready_for_pickup', 'Admin Test'], [$data['reservation']['status'], end($data['reservation']['timeline'])['by']]);
        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/notes', $yaounde, ['text' => 'Client prévenu par WhatsApp']);
        self::assertSame(201, $this->httpStatus());
        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/status', $yaounde, ['status' => 'picked_up']);
        self::assertSame('picked_up', $data['reservation']['status']);
    }

    public function testShopCancellationPutsArticlesBackOnSale(): void
    {
        ['client' => $client, 'owner' => $owner, 'ref' => $ref] = $this->paid($this->pickup(quantity: 2));
        self::assertSame(2, $this->stock(self::YDE_VARIANT));

        foreach ([[], ['reason' => 'no']] as $body) {
            $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/cancel', $owner, $body);
            self::assertSame([422, 'VALIDATION_ERROR'], [$this->httpStatus(), $data['code']]);
        }

        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/cancel', $owner, ['reason' => 'Rupture de stock en boutique']);
        self::assertSame(200, $this->httpStatus(), json_encode($data));
        $last = end($data['reservation']['timeline']);
        self::assertSame(['cancelled', 'shop', 'Rupture de stock en boutique'], [$data['reservation']['status'], $last['actor'], $last['note']]);
        self::assertSame(4, $this->stock(self::YDE_VARIANT), 'articles remis en vente');

        $detail = $this->clientDetail($client, $ref);
        self::assertSame(['cancelled', false], [$detail['status'], $detail['cancellable']]);
        $done = $this->getJson('/api/reservations?scope=done&page=1', $client);
        self::assertSame('confirmed', $done['items'][0]['reachedStatus']);

        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/cancel', $owner, ['reason' => 'Encore une fois']);
        self::assertSame([409, 'ALREADY_FINAL'], [$this->httpStatus(), $data['code']]);
        self::assertSame(4, $this->stock(self::YDE_VARIANT), 'remis une seule fois');
    }

    public function testRejectedThenCancelledCountsAsPaymentSent(): void
    {
        ['token' => $client, 'ref' => $ref] = $this->reservationWithProof();
        $owner = $this->ownerToken();
        $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/reject-payment', $owner, ['reason' => 'Capture illisible']);
        $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/cancel', $owner, ['reason' => 'Client injoignable']);

        $done = $this->getJson('/api/reservations?scope=done&page=1', $client);
        self::assertSame(['cancelled', 'pending_validation'], [$done['items'][0]['status'], $done['items'][0]['reachedStatus']]);
    }

    public function testInternalNotesStayInternal(): void
    {
        ['client' => $client, 'owner' => $owner, 'ref' => $ref] = $this->paid();

        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/notes', $owner, ['text' => 'a']);
        self::assertSame([422, 'VALIDATION_ERROR'], [$this->httpStatus(), $data['code']]);

        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/notes', $owner, ['text' => '  Client très pressé, appeler avant 17 h  ']);
        self::assertSame(201, $this->httpStatus(), json_encode($data));
        self::assertSame(['id', 'author', 'text', 'createdAt'], array_keys($data['note']));
        self::assertSame(['Admin Test', 'Client très pressé, appeler avant 17 h'], [$data['note']['author'], $data['note']['text']]);
        $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/notes', $owner, ['text' => 'Deuxième note']);
        self::assertSame(['Client très pressé, appeler avant 17 h', 'Deuxième note'], array_column($this->adminDetail($owner, $ref)['internalNotes'], 'text'));

        $this->getJson('/api/reservations/' . $ref, $client);
        self::assertStringNotContainsString('pressé', (string) $this->client->getResponse()->getContent());
    }
}
