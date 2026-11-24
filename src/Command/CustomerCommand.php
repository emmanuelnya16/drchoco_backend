<?php

namespace App\Command;

use App\Account\PhoneNumber;
use App\Admin\CustomerManager;
use App\Api\ApiException;
use App\Api\ApiJson;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Compte d'un client (demande faite à la boutique, téléphone volé, abus) :
 *
 *   php bin/console app:customer 690000000 --block --reason="Fausses preuves répétées"
 *   php bin/console app:customer 690000000 --unblock
 *   php bin/console app:customer 690000000 --close-sessions          téléphone perdu ou volé
 *   php bin/console app:customer 690000000 --export=client.json      copie de ses données (droit d'accès)
 *   php bin/console app:customer 690000000 --erase                   effacement définitif (droit à l'oubli)
 *
 * Vérifier d'abord que la demande vient bien du client (appel sur ce numéro). Le blocage se fait aussi dans
 * l'administration, écran « Clients ».
 */
#[AsCommand(name: 'app:customer', description: 'Bloque, débloque, déconnecte, exporte ou efface un client.')]
final class CustomerCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly CustomerManager $customers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('telephone', InputArgument::REQUIRED, 'Numéro du client')
            ->addOption('block', null, InputOption::VALUE_NONE, 'Bloquer le compte (sessions et notifications coupées)')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Motif du blocage (journal d’audit)')
            ->addOption('unblock', null, InputOption::VALUE_NONE, 'Débloquer le compte')
            ->addOption('close-sessions', null, InputOption::VALUE_NONE, 'Déconnecter tous ses appareils')
            ->addOption('export', null, InputOption::VALUE_REQUIRED, 'Écrire ses données dans ce fichier JSON')
            ->addOption('erase', null, InputOption::VALUE_NONE, 'Effacer définitivement ses données personnelles');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $phone = PhoneNumber::normalizeCameroonMobile((string) $input->getArgument('telephone'));
        $user = $phone !== null ? $this->users->findByPhone($phone) : null;
        if ($user === null) {
            $io->error('Aucun client avec ce numéro.');

            return Command::FAILURE;
        }

        $actions = array_values(array_filter(['block', 'unblock', 'close-sessions', 'export', 'erase'], fn (string $o) => (bool) $input->getOption($o)));
        if (\count($actions) !== 1) {
            $io->error('Choisissez une seule action : --block, --unblock, --close-sessions, --export=fichier.json ou --erase.');

            return Command::INVALID;
        }

        try {
            return match ($actions[0]) {
                'block'          => $this->done($io, fn () => $this->customers->block($user, null, $input->getOption('reason')), 'Client bloqué : sessions et notifications coupées.'),
                'unblock'        => $this->done($io, fn () => $this->customers->unblock($user, null), 'Client débloqué.'),
                'close-sessions' => $this->done($io, fn () => $this->customers->closeSessions($user, null), 'Client déconnecté de tous ses appareils.'),
                'export'         => $this->export($io, $user, (string) $input->getOption('export')),
                default          => $this->erase($io, $user),
            };
        } catch (ApiException $e) {
            $io->error($e->getMessage() . (isset($e->getDetails()['refs']) ? ' (' . implode(', ', $e->getDetails()['refs']) . ')' : ''));

            return Command::FAILURE;
        }
    }

    private function done(SymfonyStyle $io, callable $action, string $message): int
    {
        $action();
        $io->success($message);

        return Command::SUCCESS;
    }

    private function export(SymfonyStyle $io, \App\Entity\User $user, string $file): int
    {
        $handle = @fopen($file, 'x'); // jamais par-dessus un fichier existant
        if ($handle === false) {
            $io->error('Fichier impossible à créer (existe-t-il déjà ?) : ' . $file);

            return Command::FAILURE;
        }
        @chmod($file, 0600);
        fwrite($handle, json_encode($this->customers->export($user, null), ApiJson::ENCODING | \JSON_PRETTY_PRINT) . "\n");
        fclose($handle);
        $io->success('Données écrites dans ' . $file . ' : à remettre au client, puis à supprimer du serveur.');

        return Command::SUCCESS;
    }

    private function erase(SymfonyStyle $io, \App\Entity\User $user): int
    {
        $question = sprintf('Effacer définitivement les données de %s ? Impossible à annuler (oui / non)', PhoneNumber::mask($user->getPhone()));
        if (!$io->askQuestion(new ConfirmationQuestion($question, false, '/^(?:o|y)/i'))) {
            $io->note('Rien n’a été effacé.');

            return Command::SUCCESS;
        }
        $result = $this->customers->erase($user, null);
        $io->success(sprintf('Client effacé : %d réservation(s) rendue(s) anonyme(s), %d image(s) supprimée(s).', $result['reservations'], $result['files']));

        return Command::SUCCESS;
    }
}
