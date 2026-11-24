<?php

namespace App\Tests\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:purge : codes SMS de plus de 48 h et sessions expirées supprimés, le reste gardé.
 */
final class PurgeCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    public function testOldCodesAndExpiredSessionsAreDeleted(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-16 10:00:00');
        $db = static::getContainer()->get(Connection::class);
        foreach (['refresh_tokens', 'otp_codes'] as $table) {
            $db->executeStatement('DELETE FROM ' . $table);
        }

        $hash = str_repeat('a', 64);
        $db->executeStatement("INSERT INTO otp_codes (phone, code_hash, created_at, expires_at, attempts_left) VALUES
            ('+237690000001', '$hash', '2026-11-14 09:00:00', '2026-11-14 09:05:00', 5),
            ('+237690000001', '$hash', '2026-11-15 12:00:00', '2026-11-15 12:05:00', 5)");
        $db->executeStatement("INSERT INTO refresh_tokens (token_hash, user_type, user_id, family_id, expires_at, created_at, is_used, is_revoked) VALUES
            ('h1', 'user', 1, 'f1', '2026-11-15 00:00:00', '2026-08-01 00:00:00', 0, 0),
            ('h2', 'user', 1, 'f2', '2027-02-01 00:00:00', '2026-11-01 00:00:00', 0, 0),
            ('h3', 'user', 1, 'f2', '2027-02-01 00:00:00', '2026-11-01 00:00:00', 1, 0),
            ('h4', 'user', 1, 'f3', '2027-02-01 00:00:00', '2026-11-01 00:00:00', 0, 1),
            ('h5', 'user', 1, 'f3', '2027-02-01 00:00:00', '2026-11-15 00:00:00', 0, 1)");
        $db->executeStatement("UPDATE refresh_tokens SET used_at = '2026-11-02 00:00:00' WHERE token_hash = 'h3'");

        $lockDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/lock';
        @mkdir($lockDir, 0777, true);
        // Dates des fichiers par rapport à l'horloge figée du test (et non à la date réelle du passage).
        touch($old = $lockDir . '/sf.test-purge-ancien.abc.lock', strtotime('2026-11-16 10:00:00 UTC') - 3 * 3600);
        touch($fresh = $lockDir . '/sf.test-purge-recent.abc.lock', strtotime('2026-11-16 10:00:00 UTC') - 3600);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:purge'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('1 code(s) SMS, 2 session(s)', $tester->getDisplay());
        self::assertFileDoesNotExist($old);
        self::assertFileExists($fresh);
        unlink($fresh);

        self::assertSame(['2026-11-15 12:00:00'], $db->fetchFirstColumn('SELECT created_at FROM otp_codes'));
        // h3 : déjà utilisé, session encore ouverte (détection d'un cookie volé) ; h4 : session fermée depuis 15 jours.
        self::assertSame(['h2', 'h3', 'h5'], $db->fetchFirstColumn('SELECT token_hash FROM refresh_tokens ORDER BY token_hash'));
        $db->executeStatement('DELETE FROM refresh_tokens');
    }

    public function testOldNotificationsAndIdleDevicesAreDeleted(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-16 10:00:00');
        $db = static::getContainer()->get(Connection::class);
        $clean = function () use ($db): void {
            $db->executeStatement('DELETE FROM push_subscriptions');
            $db->executeStatement("DELETE FROM refresh_tokens WHERE family_id = 'f-connecte'");
            $db->executeStatement("DELETE FROM users WHERE phone = '+237690000077'");
        };
        $clean();
        try {

        $device = fn (string $name, string $lastSeen, ?string $lastSuccess) => $db->insert('push_subscriptions', [
            'owner_kind' => 'user', 'endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $name, 'endpoint_hash' => hash('sha256', $name),
            'p256dh' => 'x', 'auth' => 'x', 'content_encoding' => 'aes128gcm', 'platform' => 'web',
            'created_at' => '2026-06-01 00:00:00', 'last_seen_at' => $lastSeen, 'last_success_at' => $lastSuccess, 'failure_count' => 0,
        ]);
        $device('oublie', '2026-07-01 00:00:00', null);
        // Client toujours connecté (session ouverte) : son appareil est gardé même sans activité récente.
        $db->insert('users', ['phone' => '+237690000077', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00']);
        $connected = (int) $db->lastInsertId();
        $db->insert('refresh_tokens', ['token_hash' => str_repeat('c', 64), 'user_type' => 'user', 'user_id' => $connected, 'family_id' => 'f-connecte', 'expires_at' => '2027-02-01 00:00:00', 'created_at' => '2026-11-10 00:00:00', 'is_used' => 0, 'is_revoked' => 0]);
        $device('connecte', '2026-07-01 00:00:00', null);
        $db->executeStatement("UPDATE push_subscriptions SET user_id = ? WHERE endpoint LIKE '%connecte'", [$connected]);
        $device('utilise', '2026-07-01 00:00:00', '2026-11-01 00:00:00');
        $device('recent', '2026-11-10 00:00:00', null);
        $recent = (int) $db->fetchOne("SELECT id FROM push_subscriptions WHERE endpoint LIKE '%recent'");
        foreach ([['sent', '2026-11-01 00:00:00'], ['dropped', '2026-11-02 00:00:00'], ['pending', '2026-11-01 00:00:00'], ['sent', '2026-11-15 00:00:00']] as [$status, $at]) {
            $db->insert('push_outbox', [
                'subscription_id' => $recent, 'type' => 'delivered', 'payload' => '{}', 'ttl' => 86400, 'urgency' => 'normal', 'status' => $status,
                'attempts' => 1, 'next_attempt_at' => $at, 'expires_at' => $at, 'created_at' => $at,
            ]);
        }

        $tester = new CommandTester((new Application(self::$kernel))->find('app:purge'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('2 notification(s) et 1 appareil(s)', $tester->getDisplay());
        self::assertSame(['connecte', 'utilise', 'recent'], array_map(fn (string $e) => basename($e), $db->fetchFirstColumn('SELECT endpoint FROM push_subscriptions ORDER BY id')));
        self::assertSame(['pending', 'sent'], $db->fetchFirstColumn('SELECT status FROM push_outbox ORDER BY id'), 'en attente et récentes : gardées');
        } finally {
            $clean(); // base de test laissée propre, même si une vérification échoue
        }
    }
}
