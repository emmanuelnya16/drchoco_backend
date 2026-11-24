<?php

namespace App\Command;

use App\Seed\CatalogSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * php bin/console app:seed — charge les données de départ (catalogue, looks, livraison, pages, réglages).
 *
 *   app:seed                         tout ce qui manque (rien n'est écrasé ni supprimé)
 *   app:seed --only=pages,settings   seulement certaines parties
 *   app:seed --update                reprend aussi les valeurs du fichier pour ce qui existe déjà
 *
 * En production, seule la base de la boutique (catégories, livraison, pages, réglages) est chargée, après confirmation :
 * les produits et les looks du fichier sont fictifs, il faut les demander (--only=products,looks). Une ville, une
 * agence ou un look supprimé dans l'administration reviendrait aussi.
 */
#[AsCommand(name: 'app:seed', description: 'Charge les données de départ de data/seed (sans rien supprimer)')]
final class SeedCommand extends Command
{
    /** Parties fictives (démonstration) : jamais chargées en production sans les demander. */
    private const DEMO_SECTIONS = ['products', 'looks'];

    public function __construct(
        private readonly CatalogSeeder $seeder,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Parties à charger, séparées par des virgules : ' . implode(', ', CatalogSeeder::SECTIONS))
            ->addOption('update', null, InputOption::VALUE_NONE, 'Écrase les éléments existants avec les valeurs du fichier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $production = $this->environment === 'prod';
        $sections = $production ? array_values(array_diff(CatalogSeeder::SECTIONS, self::DEMO_SECTIONS)) : CatalogSeeder::SECTIONS;
        if (\is_string($input->getOption('only'))) {
            $sections = array_values(array_filter(array_map('trim', explode(',', $input->getOption('only')))));
            $unknown = array_diff($sections, CatalogSeeder::SECTIONS);
            if ($unknown !== []) {
                $io->error('Partie inconnue : ' . implode(', ', $unknown));

                return Command::INVALID;
            }
        }

        $question = sprintf('Site en ligne : charger %s ? Ce qui a été supprimé dans l’administration reviendra (oui / non)', implode(', ', $sections));
        if ($production && !$io->askQuestion(new ConfirmationQuestion($question, false, '/^(?:o|y)/i'))) {
            $io->note('Rien n’a été chargé.');

            return Command::SUCCESS;
        }

        $update = (bool) $input->getOption('update');
        $question = 'Les éléments existants vont reprendre les valeurs du fichier (vos modifications seront perdues). Continuer ? (oui / non)';
        if ($update && !$io->askQuestion(new ConfirmationQuestion($question, false, '/^(?:o|y)/i'))) {
            return Command::SUCCESS;
        }

        $report = $this->seeder->seed($sections, $update);

        $rows = [];
        foreach ($report as $section => $count) {
            $rows[] = [$section, $count['created'], $count['updated'], $count['skipped']];
        }
        $io->table(['Partie', 'Créés', 'Mis à jour', 'Déjà présents'], $rows);
        $io->success('Données de départ chargées.');

        return Command::SUCCESS;
    }
}
