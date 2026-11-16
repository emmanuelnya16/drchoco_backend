<?php

namespace App\Command;

use App\Reservation\ReservationLifecycle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Tâche planifiée (cPanel, toutes les 5 minutes) : réservations non payées à l'échéance → « expirée », articles
 * remis en vente. Les réservations en attente de validation du paiement n'expirent jamais.
 *
 *     php bin/console app:reservations:expire
 */
#[AsCommand(name: 'app:reservations:expire', description: 'Expire les réservations non payées à l’échéance et remet leurs articles en vente.')]
final class ExpireReservationsCommand extends Command
{
    public function __construct(
        private readonly ReservationLifecycle $lifecycle,
        private readonly LockFactory $locks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de réservations traitées', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // Deux passages simultanés (tâche lente) : le second s'arrête aussitôt.
        $lock = $this->locks->createLock('reservations-expire', 300);
        if (!$lock->acquire()) {
            $io->note('Un autre passage est en cours.');

            return Command::SUCCESS;
        }
        try {
            $count = $this->lifecycle->expireDue(null, max(1, (int) $input->getOption('limit')));
        } finally {
            $lock->release();
        }
        $io->success(sprintf('%d réservation(s) expirée(s).', $count));

        return Command::SUCCESS;
    }
}
