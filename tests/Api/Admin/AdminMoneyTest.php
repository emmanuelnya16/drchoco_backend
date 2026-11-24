<?php

namespace App\Tests\Api\Admin;

use App\Tests\AdminReservationTestCase;

/**
 * Argent du client vu par l'équipe (P9) : décision prise sur la preuve réellement affichée, preuve réservée aux super
 * admins, transaction déclarée deux fois, prix du catalogue modifié, remboursement à prévoir, journal d'audit.
 */
final class AdminMoneyTest extends AdminReservationTestCase
{
    public function testADecisionAppliesToTheProofThatWasDisplayed(): void
    {
        ['token' => $client, 'ref' => $ref] = $this->reservationWithProof();
        $owner = $this->ownerToken();
        $manager = $this->ownerToken();
        $seen = fn (array $detail): array => ['expectedStatus' => $detail['reservation']['status'], 'proofSha256' => $detail['reservation']['proof']['sha256'] ?? null];
        $ownerScreen = $seen($this->adminDetail($owner, $ref));
        self::assertSame(64, \strlen($ownerScreen['proofSha256']));

        // Le gérant rejette ; l'écran du propriétaire, resté ouvert, ne peut plus valider la même preuve.
        $this->adminApi('POST', "/api/admin/reservations/$ref/reject-payment", $manager, ['reason' => 'Transaction introuvable'] + $ownerScreen);
        self::assertSame(200, $this->httpStatus());
        $data = $this->adminApi('POST', "/api/admin/reservations/$ref/validate-payment", $owner, $ownerScreen);
        self::assertSame([409, 'STATE_CHANGED', 'payment_rejected'], [$this->httpStatus(), $data['code'], $data['details']['status']]);

        // Le client renvoie une autre capture : même statut qu'avant, mais autre preuve.
        $this->uploadProof($client, $ref, $this->file(self::pngWithText('Comment', 'seconde capture'), 'autre.png'));
        self::assertSame(201, $this->httpStatus());
        self::assertSame('pending_validation', $this->adminDetail($owner, $ref)['reservation']['status']);
        $other = $this->adminDetail($owner, $ref)['reservation']['proof']['sha256'];
        self::assertSame($other === $ownerScreen['proofSha256'] ? 200 : 409, $this->validateStatus($owner, $ref, $ownerScreen));
        self::assertSame('pending_validation', $this->adminDetail($owner, $ref)['reservation']['status']);

        // Preuve affichée « aucune » alors qu'il y en a une : refusé aussi. Sans ces champs : accepté (anciens écrans).
        self::assertSame(409, $this->validateStatus($owner, $ref, ['proofSha256' => null]));
        self::assertSame(409, $this->rejectStatus($owner, $ref, ['expectedStatus' => 'reserved']));
        self::assertSame(200, $this->validateStatus($owner, $ref, $seen($this->adminDetail($owner, $ref))));
        // Double clic : déjà validée, rien à refaire.
        self::assertSame(200, $this->validateStatus($owner, $ref, ['expectedStatus' => 'pending_validation', 'proofSha256' => $other]));

        self::assertSame(['payment.rejected', 'payment.validated'], array_column(array_filter($this->auditRecords(), fn (array $r) => str_starts_with($r['event'], 'payment.')), 'event'));
        $audit = $this->auditRecords('payment.validated')[0];
        self::assertSame([$ref, 45000, false, 'Admin Test'], [$audit['data']['ref'], $audit['data']['total'], $audit['data']['manual'], $audit['admin']['name']]);
    }

    public function testManualValidationIsRecordedAsSuch(): void
    {
        $owner = $this->ownerToken();
        $ref = $this->reserve($this->loginNamed(), $this->pickup())['ref'];
        self::assertSame(200, $this->validateStatus($owner, $ref, ['expectedStatus' => 'reserved', 'proofSha256' => null]));
        self::assertTrue($this->auditRecords('payment.validated')[0]['data']['manual']);
    }

