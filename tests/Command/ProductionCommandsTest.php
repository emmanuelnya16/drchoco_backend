<?php

namespace App\Tests\Command;

use App\Command\PushVapidKeysCommand;
use App\Command\SeedCommand;
use App\Seed\CatalogSeeder;
use App\Tests\CatalogTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Commandes lancées sur le serveur : données de départ sans article fictif, clés VAPID écrites d'un coup, diagnostic de
 * mise en ligne.
 */
final class ProductionCommandsTest extends CatalogTestCase
{
    public function testSeedNeverPutsDemoArticlesOnline(): void
    {
        $seed = fn (string $environment): CommandTester => new CommandTester(new SeedCommand(static::getContainer()->get(CatalogSeeder::class), $environment));

        // En ligne : question posée ; sans « oui », rien.
        $tester = $seed('prod');
        $tester->setInputs(['']);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Rien n’a été chargé', $tester->getDisplay());

        $tester = $seed('prod');
        $tester->setInputs(['oui']);
        self::assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();
        foreach (['categories', 'delivery', 'pages', 'settings'] as $section) {
            self::assertStringContainsString($section, $display);
        }
        self::assertDoesNotMatchRegularExpression('/^\s*(products|looks)\s/m', $display);

        // Demandés explicitement : chargés (après la question).
        $tester = $seed('prod');
        $tester->setInputs(['oui']);
        $tester->execute(['--only' => 'looks']);
        self::assertMatchesRegularExpression('/^\s*looks\s/m', $tester->getDisplay());

        // En développement : tout, sans question.
        $tester = $seed('dev');
        self::assertSame(0, $tester->execute([]));
        self::assertMatchesRegularExpression('/^\s*products\s/m', $tester->getDisplay());
    }

    public function testVapidKeysAreWrittenAtOnce(): void
    {
        $directory = sys_get_temp_dir() . '/drc-vapid-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $file = $directory . '/.env.local';
        try {
            file_put_contents($file, "APP_SECRET=secret-existant\nVAPID_PUBLIC_KEY=\n");
            $tester = new CommandTester(new PushVapidKeysCommand($directory));
            self::assertSame(0, $tester->execute(['--write' => '.env.local']), $tester->getDisplay());
            $content = (string) file_get_contents($file);
            self::assertStringStartsWith("APP_SECRET=secret-existant\n", $content, 'les autres secrets restent');
            self::assertSame(1, preg_match('/^VAPID_PRIVATE_KEY=(\S{20,})$/m', $content, $m));
            self::assertSame(1, preg_match_all('/^VAPID_PUBLIC_KEY=\S{60,}$/m', $content));
            self::assertStringNotContainsString($m[1], $tester->getDisplay(), 'clé privée jamais affichée');
            self::assertSame(['.env.local'], array_values(array_diff((array) scandir($directory), ['.', '..'])), 'aucun fichier temporaire');

            // Déjà présentes : refusé sans --force.
            self::assertSame(1, (new CommandTester(new PushVapidKeysCommand($directory)))->execute(['--write' => '.env.local']));
            self::assertSame($content, file_get_contents($file));

            // Dossier absent : échec annoncé, rien n'est dit réussi.
            $tester = new CommandTester(new PushVapidKeysCommand($directory));
            self::assertSame(1, $tester->execute(['--write' => 'dossier-absent/.env.local']));
            self::assertStringContainsString('Écriture impossible', $tester->getDisplay());
        } finally {
            @unlink($file);
            @rmdir($directory);
        }
    }

    public function testDiagnosticFailsWhenAProductionSettingIsMissing(): void
    {
        // Environnement de test : pas la production, localhost accepté par CORS… le diagnostic de mise en ligne échoue.
        $command = (new Application(static::$kernel))->find('app:diagnostic');
        $tester = new CommandTester($command);
        self::assertSame(1, $tester->execute(['--production' => true]));
        self::assertStringContainsString('APP_ENV=prod', $tester->getDisplay());
        self::assertStringNotContainsString((string) static::getContainer()->getParameter('kernel.secret'), $tester->getDisplay());
    }
}
