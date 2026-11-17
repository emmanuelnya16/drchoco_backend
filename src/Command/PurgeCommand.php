<?php

namespace App\Command;

use App\Repository\OtpCodeRepository;
use App\Repository\RefreshTokenRepository;
use Doctrine\DBAL\Connection;
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
 *  - fichiers de verrou de var/lock inutilisés depuis 48 h (un par numéro, adresse IP ou client ayant servi) ;
 *  - notifications push envoyées, en échec ou abandonnées depuis plus de 7 jours ;
 *  - appareils abonnés aux notifications sans activité depuis 90 jours (ni abonnement renouvelé, ni envoi réussi)
 *    et dont le compte n'a plus de session ouverte (un client resté connecté garde ses notifications).
 *
 *   php bin/console app:purge
 *
 * Sans danger : ne touche à aucune session encore valable ni à aucun compte.
 */
#[AsCommand(name: 'app:purge', description: 'Supprime les codes SMS de plus de 48 h, les sessions expirées, les vieux verrous et les vieilles notifications (à lancer chaque jour).')]
final class PurgeCommand extends Command
{
    public const OTP_RETENTION_HOURS = 48;
    public const PUSH_RETENTION_DAYS = 7;
    public const PUSH_DEVICE_IDLE_DAYS = 90;

    public function __construct(
        private readonly OtpCodeRepository $otpCodes,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly ClockInterface $clock,
        private readonly Connection $db,
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
        $notifications = $this->db->executeStatement(
            "DELETE FROM push_outbox WHERE status <> 'pending' AND created_at < ?",
            [$now->modify(sprintf('-%d days', self::PUSH_RETENTION_DAYS))->format('Y-m-d H:i:s')],
        );
        $idle = $now->modify(sprintf('-%d days', self::PUSH_DEVICE_IDLE_DAYS))->format('Y-m-d H:i:s');
        $devices = $this->db->executeStatement(
            'DELETE FROM push_subscriptions WHERE last_seen_at < ? AND (last_success_at IS NULL OR last_success_at < ?)
             AND NOT EXISTS (SELECT 1 FROM refresh_tokens t WHERE t.user_type = push_subscriptions.owner_kind
                             AND t.user_id = COALESCE(push_subscriptions.user_id, push_subscriptions.admin_id)
                             AND t.is_revoked = 0 AND t.expires_at > ?)',
            [$idle, $idle, $now->format('Y-m-d H:i:s')],
        );

        $io->success(sprintf(
            '%d code(s) SMS, %d session(s) expirée(s), %d fichier(s) de verrou, %d notification(s) et %d appareil(s) inactif(s) supprimés.',
            $otp, $sessions, $locks, $notifications, $devices,
        ));

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
