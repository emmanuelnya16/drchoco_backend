<?php

namespace App\Command;

use App\Push\PushSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tâche planifiée (cPanel, chaque minute) : envoie les notifications push en attente et fait les nouveaux essais.
 *
 *     php bin/console app:push:send
 */
#[AsCommand(name: 'app:push:send', description: 'Envoie les notifications push en attente (à lancer chaque minute).')]
final class PushSendCommand extends Command
{
    public function __construct(private readonly PushSender $sender)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Durée maximale en secondes', '50')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de notifications', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stats = $this->sender->sendDue(max(1, (int) $input->getOption('limit')), max(1.0, (float) $input->getOption('time-limit')));
        (new SymfonyStyle($input, $output))->success(sprintf(
            '%d envoyée(s), %d à réessayer, %d en échec, %d abandonnée(s), %d appareil(s) désabonné(s).',
            $stats['sent'], $stats['retried'], $stats['failed'], $stats['dropped'], $stats['removed'],
        ));

        return Command::SUCCESS;
    }
}
