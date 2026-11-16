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
            ('h2', 'user', 1, 'f2', '2027-02-01 00:00:00', '2026-11-01 00:00:00', 0, 0)");

        $lockDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/lock';
        @mkdir($lockDir, 0777, true);
        touch($old = $lockDir . '/sf.test-purge-ancien.abc.lock', time() - 3 * 86400);
        touch($fresh = $lockDir . '/sf.test-purge-recent.abc.lock');

        $tester = new CommandTester((new Application(self::$kernel))->find('app:purge'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('1 code(s) SMS, 1 session(s)', $tester->getDisplay());
        self::assertFileDoesNotExist($old);
        self::assertFileExists($fresh);
        unlink($fresh);

        self::assertSame(['2026-11-15 12:00:00'], $db->fetchFirstColumn('SELECT created_at FROM otp_codes'));
        self::assertSame(['h2'], $db->fetchFirstColumn('SELECT token_hash FROM refresh_tokens'));
    }
}
