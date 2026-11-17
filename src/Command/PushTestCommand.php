<?php

namespace App\Command;

use App\Account\PhoneNumber;
use App\Push\PushMessage;
use App\Push\PushQueue;
use App\Push\PushSender;
use App\Repository\AdminRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Envoie une notification d'essai à tous les appareils d'un compte (client : son téléphone ; équipe : son e-mail).
 *
 *     php bin/console app:push:test 690000001
 *     php bin/console app:push:test proprietaire@drchoco.cm
 */
#[AsCommand(name: 'app:push:test', description: 'Envoie une notification d’essai aux appareils d’un compte.')]
final class PushTestCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AdminRepository $admins,
        private readonly PushQueue $queue,
        private readonly PushSender $sender,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('compte', InputArgument::REQUIRED, 'Téléphone du client ou e-mail du membre de l’équipe');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $account = trim((string) $input->getArgument('compte'));

        if (str_contains($account, '@')) {
            $admin = $this->admins->findByEmail($account);
            if ($admin === null) {
                $io->error('Aucun membre de l’équipe avec cet e-mail.');

                return Command::FAILURE;
            }
            $queued = $this->queue->toAdmins([(int) $admin->getId()], new PushMessage('new_reservation', 'Notification d’essai', 'Les notifications de l’administration fonctionnent sur cet appareil.', '/admin', tag: 'test'));
        } else {
            $phone = PhoneNumber::normalizeCameroonMobile($account);
            $user = $phone !== null ? $this->users->findByPhone($phone) : null;
            if ($user === null) {
                $io->error('Aucun client avec ce numéro.');

                return Command::FAILURE;
            }
            $queued = $this->queue->toUser($user, new PushMessage('new_collection', 'Notification d’essai', 'Les notifications DR Choco fonctionnent sur cet appareil.', '/', tag: 'test'));
        }

        if ($queued === 0) {
            $io->warning('Ce compte n’a aucun appareil abonné aux notifications.');

            return Command::FAILURE;
        }
        $stats = $this->sender->sendDue($queued, 30.0);
        $io->success(sprintf('%d appareil(s) : %d envoyée(s), %d à réessayer, %d en échec, %d désabonné(s).', $queued, $stats['sent'], $stats['retried'], $stats['failed'], $stats['removed']));

        return Command::SUCCESS;
    }
}