    public function testASellerOnlySeesThatAProofWasReceived(): void
    {
        $token = $this->loginNamed();
        $ref = $this->reserve($token, $this->pickup())['ref'];
        $this->uploadProof($token, $ref, $this->file(), ['operator' => 'mtn', 'payerPhone' => '690112233', 'transactionId' => 'MP2611.1234.A1']);
        self::assertSame(201, $this->httpStatus());

        $seller = $this->sellerToken('Yaoundé');
        $data = $this->adminDetail($seller, $ref);
        self::assertSame(['operator', 'status', 'uploadedAt'], array_keys($data['reservation']['proof']));
        self::assertSame([[], []], [$data['duplicateProofRefs'], $data['duplicateTransactionRefs']]);
        $row = $this->adminApi('GET', '/api/admin/reservations', $seller)['items'][0];
        self::assertArrayNotHasKey('proofThumbnailUrl', $row);
        self::assertSame([true, 'mtn'], [$row['hasProof'], $row['operator']]);
        self::assertStringNotContainsString('690112233', (string) $this->client->getResponse()->getContent());

        $proof = $this->adminDetail($this->ownerToken(), $ref)['reservation']['proof'];
        self::assertSame(['+237690112233', 'MP2611.1234.A1', 'pending'], [$proof['payerPhone'], $proof['transactionId'], $proof['status']]);
        self::assertStringContainsString('/api/media/private/proofs/', $proof['thumbnailUrl']);
    }

    public function testATransactionDeclaredTwiceIsShownToTheSuperAdmin(): void
    {
        $refs = [];
        foreach (['MP2611.1234.A1', ' mp26111234-a1 ', 'MP2611.9999.Z9', 'A1'] as $i => $transaction) {
            $phone = '+23769000001' . $i;
            $token = $this->loginNamed($phone, 'Client');
            $refs[$i] = $this->reserve($token, $this->pickup(self::DLA_VARIANT, override: ['phone' => $phone]))['ref'];
            $this->uploadProof($token, $refs[$i], $this->file(self::pngWithText('Comment', 'capture ' . $i)), ['operator' => 'mtn', 'transactionId' => $transaction]);
            self::assertSame(201, $this->httpStatus(), (string) $this->client->getResponse()->getContent());
        }
        $owner = $this->ownerToken();
        self::assertSame([$refs[1]], $this->adminDetail($owner, $refs[0])['duplicateTransactionRefs'], 'même référence, écrite autrement');
        self::assertSame([$refs[0]], $this->adminDetail($owner, $refs[1])['duplicateTransactionRefs']);
        self::assertSame([], $this->adminDetail($owner, $refs[2])['duplicateTransactionRefs']);
        self::assertSame([], $this->adminDetail($owner, $refs[3])['duplicateTransactionRefs'], 'trop courte pour être comparée');
    }

    public function testACatalogPriceDifferentFromTheReservedPriceIsShown(): void
    {
        $owner = $this->ownerToken();
        $db = $this->em()->getConnection();
        $db->executeStatement("UPDATE products SET price = 5000 WHERE id = 'prod-01'");
        $this->em()->clear();
        $ref = $this->reserve($this->loginNamed(), $this->pickup())['ref'];
        self::assertArrayNotHasKey('currentUnitPrice', $this->adminDetail($owner, $ref)['reservation']['items'][0]);

        $db->executeStatement("UPDATE products SET price = 45000 WHERE id = 'prod-01'");
        $this->em()->clear();
        $item = $this->adminDetail($owner, $ref)['reservation']['items'][0];
        self::assertSame([5000, 45000], [$item['unitPrice'], $item['currentUnitPrice']]);
        self::assertArrayNotHasKey('currentUnitPrice', $this->adminDetail($this->sellerToken('Yaoundé'), $ref)['reservation']['items'][0]);
    }

