<?php

namespace App\Command;

use App\Auth\Whatsapp\WhatsappConfig;
use App\Auth\Whatsapp\WhatsappSubscription;
use App\Seo\SiteUrl;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Abonne l'app Meta « Dr Choco OTP » au compte WhatsApp Business, pour que Meta transmette les messages reçus à
 * l'adresse de rappel (WHATSAPP_OTP.md). Sans effet si l'abonnement existe déjà. Aucun message envoyé.
 *
 *   php bin/console app:whatsapp:abonner
 */
#[AsCommand(name: 'app:whatsapp:abonner', description: 'Abonne l’app Meta au compte WhatsApp Business : Meta transmet alors les messages reçus.')]
final class WhatsappSubscribeCommand extends Command
{
    public function __construct(
        private readonly WhatsappConfig $whatsapp,
        private readonly HttpClientInterface $httpClient,
        private readonly SiteUrl $site,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Abonnement de l’app Meta aux messages WhatsApp reçus');

        $before = WhatsappSubscription::read($this->httpClient, $this->whatsapp);
        if ($before['status'] === 'subscribed') {
            $io->success('Déjà abonnée : ' . implode(', ', $before['apps']) . '.');
            $this->checklist($io);

            return Command::SUCCESS;
        }
        if ($before['status'] === 'no_account' || ($before['status'] === 'error' && $this->whatsapp->token === '')) {
            $io->error($before['detail'] . ' (.env et .env.local).');

            return Command::FAILURE;
        }

        $result = WhatsappSubscription::subscribe($this->httpClient, $this->whatsapp);
        if (!$result['ok']) {
            $io->error('Abonnement refusé : ' . $result['detail'] . '. Le jeton WHATSAPP_TOKEN doit avoir la permission whatsapp_business_management.');

            return Command::FAILURE;
        }

        $after = WhatsappSubscription::read($this->httpClient, $this->whatsapp);
        $io->success('Abonnement fait' . ($after['apps'] !== [] ? ' : ' . implode(', ', $after['apps']) : '') . '.');
        $this->checklist($io);

        return Command::SUCCESS;
    }

    private function checklist(SymfonyStyle $io): void
    {
        $io->text([
            'À vérifier aussi dans la console Meta (developers.facebook.com, app « Dr Choco OTP », WhatsApp > Configuration) :',
            sprintf(' - adresse de rappel : %s (bouton « Vérifier et enregistrer » réussi) ;', $this->site->to('/api/webhooks/whatsapp')),
            ' - champ « messages » : Abonné ;',
            ' - app en mode « Live » (Publier).',
            'Puis écrivez au numéro de la boutique et lisez : php bin/console app:whatsapp:journal',
        ]);
    }
}
