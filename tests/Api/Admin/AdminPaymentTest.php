<?php

namespace App\Tests\Api\Admin;

use App\Tests\AdminReservationTestCase;

/**
 * Validation et rejet des paiements Mobile Money par le super admin (P6) : preuve acceptée ou rejetée, +6 h pour
 * renvoyer une preuve, validation manuelle, refus pour un vendeur.
 */
final class AdminPaymentTest extends AdminReservationTestCase
{
    public function testSuperAdminValidatesTheProof(): void
    {
        ['token' => $client, 'ref' => $ref] = $this->reservationWithProof();
        $owner = $this->ownerToken();

        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/validate-payment', $owner, []);
        self::assertSame(200, $this->httpStatus(), json_encode($data));
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));
        $reservation = $data['reservation'];
        self::assertSame(['confirmed', 'accepted'], [$reservation['status'], $reservation['proof']['status']]);
        self::assertArrayHasKey('paidAt', $reservation);
        $last = end($reservation['timeline']);
        self::assertSame(['confirmed', 'shop', 'Paiement validé par la boutique.', 'Admin Test'], [$last['status'], $last['actor'], $last['note'], $last['by']]);

        // Côté client : validée, sans le nom du membre de l'équipe.
        $detail = $this->clientDetail($client, $ref);
        self::assertSame('confirmed', $detail['status']);
        self::assertArrayNotHasKey('by', end($detail['timeline']));
        self::assertArrayNotHasKey('paidAt', $detail);

        // Double clic : rien de plus.
        $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/validate-payment', $owner, []);
        self::assertSame(200, $this->httpStatus());
        self::assertCount(\count($reservation['timeline']), $this->adminDetail($owner, $ref)['reservation']['timeline']);
    }

    public function testOnlyTheSuperAdminHandlesPaymentsAndCancellation(): void
    {
        ['ref' => $ref] = $this->reservationWithProof();
        $seller = $this->sellerToken('Yaoundé');

        self::assertSame('pending_validation', $this->adminDetail($seller, $ref)['reservation']['status'], 'le vendeur voit la réservation');
        foreach (['validate-payment' => [], 'reject-payment' => ['reason' => 'Montant incorrect'], 'cancel' => ['reason' => 'Rupture de stock']] as $action => $body) {
            $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/' . $action, $seller, $body);
            self::assertSame([403, 'FORBIDDEN'], [$this->httpStatus(), $data['code'] ?? null], $action);
        }
        self::assertSame('pending_validation', $this->adminDetail($seller, $ref)['reservation']['status']);
    }

    public function testRejectionGivesSixMoreHoursAndTheClientCanResend(): void
    {
        ['token' => $client, 'ref' => $ref] = $this->reservationWithProof();
        $owner = $this->ownerToken();
        $this->wait(22 * 3600); // plus que 2 h avant l'échéance d'origine (24 h)
        $this->em()->getConnection()->executeStatement('UPDATE reservations SET expiring_notified_at = NOW() WHERE ref = ?', [$ref]);

        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/reject-payment', $owner, ['reason' => '  Montant   incorrect  ']);
        self::assertSame(200, $this->httpStatus(), json_encode($data));
        $now = $this->clock->now()->getTimestamp();
        $reservation = $data['reservation'];
        self::assertSame(['payment_rejected', 'rejected', 'Montant incorrect'], [$reservation['status'], $reservation['proof']['status'], $reservation['rejection']['reason']]);
        self::assertSame($now + 6 * 3600, strtotime($reservation['expiresAt']), 'le délai repart pour 6 h');
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT expiring_notified_at FROM reservations WHERE ref = ?', [$ref]), 'nouveau rappel possible');

        $detail = $this->clientDetail($client, $ref);
        self::assertSame(['Montant incorrect', 'Montant incorrect', 'shop'], [$detail['rejection']['reason'], $detail['rejectionReason'], end($detail['timeline'])['actor']]);

        // Toujours payable après l'échéance d'origine, jusqu'aux 6 h.
        $this->wait(5 * 3600);
        self::assertSame('payment_rejected', $this->clientDetail($client, $ref)['status']);
        $this->uploadProof($client, $ref, $this->file(self::pngWithText('Comment', 'nouvelle preuve')));
        self::assertSame(201, $this->httpStatus());
        self::assertSame('pending_validation', $this->adminDetail($owner, $ref)['reservation']['status']);

        // Rejet en double : pas de seconde ligne.
        $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/reject-payment', $owner, ['reason' => 'Capture illisible']);
        $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/reject-payment', $owner, ['reason' => 'Capture illisible']);
        self::assertSame(200, $this->httpStatus());
        $statuses = array_column($this->adminDetail($owner, $ref)['reservation']['timeline'], 'status');
        self::assertSame(['reserved', 'pending_validation', 'payment_rejected', 'pending_validation', 'payment_rejected'], $statuses);

        // Puis, sans nouvelle preuve, la réservation expire 6 h après le dernier rejet.
        $this->wait(6 * 3600);
        self::assertSame('expired', $this->clientDetail($client, $ref)['status']);
        self::assertSame(4, $this->stock(self::YDE_VARIANT), 'article remis en vente');
    }

    public function testRejectionNeverShortensTheDeadline(): void
    {
        ['ref' => $ref] = $this->reservationWithProof();
        $owner = $this->ownerToken();
        $before = $this->adminDetail($owner, $ref)['reservation']['expiresAt'];
        $this->wait(3600);

        $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/reject-payment', $owner, ['reason' => 'Mauvais destinataire']);
        self::assertSame($before, $data['reservation']['expiresAt'], 'il restait 23 h : échéance gardée');
    }

    public function testRejectionNeedsAReasonAndAProofToCheck(): void
    {
        $owner = $this->ownerToken();
        ['ref' => $ref] = $this->reservationWithProof();

        foreach ([[], ['reason' => 'ab'], ['reason' => str_repeat('x', 201)], ['reason' => 12]] as $body) {
            $data = $this->adminApi('POST', '/api/admin/reservations/' . $ref . '/reject-payment', $owner, $body);
            self::assertSame([422, 'VALIDATION_ERROR'], [$this->httpStatus(), $data['code']], json_encode($body));
            self::assertArrayHasKey('reason', $data['details']);
        }

        $token = $this->loginNamed('+237690000002', 'Paul');
        $unpaid = $this->reserve($token, $this->pickup(self::DLA_VARIANT, override: ['phone' => '+237690000002']));
        $data = $this->adminApi('POST', '/api/admin/reservations/' . $unpaid['ref'] . '/reject-payment', $owner, ['reason' => 'Montant incorrect']);
        self::assertSame([422, 'INVALID_STATUS', 'reserved'], [$this->httpStatus(), $data['code'], $data['details']['status']]);
    }

    public function testManualValidationWithoutProof(): void
    {
        $owner = $this->ownerToken();
        $token = $this->loginNamed();
        $data = $this->reserve($token, $this->pickup());

        $data = $this->adminApi('POST', '/api/admin/reservations/' . $data['ref'] . '/validate-payment', $owner, ['note' => 'Paiement vérifié et validé manuellement par la boutique.']);
        self::assertSame(200, $this->httpStatus(), json_encode($data));
        self::assertSame(['confirmed', 'Paiement vérifié et validé manuellement par la boutique.'], [$data['reservation']['status'], end($data['reservation']['timeline'])['note']]);
        self::assertArrayNotHasKey('proof', $data['reservation']);
        $validated = $data['reservation']['ref'];

        // Échéance dépassée : la réservation expire (stock remis), la validation est refusée.
        $late = $this->reserve($token, $this->pickup(self::DLA_VARIANT));
        $this->wait(24 * 3600);
        $data = $this->adminApi('POST', '/api/admin/reservations/' . $late['ref'] . '/validate-payment', $owner, []);
        self::assertSame([409, 'RESERVATION_EXPIRED'], [$this->httpStatus(), $data['code']]);
        self::assertSame('expired', $this->adminDetail($owner, $late['ref'])['reservation']['status']);
        self::assertSame(8, $this->stock(self::DLA_VARIANT));

        // Plus rien à valider une fois la préparation commencée.
        $this->forceStatus($validated, 'preparing');
        $data = $this->adminApi('POST', '/api/admin/reservations/' . $validated . '/validate-payment', $owner, []);
        self::assertSame([422, 'INVALID_STATUS'], [$this->httpStatus(), $data['code']]);
    }
}
