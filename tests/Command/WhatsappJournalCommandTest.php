<?php

namespace App\Tests\Command;

use App\Command\WhatsappJournalCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * php bin/console app:whatsapp:journal : dernières lignes du journal WhatsApp, fichiers du plus ancien au plus récent.
 */
final class WhatsappJournalCommandTest extends TestCase
{
    public function testShowsTheLastLinesAcrossDays(): void
    {
        $directory = sys_get_temp_dir() . '/drchoco-wa-journal-' . bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            $tester = new CommandTester(new WhatsappJournalCommand($directory));
            self::assertSame(0, $tester->execute([]));
            self::assertStringContainsString('Aucun journal', $tester->getDisplay());

            file_put_contents($directory . '/whatsapp-2026-11-26.log', "[26/11/2026 09:00:00] INFO : ligne 1\n[26/11/2026 09:00:01] INFO : ligne 2\n");
            file_put_contents($directory . '/whatsapp-2026-11-27.log', "[27/11/2026 10:37:12] INFO : ligne 3\n");
            file_put_contents($directory . '/prod-2026-11-27.log', "autre journal\n");

            self::assertSame(0, $tester->execute(['--lignes' => '2']));
            $display = $tester->getDisplay();
            self::assertStringNotContainsString('ligne 1', $display);
            self::assertLessThan(strpos($display, 'ligne 3'), strpos($display, 'ligne 2'), 'ordre chronologique');
            self::assertStringNotContainsString('autre journal', $display);
        } finally {
            array_map('unlink', glob($directory . '/*') ?: []);
            rmdir($directory);
        }
    }
}
