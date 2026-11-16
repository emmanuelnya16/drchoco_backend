<?php

namespace App\Command;

use App\Repository\OtpCodeRepository;
use App\Repository\RefreshTokenRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Nettoyage quotidien (tâche planifiée cPanel, voir README) :
 *  - codes de connexion SMS de plus de 48 h (ils ne servent plus qu'aux limites sur 24 h) ;
 *  - sessions (jetons de rafraîchissement) expirées ;
 *  - fichiers de verrou de var/lock inutilisés depuis 48 h (un par numéro, adresse IP ou client ayant servi).
 *
 *   php bin/console app:purge
 *
 * Sans danger : ne touche à aucune session encore valable ni à aucun compte.
 */
#[AsCommand(name: 'app:purge', description: 'Supprime les codes SMS de plus de 48 h, les sessions expirées et les vieux verrous (à lancer chaque jour).')]
final class PurgeCommand extends Command
{
    public const OTP_RETENTION_HOURS = 48;

    public function __construct(
        private readonly OtpCodeRepository $otpCodes,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.project_dir%/var/lock')]
        private readonly string $lockDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));

        $otp = $this->otpCodes->deleteOlderThan($now->modify(sprintf('-%d hours', self::OTP_RETENTION_HOURS)));
        $sessions = $this->refreshTokens->deleteExpired($now);
        $locks = $this->purgeLockFiles($now->getTimestamp() - self::OTP_RETENTION_HOURS * 3600);

        $io->success(sprintf('%d code(s) SMS, %d session(s) expirée(s) et %d fichier(s) de verrou supprimés.', $otp, $sessions, $locks));

        return Command::SUCCESS;
    }

    /** Fichiers de verrou plus anciens que $before et libres (un verrou en cours d'utilisation est laissé). */
    private function purgeLockFiles(int $before): int
    {
        $deleted = 0;
        foreach (glob($this->lockDir . '/sf.*.lock') ?: [] as $file) {
            if ((int) @filemtime($file) >= $before) {
                continue;
            }
            $handle = @fopen($file, 'r+');
            if ($handle === false) {
                continue;
            }
            $free = flock($handle, \LOCK_EX | \LOCK_NB);
            if ($free) {
                flock($handle, \LOCK_UN);
            }
            fclose($handle);
            if ($free && @unlink($file)) {
                ++$deleted;
            }
        }

        return $deleted;
    }
}
