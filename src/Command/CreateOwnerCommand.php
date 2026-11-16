<?php

namespace App\Command;

use App\Admin\SellerManager;
use App\Entity\Admin;
use App\Repository\AdminRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Crée le compte propriétaire (Super Admin) de DR Choco.
 * Repris de la commande app:create-super-admin du backend de référence.
 *
 *   php bin/console app:create-owner
 *   php bin/console app:create-owner --name="..." --email="..." --phone="+2376XXXXXXXX"
 *
 * Le mot de passe est toujours demandé de façon masquée (jamais en paramètre : il resterait
 * dans l'historique du terminal).
 */
#[AsCommand(
    name: 'app:create-owner',
    description: 'Crée le compte propriétaire (Super Admin) de DR Choco.',
)]
class CreateOwnerCommand extends Command
{
    public function __construct(
        private readonly AdminRepository             $adminRepository,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface          $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom affiché')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'E-mail de connexion')
            ->addOption('phone', null, InputOption::VALUE_REQUIRED, 'Téléphone +2376XXXXXXXX (facultatif)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Création du compte propriétaire — DR Choco L’Incompris');

        $existing = $this->adminRepository->findOwner();
        if ($existing !== null) {
            $io->warning(sprintf('Un propriétaire existe déjà : %s (%s)', $existing->getName(), $existing->getEmail()));

            return Command::SUCCESS;
        }

        $name  = $input->getOption('name')  ?: $io->ask('Nom affiché', 'DR Choco');
        $email = $input->getOption('email') ?: $io->ask('E-mail de connexion');
        $phone = $input->getOption('phone') ?: $io->ask('Téléphone (+2376XXXXXXXX, facultatif)', '');
        $password = (string) $io->askHidden('Mot de passe (masqué, 10 caractères minimum)');

        // Même règle que tous les comptes de l'équipe (10 caractères, ni mot de passe connu, ni e-mail ou téléphone).
        $problem = SellerManager::passwordProblem($password, [(string) $email, (string) $phone]);
        if ($problem !== null) {
            $io->error($problem);

            return Command::FAILURE;
        }

        $admin = (new Admin())
            ->setName((string) $name)
            ->setEmail((string) $email)
            ->setPhone($phone !== '' ? (string) $phone : null)
            ->setRole(Admin::ROLE_OWNER)
            ->setCity(null)
            ->setIsActive(true);
        $admin->setPassword($this->hasher->hashPassword($admin, $password));

        $violations = $this->validator->validate($admin);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error(sprintf('%s : %s', $violation->getPropertyPath(), $violation->getMessage()));
            }

            return Command::FAILURE;
        }

        if ($this->adminRepository->findByEmail($admin->getEmail()) !== null) {
            $io->error('Cet e-mail est déjà utilisé par un autre compte.');

            return Command::FAILURE;
        }

        $this->adminRepository->save($admin, flush: true);
        $io->success(sprintf('Compte propriétaire créé : %s', $admin->getEmail()));

        return Command::SUCCESS;
    }
}
