<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dernières lignes du journal de la connexion par WhatsApp (var/log/whatsapp-AAAA-MM-JJ.log, 30 jours) : demandes de
 * l'app, messages reçus de Meta, réponses envoyées ou refusées. Jamais de code ni de texte de message.
 *
 *   php bin/console app:whatsapp:journal              50 dernières lignes
 *   php bin/console app:whatsapp:journal --lignes=200
 */
#[AsCommand(name: 'app:whatsapp:journal', description: 'Affiche les dernières lignes du journal de la connexion par WhatsApp.')]
final class WhatsappJournalCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.logs_dir%')]
        private readonly string $logsDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('lignes', null, InputOption::VALUE_REQUIRED, 'Nombre de lignes', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $wanted = max(1, min(2000, (int) $input->getOption('lignes')));

        $files = glob($this->logsDir . '/whatsapp-*.log') ?: [];
        rsort($files); // le plus récent d'abord (date dans le nom)
        if ($files === []) {
            $io->warning(sprintf('Aucun journal dans %s : rien n’est encore arrivé (ni demande de l’app, ni message transmis par Meta).', $this->logsDir));

            return Command::SUCCESS;
        }

        $lines = [];
        foreach ($files as $file) {
            $content = @file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
            $lines = array_merge($content, $lines);
            if (\count($lines) >= $wanted) {
                break;
            }
        }
        $lines = \array_slice($lines, -$wanted);

        $io->title(sprintf('Journal WhatsApp — %d dernière(s) ligne(s), heure du Cameroun', \count($lines)));
        $output->writeln($lines, OutputInterface::OUTPUT_RAW);
        $io->newLine();
        $io->text('Fichiers : ' . $this->logsDir . '/whatsapp-AAAA-MM-JJ.log (gestionnaire de fichiers de cPanel).');

        return Command::SUCCESS;
    }
}
