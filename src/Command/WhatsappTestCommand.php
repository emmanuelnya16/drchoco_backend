<?php

namespace App\Command;

use App\Account\PhoneNumber;
use App\Auth\Whatsapp\WhatsappCloudMessenger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Envoie UN message WhatsApp d'essai depuis le numéro de la boutique, exactement comme une réponse du bot : vérifie
 * que le serveur joint Meta et que le jeton WHATSAPP_TOKEN permet de répondre. Gratuit dans les 24 h qui suivent un
 * message du client : écrire d'abord au numéro de la boutique depuis ce téléphone.
 *
 *   php bin/console app:whatsapp:essai 690000001
 */
#[AsCommand(name: 'app:whatsapp:essai', description: 'Envoie un message WhatsApp d’essai depuis le numéro de la boutique.')]
final class WhatsappTestCommand extends Command
{
    public function __construct(private readonly WhatsappCloudMessenger $messenger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('phone', InputArgument::REQUIRED, 'Numéro WhatsApp camerounais (ex. 690000001 ou +237690000001)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $phone = PhoneNumber::normalizeCameroonMobile((string) $input->getArgument('phone'));
        if ($phone === null) {
            $io->error('Numéro invalide : 9 chiffres commençant par 6.');

            return Command::INVALID;
        }
        $io->note('Écrivez d’abord au numéro WhatsApp de la boutique depuis ce téléphone (moins de 24 h avant) : sinon Meta n’accepte pas ce message.');
        if (!$io->confirm(sprintf('Envoyer un message WhatsApp d’essai au %s ?', $phone), false)) {
            $io->note('Aucun message envoyé.');

            return Command::SUCCESS;
        }

        $reason = $this->messenger->deliver($phone, 'DR Choco : message d’essai. Si vous le recevez, le serveur peut répondre sur WhatsApp et envoyer les codes de connexion.');
        if ($reason !== null) {
            $io->error('Meta refuse le message : ' . $reason . '. Codes fréquents : 190 jeton faux ou expiré ; 10 ou 200 permission manquante ; 100 WHATSAPP_PHONE_NUMBER_ID faux ; 131030 numéro hors de la liste de test.');

            return Command::FAILURE;
        }
        $io->success('Message accepté par Meta. S’il n’arrive pas sur le téléphone, la raison s’affiche dans quelques secondes : php bin/console app:whatsapp:journal');

        return Command::SUCCESS;
    }
}
