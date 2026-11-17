<?php

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Crée les clés VAPID des notifications push (une seule fois par site : en changer désabonne tous les appareils).
 *
 *     php bin/console app:push:vapid-keys --write=.env.local
 *
 * Avec `--write`, les clés sont écrites dans ce fichier (clé privée jamais affichée) ; seule la clé publique est
 * montrée, à recopier dans le front (`VITE_VAPID_PUBLIC_KEY`). Sous Windows/XAMPP, lancer avec `OPENSSL_CONF`.
 */
#[AsCommand(name: 'app:push:vapid-keys', description: 'Crée les clés VAPID des notifications push.')]
final class PushVapidKeysCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('write', null, InputOption::VALUE_REQUIRED, 'Fichier où écrire les clés (ex. .env.local)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Remplacer des clés déjà présentes dans le fichier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            $io->error('Création des clés impossible : ' . $e->getMessage() . ' (Windows/XAMPP : définir OPENSSL_CONF=C:\xampp\php\extras\ssl\openssl.cnf avant de lancer PHP).');

            return Command::FAILURE;
        }

        $file = $input->getOption('write');
        if (!\is_string($file) || $file === '') {
            $io->writeln('VAPID_PUBLIC_KEY=' . $keys['publicKey']);
            $io->writeln('VAPID_PRIVATE_KEY=' . $keys['privateKey']);
            $io->note('Clé privée : seulement dans .env.local du serveur. Clé publique : aussi dans le front (VITE_VAPID_PUBLIC_KEY).');

            return Command::SUCCESS;
        }

        $path = str_starts_with($file, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $file) === 1 ? $file : $this->projectDir . '/' . $file;
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        if (preg_match('/^VAPID_PRIVATE_KEY=.+$/m', $content) === 1 && !$input->getOption('force')) {
            $io->error('Des clés VAPID existent déjà dans ce fichier (--force pour les remplacer : tous les appareils devront se réabonner).');

            return Command::FAILURE;
        }
        $content = preg_replace('/^VAPID_(PUBLIC|PRIVATE)_KEY=.*\R?/m', '', $content) ?? $content;
        $content = rtrim($content) . ($content !== '' ? "\n" : '') . 'VAPID_PUBLIC_KEY=' . $keys['publicKey'] . "\nVAPID_PRIVATE_KEY=" . $keys['privateKey'] . "\n";
        file_put_contents($path, $content);

        $io->success('Clés VAPID écrites dans ' . $file . '.');
        $io->writeln('Clé publique, à mettre dans le front (VITE_VAPID_PUBLIC_KEY) : ' . $keys['publicKey']);

        return Command::SUCCESS;
    }
}
