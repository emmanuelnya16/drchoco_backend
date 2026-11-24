<?php

namespace App\Tests\Api\Admin;

use App\Entity\User;
use App\Tests\AdminReservationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Compte d'un client géré par la boutique (P9) : blocage, sessions coupées, export, effacement ; conservation de 12 mois
 * des captures et des photos de colis (app:purge).
 */
final class CustomerManagementTest extends AdminReservationTestCase
{
    public function testBlockingCutsEverythingAtOnce(): void
    {
        $owner = $this->ownerToken();
        ['token' => $client, 'ref' => $ref] = $this->reservationWithProof();
        $id = $this->userByPhone()->getId();
        $this->client->request('POST', '/api/push/subscribe', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $client, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(\App\Tests\Api\Push\PushSubscriptionTest::subscription('https://fcm.googleapis.com/fcm/send/telephone-client')));
        self::assertSame(201, $this->httpStatus());

        $data = $this->adminApi('POST', "/api/admin/customers/$id/block", $owner, ['reason' => '  Fausses   preuves ']);
        self::assertSame([200, 'blocked', self::PHONE], [$this->httpStatus(), $data['customer']['status'] ?? null, $data['customer']['phone'] ?? null], json_encode($data));
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));
        self::assertSame([$ref], array_column($data['customer']['reservations'], 'ref'), 'ses réservations restent visibles');
        $audit = $this->auditRecords('customer.blocked')[0];
        self::assertSame([$id, 'Fausses preuves', 'Admin Test'], [$audit['data']['customer'], $audit['data']['reason'], $audit['admin']['name']]);
        self::assertStringNotContainsString('690000001', json_encode($audit));

        // Jeton, session et notifications coupés ; plus de connexion possible.
        $this->api('GET', '/api/me', $client);
        self::assertContains($this->httpStatus(), [401, 403]);
        $this->postJson('/api/auth/refresh', []);
        self::assertSame(401, $this->httpStatus());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM push_subscriptions WHERE owner_kind = 'user'"));
        $this->wait(120);
        self::assertSame([403, 'ACCOUNT_BLOCKED'], [$this->statusAfter(fn () => $this->requestCode()), $this->lastCode()]);

        $data = $this->adminApi('POST', "/api/admin/customers/$id/unblock", $owner, []);
        self::assertSame([200, 'active'], [$this->httpStatus(), $data['customer']['status']]);
        self::assertSame('active', $this->adminApi('GET', '/api/admin/customers', $owner)['items'][0]['status']);
        $this->login();
        self::assertSame($id, $this->userByPhone()->getId(), 'même compte');
        self::assertCount(1, $this->auditRecords('customer.unblocked'));
    }

    public function testOnlySuperAdminsBlock(): void
    {
        $this->reservationWithProof();
        $id = $this->userByPhone()->getId();
        $seller = $this->sellerToken('Yaoundé');
        foreach (['block', 'unblock'] as $action) {
            $data = $this->adminApi('POST', "/api/admin/customers/$id/$action", $seller, []);
            self::assertSame([403, 'FORBIDDEN'], [$this->httpStatus(), $data['code'] ?? null], $action);
        }
        $this->adminApi('POST', "/api/admin/customers/$id/block", $this->tokenFor($this->userByPhone()), []);
        self::assertSame(403, $this->httpStatus(), 'jeton client');
        self::assertSame(User::STATUS_ACTIVE, $this->userByPhone()->getStatus());

        $owner = $this->ownerToken();
        $data = $this->adminApi('POST', '/api/admin/customers/999999/block', $owner, []);
        self::assertSame([404, 'CUSTOMER_NOT_FOUND'], [$this->httpStatus(), $data['code']]);
        $data = $this->adminApi('POST', "/api/admin/customers/$id/block", $owner, ['reason' => str_repeat('x', 201)]);
        self::assertSame([422, 'VALIDATION_ERROR'], [$this->httpStatus(), $data['code']]);
    }

    public function testSellersOnlyCountTheirOwnLines(): void
    {
        $owner = $this->ownerToken();
        $seller = null;
        $sellerToken = $this->sellerToken('Yaoundé', $seller);
        $ref = $this->reserve($this->loginNamed(), $this->pickup(override: ['items' => [['variantId' => 'prod-01-noir-s', 'quantity' => 1], ['variantId' => 'prod-01-noir-m', 'quantity' => 1]]]))['ref'];
        self::assertSame(201, $this->httpStatus());
        $other = $this->createAdmin('seller', 'collegue@drchoco.cm', 'Yaoundé');
        $this->em()->getConnection()->executeStatement("UPDATE reservation_items SET seller_id = ? WHERE variant_id = 'prod-01-noir-s'", [$seller->getId()]);
        $this->em()->getConnection()->executeStatement("UPDATE reservation_items SET seller_id = ? WHERE variant_id = 'prod-01-noir-m'", [$other->getId()]);
        $this->forceStatus($ref, 'confirmed', ['paid_at' => '2026-11-24 10:00:00']);

        self::assertSame(90000, $this->adminApi('GET', '/api/admin/customers', $owner)['items'][0]['confirmedTotalFcfa']);
        $item = $this->adminApi('GET', '/api/admin/customers', $sellerToken)['items'][0];
        self::assertSame([45000, 1], [$item['confirmedTotalFcfa'], $item['reservationsCount']]);
    }

    public function testEachCustomerShowsTheirLatestReservationsOnly(): void
    {
        $owner = $this->ownerToken();
        $token = $this->loginNamed();
        $ref = $this->reserve($token, $this->pickup())['ref'];
        $db = $this->em()->getConnection();
        $columns = implode(', ', array_diff($db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'reservations'"), ['id', 'ref', 'idempotency_key', 'updated_at']));
        foreach (range(1, 24) as $n) {
            $db->executeStatement("INSERT INTO reservations (ref, idempotency_key, updated_at, $columns) SELECT ?, ?, DATE_ADD(updated_at, INTERVAL ? MINUTE), $columns FROM reservations WHERE ref = ?", [sprintf('RES-9%05d', $n), 'copie-' . $n, $n, $ref]);
        }
        $item = $this->adminApi('GET', '/api/admin/customers', $owner)['items'][0];
        self::assertSame([25, 20], [$item['reservationsCount'], \count($item['reservations'])]);
        self::assertSame('RES-900024', $item['reservations'][0]['ref'], 'la plus récente d’abord');
    }

    public function testCloseSessionsKeepsTheAccount(): void
    {
        $this->loginNamed();
        self::assertStringContainsString('déconnecté de tous ses appareils', $this->command(['telephone' => '690 00 00 01', '--close-sessions' => true]));
        $this->postJson('/api/auth/refresh', []);
        self::assertSame(401, $this->httpStatus());
        self::assertSame(User::STATUS_ACTIVE, $this->userByPhone()->getStatus());
        self::assertCount(1, $this->auditRecords('customer.sessions_closed'));
        self::assertStringContainsString('Aucun client', $this->command(['telephone' => '699999999', '--block' => true]));
        self::assertStringContainsString('une seule action', $this->command(['telephone' => '690000001']));
        self::assertStringContainsString('une seule action', $this->command(['telephone' => '690000001', '--block' => true, '--erase' => true]));
    }

    public function testExportWritesEverythingTheShopKeeps(): void
    {
        ['ref' => $ref] = $this->reservationWithProof($this->pickup(override: ['notes' => 'Appelez avant', 'receptionMode' => 'delivery', 'city' => 'Yaoundé', 'district' => 'Bastos', 'landmark' => 'Portail noir après la pharmacie']));
        $file = sys_get_temp_dir() . '/drc-export-' . bin2hex(random_bytes(6)) . '.json';
        try {
            self::assertStringContainsString('Données écrites', $this->command(['telephone' => '690000001', '--export' => $file]));
            $data = json_decode((string) file_get_contents($file), true);
            self::assertSame([self::PHONE, 'Junior', 'Mbarga'], [$data['compte']['telephone'], $data['compte']['prenom'], $data['compte']['nom']]);
            $reservation = $data['reservations'][0];
            self::assertSame([$ref, 'Bastos', 'Appelez avant', 'orange'], [$reservation['ref'], $reservation['livraison']['quartier'], $reservation['remarque'], $reservation['preuves'][0]['operateur']]);
            self::assertSame(1, $reservation['articles'][0]['quantite']);
            self::assertStringNotContainsString('signature=', (string) file_get_contents($file), 'aucun lien d’image');
            self::assertStringContainsString('impossible à créer', $this->command(['telephone' => '690000001', '--export' => $file]), 'jamais par-dessus un fichier');
        } finally {
            @unlink($file);
        }
        self::assertCount(1, $this->auditRecords('customer.exported'));
    }

    public function testEraseMakesTheCustomerAnonymous(): void
    {
        $owner = $this->ownerToken();
        ['token' => $client, 'ref' => $paid] = $this->reservationWithProof($this->pickup(override: ['notes' => 'Appelez avant', 'receptionMode' => 'delivery', 'city' => 'Yaoundé', 'district' => 'Bastos', 'landmark' => 'Portail noir après la pharmacie']));
        $this->adminApi('POST', "/api/admin/reservations/$paid/notes", $owner, ['text' => 'Client pressé, rappeler le soir']);
        $cancelled = $this->reserve($client, $this->pickup())['ref'];
        $this->uploadProof($client, $cancelled, $this->file(self::pngWithText('Comment', 'capture du paiement annulé')));
        $this->api('POST', "/api/reservations/$cancelled/cancel", $client, ['reason' => 'Je déménage à Garoua']);
        self::assertSame(200, $this->httpStatus());
        $this->api('POST', '/api/me/addresses', $client, ['label' => 'Maison', 'city' => 'Yaoundé', 'district' => 'Bastos', 'landmark' => 'Portail noir']);
        $id = $this->userByPhone()->getId();

        // Une réservation en cours et un remboursement à prévoir : refusé. Réponse « non » : rien.
        $refused = $this->command(['telephone' => '690000001', '--erase' => true], ['oui']);
        self::assertStringContainsString($paid, $refused);
        self::assertStringContainsString($cancelled, $refused);
        $this->adminApi('POST', "/api/admin/reservations/$cancelled/refund-settled", $owner, ['note' => 'Fausse capture, rien à rembourser']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString($cancelled, $this->command(['telephone' => '690000001', '--erase' => true], ['oui']));
        $this->adminApi('POST', "/api/admin/reservations/$paid/validate-payment", $owner, []);
        $this->packageReady($owner, $paid, [$this->photo()]);
        $this->adminApi('POST', "/api/admin/reservations/$paid/status", $owner, ['status' => 'delivered']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Rien n’a été effacé', $this->command(['telephone' => '690000001', '--erase' => true], ['non']));
        self::assertNotNull($this->userByPhone());

        // La capture du paiement validé reste (12 mois) ; celle de la réservation annulée et la photo du colis partent.
        self::assertStringContainsString('2 réservation(s) rendue(s) anonyme(s), 2 image(s) supprimée(s)', $this->command(['telephone' => '690000001', '--erase' => true], ['oui']));
        $db = $this->em()->getConnection();
        self::assertNull($this->userByPhone());
        self::assertSame(['efface-' . $id, null, null, null, 'blocked'], array_values((array) $db->fetchAssociative('SELECT phone, first_name, last_name, city, status FROM users WHERE id = ?', [$id])));
        foreach (['addresses' => 'user_id', 'favorites' => 'user_id', 'refresh_tokens' => 'user_id', 'push_subscriptions' => 'user_id'] as $table => $column) {
            self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM $table WHERE $column = ?" . ($table === 'refresh_tokens' ? " AND user_type = 'user'" : ''), [$id]), $table);
        }
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM otp_codes WHERE phone = ?', [self::PHONE]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM reservation_notes'));
        foreach ($db->fetchAllAssociative('SELECT * FROM reservations WHERE user_id = ?', [$id]) as $row) {
            self::assertSame(['Client', 'effacé', '', null, null, null, null, null], [$row['customer_first_name'], $row['customer_last_name'], $row['customer_phone'], $row['recipient_name'], $row['recipient_phone'], $row['notes'], $row['district'], $row['landmark']]);
            self::assertGreaterThan(0, (int) $row['total'], 'montant gardé');
        }
        self::assertStringNotContainsString('Garoua', json_encode($db->fetchFirstColumn('SELECT note FROM reservation_events'), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        self::assertSame(['accepted' => 1, 'pending' => 0], array_map('intval', $db->fetchAllKeyValue("SELECT status, storage_path <> '' FROM reservation_proofs")));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM reservation_package_photos'));

        // Fiches toujours lisibles par l'équipe ; les images effacées ne sont plus annoncées.
        self::assertArrayNotHasKey('proof', $this->adminDetail($owner, $cancelled)['reservation']);
        self::assertSame([], $this->adminDetail($owner, $paid)['reservation']['packagePhotos']);
        $audit = $this->auditRecords('customer.erased')[0];
        self::assertSame([$id, 2, 2], [$audit['data']['customer'], $audit['data']['reservations'], $audit['data']['files']]);

        // Pas de déblocage possible ; le même numéro ouvre un compte neuf, et l'ancienne capture reste reconnue.
        $data = $this->adminApi('POST', "/api/admin/customers/$id/unblock", $owner, []);
        self::assertSame([409, 'CUSTOMER_ERASED'], [$this->httpStatus(), $data['code']]);
        $this->wait(120);
        $again = $this->login();
        self::assertTrue($again['isNewUser']);
        self::assertSame(0, $this->api('GET', '/api/reservations?scope=done', $again['token'])['total']);
        $new = $this->reserve($again['token'], $this->pickup(override: ['firstName' => 'Awa']))['ref'];
        $data = $this->uploadProof($again['token'], $new, $this->file(self::pngWithText('Comment', 'capture du paiement annulé')));
        self::assertSame([409, 'DUPLICATE_PROOF'], [$this->httpStatus(), $data['code']]);
    }

    public function testImagesAreErasedAfterTwelveMonths(): void
    {
        $owner = $this->ownerToken();
        ['token' => $client, 'ref' => $done] = $this->reservationWithProof();
        $this->adminApi('POST', "/api/admin/reservations/$done/validate-payment", $owner, []);
        $this->packageReady($owner, $done, [$this->photo()]);
        $this->adminApi('POST', "/api/admin/reservations/$done/status", $owner, ['status' => 'picked_up']);
        self::assertSame(200, $this->httpStatus());
        $open = $this->reserve($client, $this->pickup(self::DLA_VARIANT))['ref'];
        $this->uploadProof($client, $open, $this->file(self::pngWithText('Comment', 'toujours en cours')));
        self::assertSame(201, $this->httpStatus());
        $db = $this->em()->getConnection();
        $files = fn (): int => iterator_count(new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(static::getContainer()->getParameter('app.private_media_dir'), \FilesystemIterator::SKIP_DOTS)), '/\.png$/'));
        self::assertSame(3, $files());

        $this->clock->modify('+11 months');
        self::assertStringContainsString('0 capture(s) ou photo(s) de plus de 12 mois effacée(s)', $this->purge());
        self::assertSame(3, $files());

        $this->clock->modify('+33 days');
        self::assertStringContainsString('2 capture(s) ou photo(s) de plus de 12 mois effacée(s)', $this->purge());
        self::assertSame(1, $files(), 'la réservation en cours garde sa capture');
        self::assertSame(['', null, null], array_values((array) $db->fetchAssociative('SELECT p.storage_path, p.payer_phone, p.transaction_id FROM reservation_proofs p INNER JOIN reservations r ON r.id = p.reservation_id WHERE r.ref = ?', [$done])));
        self::assertNotSame('', (string) $db->fetchOne('SELECT sha256 FROM reservation_proofs p INNER JOIN reservations r ON r.id = p.reservation_id WHERE r.ref = ?', [$done]), 'empreinte gardée');
        self::assertStringContainsString('0 capture(s)', $this->purge());
    }

    /** @param array<string, mixed> $input */
    private function command(array $input, array $answers = []): string
    {
        $this->em()->clear();
        $tester = new CommandTester((new Application(static::$kernel))->find('app:customer'));
        $tester->setInputs($answers);
        $tester->execute($input);
        $this->em()->clear();

        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function purge(): string
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:purge'));
        $tester->execute([]);
        $this->em()->clear();

        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function statusAfter(callable $request): int
    {
        $request();

        return $this->httpStatus();
    }

    private function lastCode(): ?string
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true)['code'] ?? null;
    }
}
