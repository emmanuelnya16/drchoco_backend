<?php

namespace App\Tests\Api\Push;

use App\Entity\Admin;
use App\Push\PushSender;
use App\Tests\AdminReservationTestCase;
use App\Tests\Fake\RecordingPushTransport;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Notifications push des réservations (P7) : destinataires, textes, photo du colis, rappels, erreurs du service
 * de push (nouvel essai, appareil désabonné, clés refusées), comptes bloqués.
 */
final class PushNotificationsTest extends AdminReservationTestCase
{
    private const CLIENT_DEVICE = 'https://fcm.googleapis.com/fcm/send/telephone-client';
    private const OWNER_DEVICE = 'https://fcm.googleapis.com/fcm/send/telephone-proprietaire';

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport()->reset();
    }

    private function transport(): RecordingPushTransport
    {
        return static::getContainer()->get('test.push_transport');
    }

    private function subscribe(string $uri, string $token, string $endpoint): void
    {
        $this->client->request('POST', $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(PushSubscriptionTest::subscription($endpoint)));
        self::assertSame(201, $this->httpStatus(), (string) $this->client->getResponse()->getContent());
    }

    /** @return list<array<string, mixed>> contenus envoyés à cet appareil */
    private function payloads(string $endpoint): array
    {
        return array_column($this->transport()->sentTo($endpoint), 'payload');
    }

    private function console(string $command): string
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($command));
        $tester->execute([]);

        return $tester->getDisplay();
    }

    /** @return array{client: string, owner: string, ref: string} client abonné, réservation avec preuve */
    private function subscribedReservation(array $body = []): array
    {
        $owner = $this->ownerToken();
        $this->subscribe('/api/admin/push/subscribe', $owner, self::OWNER_DEVICE);
        $client = $this->loginNamed();
        $this->subscribe('/api/push/subscribe', $client, self::CLIENT_DEVICE);
        $data = $this->reserve($client, $body !== [] ? $body : $this->pickup());
        self::assertSame(201, $this->httpStatus());
        $this->uploadProof($client, $data['ref'], $this->file(self::pngWithText('Comment', 'preuve ' . $data['ref'])));
        self::assertSame(201, $this->httpStatus());

        return ['client' => $client, 'owner' => $owner, 'ref' => $data['ref']];
    }

    public function testTheTeamHearsAboutNewReservationsAndProofs(): void
    {
        $owner = $this->ownerToken();
        $this->subscribe('/api/admin/push/subscribe', $owner, self::OWNER_DEVICE);
        $this->subscribe('/api/admin/push/subscribe', $this->sellerToken('Yaoundé'), 'https://fcm.googleapis.com/fcm/send/vendeur-yaounde');
        $this->subscribe('/api/admin/push/subscribe', $this->sellerToken('Douala'), 'https://fcm.googleapis.com/fcm/send/vendeur-douala');
        $inactive = $this->createAdmin(Admin::ROLE_MANAGER, 'ancien.gerant@drchoco.cm', null, false);
        $this->subscribe('/api/push/subscribe', $this->loginNamed('+237690000002', 'Paul'), 'https://fcm.googleapis.com/fcm/send/autre-client');
        $this->em()->getConnection()->insert('push_subscriptions', ['owner_kind' => 'admin', 'admin_id' => $inactive->getId(), 'endpoint' => 'https://fcm.googleapis.com/fcm/send/gerant-parti', 'endpoint_hash' => hash('sha256', 'x'), 'p256dh' => 'x', 'auth' => 'x', 'content_encoding' => 'aes128gcm', 'platform' => 'web', 'created_at' => '2026-01-01 00:00:00', 'last_seen_at' => '2026-01-01 00:00:00', 'failure_count' => 0]);

        $client = $this->loginNamed();
        $ref = $this->reserve($client, $this->pickup())['ref'];

        $new = $this->payloads(self::OWNER_DEVICE);
        self::assertCount(1, $new);
        self::assertSame(['type' => 'new_reservation', 'title' => 'Nouvelle réservation', 'body' => "$ref · Junior Mbarga · 45\u{202F}000\u{202F}FCFA · Retrait à Yaoundé", 'url' => "/admin/reservations/$ref", 'ref' => $ref, 'tag' => "admin-$ref"], $new[0]);
        self::assertCount(1, $this->payloads('https://fcm.googleapis.com/fcm/send/vendeur-yaounde'), 'vendeur de la ville des articles');
        self::assertSame([], $this->payloads('https://fcm.googleapis.com/fcm/send/vendeur-douala'), 'pas l’autre ville');
        self::assertSame([], $this->payloads('https://fcm.googleapis.com/fcm/send/gerant-parti'), 'compte désactivé');
        self::assertSame([], $this->payloads('https://fcm.googleapis.com/fcm/send/autre-client'), 'jamais les clients');

        $this->transport()->reset();
        $this->uploadProof($client, $ref, $this->file());
        $sent = $this->transport()->sent();
        self::assertCount(1, $sent, 'preuve reçue : super admins seulement');
        self::assertSame([self::OWNER_DEVICE, 'proof_received', 'Preuve de paiement à vérifier', "$ref · Orange Money · 45\u{202F}000\u{202F}FCFA", 'high'], [$sent[0]['endpoint'], $sent[0]['payload']['type'], $sent[0]['payload']['title'], $sent[0]['payload']['body'], $sent[0]['urgency']]);
    }

    public function testTheClientFollowsItsPickup(): void
    {
        ['owner' => $owner, 'ref' => $ref] = $this->subscribedReservation();
        $this->transport()->reset();

        $this->adminApi('POST', "/api/admin/reservations/$ref/validate-payment", $owner, []);
        $this->adminApi('POST', "/api/admin/reservations/$ref/status", $owner, ['status' => 'preparing']);
        $this->packageReady($owner, $ref, [$this->photo()]);
        $this->adminApi('POST', "/api/admin/reservations/$ref/status", $owner, ['status' => 'picked_up']);

        $sent = $this->transport()->sentTo(self::CLIENT_DEVICE);
        self::assertSame(['payment_validated', 'package_ready', 'delivered'], array_column(array_column($sent, 'payload'), 'type'), 'rien pour la préparation');
        [$validated, $ready, $done] = array_column($sent, 'payload');
        self::assertSame(['Paiement validé ✓', "$ref est confirmée. Nous préparons votre colis.", "/reservations/$ref", $ref], [$validated['title'], $validated['body'], $validated['url'], $validated['tag']]);
        self::assertSame(['Votre colis est prêt 📦', "$ref vous attend à la boutique de Yaoundé. Voyez la photo de votre colis.", "/reservations/$ref#colis"], [$ready['title'], $ready['body'], $ready['url']]);
        self::assertSame(['Colis retiré ✓', "$ref a été retiré à la boutique. Merci pour votre confiance !"], [$done['title'], $done['body']]);
        foreach ($sent as $message) {
            self::assertSame([$ref, 86400], [$message['topic'], $message['ttl']]);
            self::assertLessThan(4096, $message['bytes']);
        }
        self::assertSame([], $this->transport()->sentTo(self::OWNER_DEVICE), 'l’équipe n’est pas notifiée de ses propres actions');

        // Photo du colis : lien sans domaine, signé pour 25 h, servi par l'API.
        self::assertMatchesRegularExpression('#^/api/media/private/package-photos/\d+\?expires=\d+&signature=[0-9a-f]{64}$#', $ready['image']);
        parse_str((string) parse_url($ready['image'], \PHP_URL_QUERY), $query);
        self::assertGreaterThan($this->clock->now()->getTimestamp() + 24 * 3600, (int) $query['expires']);
        $this->clock->sleep(3 * 3600); // bien après le lien de l'écran (1 h)
        $this->client->request('GET', $ready['image']);
        self::assertSame([200, 'image/png'], [$this->httpStatus(), $this->header('Content-Type')]);
    }

    public function testRejectionReminderAndDeliveryTexts(): void
    {
        ['client' => $client, 'owner' => $owner, 'ref' => $ref] = $this->subscribedReservation($this->pickup(override: ['receptionMode' => 'delivery', 'city' => 'Douala', 'agencyId' => 'ag-dla-travel-lines']));
        $this->transport()->reset();

        $this->clock->sleep(20 * 3600);
        $this->adminApi('POST', "/api/admin/reservations/$ref/reject-payment", $owner, ['reason' => 'Montant incorrect.']);
        $rejected = $this->transport()->sentTo(self::CLIENT_DEVICE)[0];
        $deadline = (new \DateTimeImmutable('@' . ($this->clock->now()->getTimestamp() + 6 * 3600)))->setTimezone(new \DateTimeZone('Africa/Douala'));
        self::assertSame('payment_rejected', $rejected['payload']['type']);
        self::assertMatchesRegularExpression('/^' . preg_quote("$ref : Montant incorrect. Renvoyez votre preuve avant ", '/') . '(demain )?' . preg_quote($deadline->format('G') . "\u{202F}h\u{202F}" . $deadline->format('i') . '.', '/') . '$/u', $rejected['payload']['body']);
        self::assertSame(['high', 6 * 3600], [$rejected['urgency'], $rejected['ttl']], 'jusqu’à la nouvelle échéance');

        // Rappel 2 h avant la nouvelle échéance, par la tâche planifiée.
        $this->transport()->reset();
        $this->clock->sleep(4 * 3600 + 60);
        self::assertStringContainsString('1 rappel(s), 1 notification(s) envoyée(s)', $this->console('app:reservations:remind'));
        $reminder = $this->transport()->sentTo(self::CLIENT_DEVICE)[0];
        self::assertSame(['expiring_soon', 'Votre réservation expire bientôt', 'high'], [$reminder['payload']['type'], $reminder['payload']['title'], $reminder['urgency']]);
        self::assertStringEndsWith('Renvoyez votre preuve de paiement.', $reminder['payload']['body']);
        self::assertLessThanOrEqual(2 * 3600, $reminder['ttl']);

        // Puis la livraison par agence.
        $this->uploadProof($client, $ref, $this->file(self::pngWithText('Comment', 'nouvelle preuve')));
        $this->adminApi('POST', "/api/admin/reservations/$ref/validate-payment", $owner, []);
        $this->transport()->reset();
        $this->packageReady($owner, $ref, [$this->photo()]);
        $this->adminApi('POST', "/api/admin/reservations/$ref/status", $owner, ['status' => 'in_delivery']);
        $this->adminApi('POST', "/api/admin/reservations/$ref/status", $owner, ['status' => 'delivered']);
        self::assertSame([
            "$ref a été remis à Travel Lines Cameroun. Voyez la photo de votre colis.",
            "$ref part vers Douala.",
            "$ref a été livré. Merci pour votre confiance !",
        ], array_column(array_column($this->transport()->sentTo(self::CLIENT_DEVICE), 'payload'), 'body'));
    }

    public function testCancellationsAreAnnounced(): void
    {
        ['client' => $client, 'owner' => $owner, 'ref' => $ref] = $this->subscribedReservation();
        $this->adminApi('POST', "/api/admin/reservations/$ref/validate-payment", $owner, []);
        $this->transport()->reset();

        // Annulée par le client après paiement : l'équipe doit rembourser.
        $this->api('POST', "/api/reservations/$ref/cancel", $client, []);
        self::assertSame(200, $this->httpStatus());
        $team = $this->transport()->sentTo(self::OWNER_DEVICE);
        self::assertSame(['reservation_cancelled', 'Réservation annulée par le client', "$ref · paiement déjà validé (45\u{202F}000\u{202F}FCFA) : remboursement à prévoir.", "/admin/reservations/$ref"], [$team[0]['payload']['type'], $team[0]['payload']['title'], $team[0]['payload']['body'], $team[0]['payload']['url']]);
        self::assertSame([], $this->transport()->sentTo(self::CLIENT_DEVICE));

        // Annulée par la boutique après paiement : motif et remboursement annoncés au client.
        $other = $this->reserve($client, $this->pickup(self::DLA_VARIANT))['ref'];
        $this->uploadProof($client, $other, $this->file(self::pngWithText('Comment', 'preuve ' . $other)));
        $this->adminApi('POST', "/api/admin/reservations/$other/validate-payment", $owner, []);
        $this->transport()->reset();
        $this->adminApi('POST', "/api/admin/reservations/$other/cancel", $owner, ['reason' => 'Rupture de stock en boutique']);
        $message = $this->transport()->sentTo(self::CLIENT_DEVICE)[0];
        self::assertSame(['reservation_cancelled', 'Réservation annulée', "$other a été annulée par la boutique : Rupture de stock en boutique. La boutique vous contacte pour le remboursement.", 'high'], [$message['payload']['type'], $message['payload']['title'], $message['payload']['body'], $message['urgency']]);

        // Annulée par le client avant tout paiement : personne n'est dérangé.
        $this->transport()->reset();
        $unpaid = $this->reserve($client, $this->pickup())['ref'];
        $this->transport()->reset();
        $this->api('POST', "/api/reservations/$unpaid/cancel", $client, []);
        self::assertSame([], $this->transport()->sent());
    }

    public function testServiceErrorsAreRetriedOrCleanedUp(): void
    {
        ['owner' => $owner, 'ref' => $ref] = $this->subscribedReservation();
        $connection = $this->em()->getConnection();
        $sender = static::getContainer()->get(PushSender::class);

        // Erreur passagère : nouvel essai une minute plus tard.
        $this->transport()->reset();
        $this->transport()->respondWith([503]);
        $this->adminApi('POST', "/api/admin/reservations/$ref/validate-payment", $owner, []);
        $row = $connection->fetchAssociative("SELECT status, attempts, last_status FROM push_outbox WHERE type = 'payment_validated'");
        self::assertSame(['pending', 1, 503], [$row['status'], (int) $row['attempts'], (int) $row['last_status']]);
        self::assertSame(0, $sender->sendDue()['sent'], 'pas avant le délai');
        $this->clock->sleep(61);
        self::assertSame(1, $sender->sendDue()['sent']);
        self::assertSame(['sent', 2], array_values(array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $connection->fetchAssociative("SELECT status, attempts FROM push_outbox WHERE type = 'payment_validated'"))));

        // Clés refusées : échec signalé, appareil gardé.
        $this->transport()->respondWith([403]);
        $this->packageReady($owner, $ref, [$this->photo()]);
        self::assertSame('failed', $connection->fetchOne("SELECT status FROM push_outbox WHERE type = 'package_ready'"));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM push_subscriptions WHERE endpoint = ?", [self::CLIENT_DEVICE]));

        // Appareil désabonné : supprimé avec ses notifications.
        $this->transport()->respondWith([410]);
        $this->adminApi('POST', "/api/admin/reservations/$ref/status", $owner, ['status' => 'picked_up']);
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM push_subscriptions WHERE endpoint = ?", [self::CLIENT_DEVICE]));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM push_outbox WHERE type IN ('payment_validated', 'package_ready', 'delivered')"));

        // Après 5 essais, la notification est abandonnée.
        $this->transport()->respondWith(array_fill(0, 12, 500));
        $awa = $this->loginNamed('+237690000003', 'Awa');
        $this->reserve($awa, $this->pickup(self::DLA_VARIANT, override: ['phone' => '+237690000003']));
        for ($i = 0; $i < 6; ++$i) {
            $this->clock->sleep(3601);
            $sender->sendDue();
        }
        self::assertSame(['dropped', 5], array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, array_values($connection->fetchAssociative("SELECT status, attempts FROM push_outbox WHERE type = 'new_reservation' ORDER BY id DESC LIMIT 1"))));
    }

    public function testAnUnreachableDeviceNeitherDelaysTheOthersNorStaysForever(): void
    {
        ['client' => $client, 'owner' => $owner] = $this->subscribedReservation();
        $connection = $this->em()->getConnection();
        $sender = static::getContainer()->get(PushSender::class);
        $ownerDevice = (int) $connection->fetchOne('SELECT id FROM push_subscriptions WHERE endpoint = ?', [self::OWNER_DEVICE]);
        $connection->executeStatement('DELETE FROM push_outbox');

        // Cinq messages pour un appareil qui ne répond pas : un seul essai par passage, les autres attendent.
        for ($i = 0; $i < 5; ++$i) {
            $connection->executeStatement("INSERT INTO push_outbox (subscription_id, type, payload, ttl, urgency, status, attempts, next_attempt_at, expires_at, created_at)
                VALUES (?, 'new_reservation', '{}', 86400, 'normal', 'pending', 0, ?, ?, ?)", [$ownerDevice, $now = $this->clock->now()->format('Y-m-d H:i:s'), $this->clock->now()->modify('+1 day')->format('Y-m-d H:i:s'), $now]);
        }
        $this->transport()->reset();
        $this->transport()->respondWith(array_fill(0, 40, 0));
        $sender->sendDue();
        self::assertCount(1, $this->transport()->sent());
        self::assertSame(1, (int) $connection->fetchOne('SELECT SUM(attempts) FROM push_outbox'));

        // Panne de quelques heures, même avec beaucoup d'échecs : l'appareil est gardé.
        for ($i = 0; $i < 12; ++$i) {
            $this->clock->sleep(3601);
            $sender->sendDue();
        }
        $kept = fn (): int => (int) $connection->fetchOne('SELECT COUNT(*) FROM push_subscriptions WHERE id = ?', [$ownerDevice]);
        self::assertSame(1, $kept());
        self::assertGreaterThanOrEqual(10, (int) $connection->fetchOne('SELECT failure_count FROM push_subscriptions WHERE id = ?', [$ownerDevice]));

        // Injoignable depuis plus de 7 jours : retiré, avec ses messages.
        $queue = function () use ($connection, $ownerDevice): void {
            $connection->executeStatement("INSERT INTO push_outbox (subscription_id, type, payload, ttl, urgency, status, attempts, next_attempt_at, expires_at, created_at)
                VALUES (?, 'new_reservation', '{}', 86400, 'normal', 'pending', 0, ?, ?, ?)", [$ownerDevice, $now = $this->clock->now()->format('Y-m-d H:i:s'), $this->clock->now()->modify('+1 day')->format('Y-m-d H:i:s'), $now]);
        };
        $this->clock->modify('+8 days');
        $queue();
        $this->transport()->respondWith([0]);
        $sender->sendDue();
        self::assertSame(0, $kept());
        self::assertNotSame('', $client . $owner);
    }

    public function testRefusedKeysNeverRemoveADevice(): void
    {
        $this->subscribedReservation();
        $connection = $this->em()->getConnection();
        $sender = static::getContainer()->get(PushSender::class);
        $device = (int) $connection->fetchOne('SELECT id FROM push_subscriptions WHERE endpoint = ?', [self::OWNER_DEVICE]);
        foreach ([401, 403, 429, 401, 403, 429, 401, 403, 429, 401, 403, 429] as $status) {
            $connection->executeStatement('DELETE FROM push_outbox');
            $connection->executeStatement("INSERT INTO push_outbox (subscription_id, type, payload, ttl, urgency, status, attempts, next_attempt_at, expires_at, created_at)
                VALUES (?, 'new_reservation', '{}', 86400, 'normal', 'pending', 0, ?, ?, ?)", [$device, $now = $this->clock->now()->format('Y-m-d H:i:s'), $this->clock->now()->modify('+1 day')->format('Y-m-d H:i:s'), $now]);
            $this->transport()->respondWith([$status]);
            $sender->sendDue();
            $this->clock->modify('+1 day');
        }
        self::assertSame([1, 0], array_map('intval', array_values((array) $connection->fetchAssociative('SELECT COUNT(*), MAX(failure_count) FROM push_subscriptions WHERE id = ?', [$device]))));
    }

    public function testNothingIsLostWhileVapidKeysAreMissing(): void
    {
        ['owner' => $owner, 'ref' => $ref] = $this->subscribedReservation();
        $this->transport()->reset();
        $this->transport()->configured = false;

        $this->adminApi('POST', "/api/admin/reservations/$ref/validate-payment", $owner, []);
        self::assertSame([], $this->transport()->sent());
        self::assertSame('pending', $this->em()->getConnection()->fetchOne("SELECT status FROM push_outbox WHERE type = 'payment_validated'"));

        $this->transport()->configured = true;
        self::assertStringContainsString('1 envoyée(s)', $this->console('app:push:send'));
        self::assertSame('payment_validated', $this->transport()->sentTo(self::CLIENT_DEVICE)[0]['payload']['type']);
    }

    public function testBlockedClientsGetNothing(): void
    {
        ['client' => $client, 'owner' => $owner, 'ref' => $ref] = $this->subscribedReservation();
        $this->em()->getConnection()->executeStatement("UPDATE users SET status = 'blocked' WHERE phone = ?", [self::PHONE]);
        $this->transport()->reset();

        $this->adminApi('POST', "/api/admin/reservations/$ref/validate-payment", $owner, []);
        self::assertSame([], $this->transport()->sentTo(self::CLIENT_DEVICE));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM push_outbox WHERE type = 'payment_validated'"));
    }

    public function testALateRetryNeverReplacesANewerNotification(): void
    {
        ['owner' => $owner, 'ref' => $ref] = $this->subscribedReservation();
        $this->transport()->reset();
        $this->transport()->respondWith([503]);
        $this->adminApi('POST', "/api/admin/reservations/$ref/validate-payment", $owner, []);
        $this->packageReady($owner, $ref, [$this->photo()]);
        self::assertSame(['payment_validated', 'package_ready'], array_column(array_column($this->transport()->sentTo(self::CLIENT_DEVICE), 'payload'), 'type'));

        $this->clock->sleep(61);
        $this->transport()->reset();
        static::getContainer()->get(PushSender::class)->sendDue();
        self::assertSame([], $this->transport()->sent(), '« Paiement validé » arrivé trop tard : pas envoyé après « Colis prêt »');
        self::assertSame(['dropped', 'remplacée par une notification plus récente'], array_values($this->em()->getConnection()->fetchAssociative("SELECT status, last_error FROM push_outbox WHERE type = 'payment_validated'")));
    }

    public function testReasonsEndingWithAnAccentStayReadable(): void
    {
        ['owner' => $owner, 'ref' => $ref] = $this->subscribedReservation();
        $this->transport()->reset();
        $this->adminApi('POST', "/api/admin/reservations/$ref/reject-payment", $owner, ['reason' => 'Capture envoyée déjà.']);

        $body = $this->transport()->sentTo(self::CLIENT_DEVICE)[0]['payload']['body'] ?? '';
        self::assertStringStartsWith("$ref : Capture envoyée déjà. Renvoyez votre preuve avant ", $body);
    }

    public function testAnUnusedTeamDeviceStopsReceivingNames(): void
    {
        $owner = $this->ownerToken();
        $this->subscribe('/api/admin/push/subscribe', $owner, self::OWNER_DEVICE);
        $db = $this->em()->getConnection();

        // Administration non ouverte depuis 31 jours sur cet appareil (téléphone perdu, ancien employé) : plus rien.
        $db->executeStatement('UPDATE push_subscriptions SET last_seen_at = ?', [$this->clock->now()->modify('-31 days')->format('Y-m-d H:i:s')]);
        $this->reserve($this->loginNamed(), $this->pickup());
        self::assertSame([], $this->transport()->sentTo(self::OWNER_DEVICE));

        $db->executeStatement('UPDATE push_subscriptions SET last_seen_at = ?', [$this->clock->now()->modify('-29 days')->format('Y-m-d H:i:s')]);
        $this->reserve($this->loginNamed('+237690000002', 'Paul'), $this->pickup(self::DLA_VARIANT, override: ['phone' => '+237690000002']));
        self::assertCount(1, $this->transport()->sentTo(self::OWNER_DEVICE));
    }

    public function testACancellationBeforeTheProofIsCheckedWarnsTheTeam(): void
    {
        ['client' => $client, 'owner' => $owner, 'ref' => $ref] = $this->subscribedReservation();
        $this->transport()->reset();
        $this->api('POST', "/api/reservations/$ref/cancel", $client, []);
        self::assertSame(200, $this->httpStatus());
        $team = $this->transport()->sentTo(self::OWNER_DEVICE);
        self::assertSame(["$ref · preuve de paiement envoyée (45\u{202F}000\u{202F}FCFA) : à vérifier puis rembourser.", 'high'], [$team[0]['payload']['body'], $team[0]['urgency']]);

        // Annulée par la boutique avant d'avoir examiné la preuve : le client est prévenu du remboursement.
        $other = $this->reserve($client, $this->pickup())['ref'];
        $this->uploadProof($client, $other, $this->file(self::pngWithText('Comment', 'preuve ' . $other)));
        $this->transport()->reset();
        $this->adminApi('POST', "/api/admin/reservations/$other/cancel", $owner, ['reason' => 'Rupture de stock']);
        self::assertSame("$other a été annulée par la boutique : Rupture de stock. Si vous avez payé, la boutique vous contacte pour le remboursement.", $this->transport()->sentTo(self::CLIENT_DEVICE)[0]['payload']['body']);

        // Preuve rejetée puis annulation : rien à rembourser, personne n'est dérangé.
        $third = $this->reserve($client, $this->pickup())['ref'];
        $this->uploadProof($client, $third, $this->file(self::pngWithText('Comment', 'preuve ' . $third)));
        $this->adminApi('POST', "/api/admin/reservations/$third/reject-payment", $owner, ['reason' => 'Montant incorrect']);
        $this->transport()->reset();
        $this->api('POST', "/api/reservations/$third/cancel", $client, []);
        self::assertSame([], $this->transport()->sent());
    }
}
