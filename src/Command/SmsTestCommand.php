<?php

namespace App\Command;

use App\Account\PhoneNumber;
use App\Service\Notification\SmsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Envoie UN SMS d'essai par Twilio, pour vérifier les identifiants et, avec un compte d'essai,
 * que le numéro est bien vérifié dans la console Twilio.
 *
 *   php bin/console app:sms:test 690000001
 *
 * Un SMS réel part (coût d'un SMS) : une confirmation est demandée.
 */
#[AsCommand(name: 'app:sms:test', description: 'Envoie un SMS d’essai par Twilio à un numéro camerounais.')]
final class SmsTestCommand extends Command
{
    public function __construct(private readonly SmsService $sms)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('phone', InputArgument::REQUIRED, 'Numéro mobile camerounais (ex. 690000001 ou +237690000001)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $phone = PhoneNumber::normalizeCameroonMobile((string) $input->getArgument('phone'));
        if ($phone === null) {
            $io->error('Numéro invalide : 9 chiffres commençant par 6.');

            return Command::INVALID;
        }

        if (!$io->confirm(sprintf('Envoyer un SMS d’essai au %s ?', $phone), false)) {
            $io->note('Aucun SMS envoyé.');

            return Command::SUCCESS;
        }

        if (!$this->sms->send($phone, 'DR Choco : SMS d\'essai. Si vous le recevez, l\'envoi des codes de connexion fonctionne.')) {
            $io->error('Échec de l’envoi. Avec un compte Twilio d’essai, le numéro doit être vérifié dans la console Twilio (Phone Numbers > Verified Caller IDs). Détail dans le journal PHP.');

            return Command::FAILURE;
        }

        $io->success('SMS envoyé.');

        return Command::SUCCESS;
    }
}