    public function testRefundDueIsShownUntilASuperAdminClosesIt(): void
    {
        $owner = $this->ownerToken();
        $row = fn (string $ref): array => array_column($this->adminApi('GET', '/api/admin/reservations?q=' . $ref, $owner)['items'], null, 'ref')[$ref];

        // Annulée sans paiement : rien à rembourser.
        $token = $this->loginNamed();
        $unpaid = $this->reserve($token, $this->pickup())['ref'];
        $this->api('POST', "/api/reservations/$unpaid/cancel", $token, []);
        self::assertFalse($row($unpaid)['refundDue']);

        // Preuve envoyée puis annulée avant d'être examinée : à vérifier et rembourser.
        $waiting = $this->reserve($token, $this->pickup())['ref'];
        $this->uploadProof($token, $waiting, $this->file());
        self::assertFalse($row($waiting)['refundDue']);
        $this->api('POST', "/api/reservations/$waiting/cancel", $token, []);
        self::assertTrue($row($waiting)['refundDue']);
        self::assertTrue($this->adminDetail($owner, $waiting)['reservation']['refundDue']);

        // Preuve rejetée puis annulée : rien à rembourser.
        $rejected = $this->reserve($token, $this->pickup())['ref'];
        $this->uploadProof($token, $rejected, $this->file(self::pngWithText('Comment', 'fausse capture')));
        $this->adminApi('POST', "/api/admin/reservations/$rejected/reject-payment", $owner, ['reason' => 'Transaction introuvable']);
        $this->api('POST', "/api/reservations/$rejected/cancel", $token, []);
        self::assertFalse($row($rejected)['refundDue']);

        // Payée puis annulée par la boutique.
        $paid = $this->reserve($token, $this->pickup())['ref'];
        $this->adminApi('POST', "/api/admin/reservations/$paid/validate-payment", $owner, []);
        $this->adminApi('POST', "/api/admin/reservations/$paid/cancel", $owner, ['reason' => 'Rupture de stock']);
        self::assertTrue($row($paid)['refundDue']);
        $audit = $this->auditRecords('reservation.cancelled');
        self::assertSame([$paid, true, 'Rupture de stock'], [end($audit)['data']['ref'], end($audit)['data']['refundDue'], end($audit)['data']['reason']]);

        // Remboursement fait hors de l'application : clos par un super admin, avec une explication.
        $settle = fn (string $ref, string $token, array $body): array => [$this->adminApi('POST', "/api/admin/reservations/$ref/refund-settled", $token, $body), $this->httpStatus()];
        self::assertSame(403, $settle($paid, $this->sellerToken('Yaoundé'), ['note' => 'Remboursé'])[1]);
        foreach ([[], ['note' => 'ab'], ['note' => str_repeat('x', 201)]] as $body) {
            self::assertSame(422, $settle($paid, $owner, $body)[1]);
        }
        [$data, $status] = $settle($paid, $owner, ['note' => '45 000 FCFA rendus par Orange Money, MP2611.1']);
        self::assertSame([200, false], [$status, $data['reservation']['refundDue']], json_encode($data));
        self::assertArrayHasKey('refundSettledAt', $data['reservation']);
        self::assertFalse($row($paid)['refundDue']);
        self::assertSame(200, $settle($paid, $owner, ['note' => 'encore'])[1], 'double clic : sans effet');
        self::assertSame(['Remboursement clos : 45 000 FCFA rendus par Orange Money, MP2611.1'], array_column($this->adminDetail($owner, $paid)['internalNotes'], 'text'));
        self::assertSame([$paid, 45000], [$this->auditRecords('refund.settled')[0]['data']['ref'], $this->auditRecords('refund.settled')[0]['data']['total']]);
        self::assertCount(1, $this->auditRecords('refund.settled'));
        // Rien à rembourser : refusé.
        self::assertSame([422, 'INVALID_STATUS'], [$settle($unpaid, $owner, ['note' => 'Rien'])[1], $settle($unpaid, $owner, ['note' => 'Rien'])[0]['code']]);
        self::assertArrayNotHasKey('refundDue', $this->clientDetail($token, $paid), 'jamais montré au client');
    }

    public function testRefundDueSurvivesTheEndOfTheRetention(): void
    {
        $owner = $this->ownerToken();
        $token = $this->loginNamed();
        $ref = $this->reserve($token, $this->pickup())['ref'];
        $this->uploadProof($token, $ref, $this->file());
        $this->api('POST', "/api/reservations/$ref/cancel", $token, []);
        $this->em()->getConnection()->executeStatement("UPDATE reservation_proofs SET storage_path = '', payer_phone = NULL, transaction_id = NULL");
        $this->em()->clear();
        $reservation = $this->adminDetail($owner, $ref)['reservation'];
        self::assertTrue($reservation['refundDue'], 'capture effacée après 12 mois : la marque reste');
        self::assertArrayNotHasKey('proof', $reservation);
    }

    /** @param array<string, mixed> $body */
    private function validateStatus(string $token, string $ref, array $body): int
    {
        $this->adminApi('POST', "/api/admin/reservations/$ref/validate-payment", $token, $body);

        return $this->httpStatus();
    }

    /** @param array<string, mixed> $body */
    private function rejectStatus(string $token, string $ref, array $body): int
    {
        $this->adminApi('POST', "/api/admin/reservations/$ref/reject-payment", $token, ['reason' => 'Montant incorrect'] + $body);

        return $this->httpStatus();
    }
}
