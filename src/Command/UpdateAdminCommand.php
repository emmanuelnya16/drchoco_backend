<?php

namespace App\Command;

use App\Admin\AdminLogin;
use App\Admin\SellerManager;
use App\Audit\AuditLog;
use App\Entity\Admin;
use App\Entity\RefreshToken;
use App\Push\PushSubscriptions;
use App\Repository\AdminRepository;
use App\Service\Auth\RefreshTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Dépannage d'un compte de l'équipe, quel que soit son rôle (propriétaire, gérant, vendeur) :
 * nouveau mot de passe (demandé masqué) et/ou désactivation, réactivation. Toutes ses sessions sont coupées.
 *
 *   php bin/console app:admin:update owner@drchoco.cm --password
 *   php bin/console app:admin:update gerant@drchoco.cm --disable
 *   php bin/console app:admin:update 690000000 --enable
 *
 * Compte bloqué après 5 mots de passe faux : attendre 15 min, ou remettre les compteurs à zéro avec
 *   php bin/console cache:pool:clear cache.rate_limiter
 */
#[AsCommand(name: 'app:admin:update', description: 'Change le mot de passe d’un compte de l’équipe, ou le désactive / réactive.')]
final class UpdateAdminCommand extends Command
{
    public function __construct(
        private readonly AdminRepository $admins,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly RefreshTokenService $refreshTokens,
        private readonly PushSubscriptions $pushDevices,
        private readonly AuditLog $audit,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifiant', InputArgument::REQUIRED, 'E-mail ou téléphone du compte')
            ->addOption('password', null, InputOption::VALUE_NONE, 'Définir un nouveau mot de passe (demandé masqué)')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Désactiver le compte')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Réactiver le compte');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = AdminLogin::normalizeIdentifier((string) $input->getArgument('identifiant'));
        $admin = str_contains($identifier, '@') ? $this->admins->findByEmail($identifier) : $this->admins->findByPhone($identifier);
        if ($admin === null) {
            $io->error('Aucun compte de l’équipe avec cet identifiant.');

            return Command::FAILURE;
        }
        if ($input->getOption('disable') && $input->getOption('enable')) {
            $io->error('Choisissez --disable ou --enable, pas les deux.');

            return Command::INVALID;
        }
        if (!$input->getOption('password') && !$input->getOption('disable') && !$input->getOption('enable')) {
            $io->note(sprintf('%s — %s%s, %s. Options : --password, --disable, --enable.', $admin->getEmail(), $admin->getRole(), $admin->getCity() ? ' (' . $admin->getCity() . ')' : '', $admin->isActive() ? 'actif' : 'désactivé'));

            return Command::SUCCESS;
        }

        if ($input->getOption('disable') && $admin->getRole() === Admin::ROLE_OWNER && $this->activeOwners() <= 1) {
            $io->error('C’est le dernier propriétaire actif : il ne peut pas être désactivé.');

            return Command::FAILURE;
        }

        if ($input->getOption('password')) {
            $password = (string) $io->askHidden(sprintf('Nouveau mot de passe (masqué, %d caractères minimum)', SellerManager::PASSWORD_MIN_LENGTH));
            $problem = SellerManager::passwordProblem($password, [$admin->getEmail(), $admin->getPhone()]);
            if ($problem !== null) {
                $io->error($problem);

                return Command::FAILURE;
            }
            $admin->setPassword($this->hasher->hashPassword($admin, $password));
        }
        if ($input->getOption('disable')) {
            $admin->setIsActive(false);
        }
        if ($input->getOption('enable')) {
            $admin->setIsActive(true);
        }

        $this->em->wrapInTransaction(function () use ($admin): void {
            $this->em->flush();
            $this->refreshTokens->revokeAll((int) $admin->getId(), RefreshToken::TYPE_ADMIN);
            $this->pushDevices->forgetAdmin((int) $admin->getId());
        });
        $this->audit->record('admin.updated', null, ['account' => $admin->getId(), 'password' => (bool) $input->getOption('password'), 'active' => $admin->isActive(), 'by' => 'ligne de commande']);

        $io->success(sprintf('Compte %s mis à jour (%s). Toutes ses sessions sont coupées, ses appareils ne reçoivent plus de notifications.', $admin->getEmail(), $admin->isActive() ? 'actif' : 'désactivé'));

        return Command::SUCCESS;
    }

    private function activeOwners(): int
    {
        return \count($this->admins->findBy(['role' => Admin::ROLE_OWNER, 'isActive' => true]));
    }
}
