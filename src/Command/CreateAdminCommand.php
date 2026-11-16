<?php

namespace App\Command;

use App\Admin\SellerManager;
use App\Api\ApiException;
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
 * Crée un compte de l'équipe en ligne de commande : gérant (super admin) ou vendeur rattaché à une ville.
 * Les vendeurs se créent d'habitude depuis l'administration (écran « Vendeurs ») ; le propriétaire avec
 * app:create-owner. Le mot de passe est demandé masqué (jamais dans l'historique du terminal).
 *
 *   php bin/console app:create-admin --role=seller --city=Douala --name="Vendeur Douala" --email=vendeur.douala@drchoco.cm
 *   php bin/console app:create-admin --role=manager --name="Gérant" --email=gerant@drchoco.cm
 */
#[AsCommand(name: 'app:create-admin', description: 'Crée un compte gérant (manager) ou vendeur (seller, Yaoundé ou Douala).')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly SellerManager $sellers,
        private readonly AdminRepository $admins,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'manager ou seller', 'seller')
            ->addOption('city', null, InputOption::VALUE_REQUIRED, 'Ville du vendeur : Yaoundé ou Douala')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom affiché')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'E-mail de connexion')
            ->addOption('phone', null, InputOption::VALUE_REQUIRED, 'Téléphone +2376XXXXXXXX (facultatif)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $role = (string) $input->getOption('role');
        if (!\in_array($role, [Admin::ROLE_MANAGER, Admin::ROLE_SELLER], true)) {
            $io->error('Rôle inconnu : manager ou seller (le propriétaire se crée avec app:create-owner).');

            return Command::INVALID;
        }

        $name = $input->getOption('name') ?: $io->ask('Nom affiché');
        $email = $input->getOption('email') ?: $io->ask('E-mail de connexion');
        $phone = $input->getOption('phone') ?: $io->ask('Téléphone (+2376XXXXXXXX, facultatif)', '');
        $city = $role === Admin::ROLE_SELLER ? ($input->getOption('city') ?: $io->choice('Ville', ['Yaoundé', 'Douala'])) : null;
        $password = (string) $io->askHidden(sprintf('Mot de passe (masqué, %d caractères minimum)', SellerManager::PASSWORD_MIN_LENGTH));

        if ($role === Admin::ROLE_SELLER) {
            try {
                $seller = $this->sellers->create(['name' => $name, 'email' => $email, 'phone' => $phone, 'password' => $password, 'city' => $city]);
            } catch (ApiException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $io->success(sprintf('Compte vendeur créé : %s (%s)', $seller->getEmail(), $seller->getCity()));

            return Command::SUCCESS;
        }

        $problem = SellerManager::passwordProblem($password, [(string) $email, (string) $phone]);
        if ($problem !== null) {
            $io->error($problem);

            return Command::FAILURE;
        }
        $manager = (new Admin())
            ->setName(trim((string) $name))
            ->setEmail((string) $email)
            ->setPhone($phone !== '' ? (string) $phone : null)
            ->setRole(Admin::ROLE_MANAGER)
            ->setCity(null)
            ->setIsActive(true);
        $manager->setPassword($this->hasher->hashPassword($manager, $password));

        $violations = $this->validator->validate($manager);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error(sprintf('%s : %s', $violation->getPropertyPath(), $violation->getMessage()));
            }

            return Command::FAILURE;
        }
        if ($this->admins->isTaken($manager->getEmail(), $manager->getPhone())) {
            $io->error('Cet e-mail ou ce téléphone est déjà utilisé par un autre compte.');

            return Command::FAILURE;
        }

        $this->admins->save($manager, flush: true);
        $io->success(sprintf('Compte gérant créé : %s', $manager->getEmail()));

        return Command::SUCCESS;
    }
}
