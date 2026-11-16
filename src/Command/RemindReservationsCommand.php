<?php

namespace App\Command;

use App\Repository\ReservationRepository;
use App\Reservation\ReservationLifecycle;
use App\Reservation\ReservationNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Tâche planifiée (cPanel, toutes les 15 minutes) : rappel aux clients dont la réservation expire dans moins
 * de 2 h sans paiement, une seule fois par réservation. P5 : rappel écrit dans le journal ; notification push en P7.
 *
 *     php bin/console app:reservations:remind
 */
#[AsCommand(name: 'app:reservations:remind', description: 'Rappelle les réservations non payées qui expirent dans moins de 2 h.')]
final class RemindReservationsCommand extends Command
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ReservationLifecycle $lifecycle,
        private readonly ReservationNotifier $notifier,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $locks,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = $this->locks->createLock('reservations-remind', 300);
        if (!$lock->acquire()) {
            $io->note('Un autre passage est en cours.');

            return Command::SUCCESS;
        }
        try {
            $now = $this->lifecycle->now();
            $due = $this->reservations->dueForReminder($now, $now->modify('+2 hours'));
            foreach ($due as $reservation) {
                $reservation->setExpiringNotifiedAt($now);
            }
            $this->em->flush();
            foreach ($due as $reservation) {
                $this->notifier->expiringSoon($reservation);
            }
        } finally {
            $lock->release();
        }
        $io->success(sprintf('%d rappel(s).', \count($due)));

        return Command::SUCCESS;
    }
}
