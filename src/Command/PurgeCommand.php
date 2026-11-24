<?php

namespace App\Command;

use App\Push\PushQueue;
use App\Repository\OtpCodeRepository;
use App\Repository\RefreshTokenRepository;
use App\Reservation\ReservationFileRetention;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Nettoyage (tâche planifiée cPanel, chaque heure : voir README) :
 *  - codes de connexion SMS de plus de 48 h (ils ne servent plus qu'aux limites sur 24 h) ;
 *  - sessions (jetons de rafraîchissement) expirées, et sessions fermées depuis 7 jours ;
 *  - fichiers de verrou de var/lock inutilisés depuis 2 h (un par numéro, adresse IP ou client ayant servi : ils ne
 *    durent que quelques secondes mais restent sur le disque, dont le nombre de fichiers est limité) ;
 *  - compteurs de limites de débit périmés (fichiers du cache) ;
 *  - notifications push envoyées, en échec ou abandonnées depuis plus de 7 jours ;
 *  - appareils de l'équipe dont l'administration n'a pas été ouverte depuis 30 jours ;
 *  - appareils abonnés aux notifications sans activité depuis 90 jours (ni abonnement renouvelé, ni envoi réussi)
 *    et dont le compte n'a plus de session ouverte (un client resté connecté garde ses notifications) ;
 *  - captures de paiement et photos de colis de plus de 12 mois (réservations terminées).
 *
 *   php bin/console app:purge
 *
 * Sans danger : ne touche à aucune session encore valable ni à aucun compte.
 */
#[AsCommand(name: 'app:purge', description: 'Supprime les codes SMS de plus de 48 h, les sessions expirées, les vieux verrous, les vieilles notifications et les images de plus de 12 mois (à lancer chaque heure).')]
final class PurgeCommand extends Command
{
    public const OTP_RETENTION_HOURS = 48;
    public const LOCK_RETENTION_HOURS = 2;
    public const PUSH_RETENTION_DAYS = 7;
    public const DONE_TOKEN_RETENTION_DAYS = 7;
    public const PUSH_DEVICE_IDLE_DAYS = 90;

    public function __construct(
        private readonly OtpCodeRepository $otpCodes,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly ClockInterface $clock,
        private readonly Connection $db,
        private readonly ReservationFileRetention $files,
        #[Autowire('%kernel.project_dir%/var/lock')]
        private readonly string $lockDir,
        #[Autowire(service: 'cache.rate_limiter')]
        private readonly CacheItemPoolInterface $limiterCache,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));

        $otp = $this->otpCodes->deleteOlderThan($now->modify(sprintf('-%d hours', self::OTP_RETENTION_HOURS)));
        $sessions = $this->refreshTokens->deleteExpired($now, $now->modify(sprintf('-%d days', self::DONE_TOKEN_RETENTION_DAYS)));
        $locks = $this->purgeLockFiles($now->getTimestamp() - self::LOCK_RETENTION_HOURS * 3600);
        $notifications = $this->db->executeStatement(
            "DELETE FROM push_outbox WHERE status <> 'pending' AND created_at < ?",
            [$now->modify(sprintf('-%d days', self::PUSH_RETENTION_DAYS))->format('Y-m-d H:i:s')],
        );
        if ($this->limiterCache instanceof PruneableInterface) {
            $this->limiterCache->prune();
        }
        $teamDevices = $this->db->executeStatement(
            "DELETE FROM push_subscriptions WHERE owner_kind = 'admin' AND last_seen_at < ?",
            [$now->modify(sprintf('-%d days', PushQueue::ADMIN_DEVICE_IDLE_DAYS))->format('Y-m-d H:i:s')],
        );
        $idle = $now->modify(sprintf('-%d days', self::PUSH_DEVICE_IDLE_DAYS))->format('Y-m-d H:i:s');
        $devices = $this->db->executeStatement(
            'DELETE FROM push_subscriptions WHERE last_seen_at < ? AND (last_success_at IS NULL OR last_success_at < ?)
             AND NOT EXISTS (SELECT 1 FROM refresh_tokens t WHERE t.user_type = push_subscriptions.owner_kind
                             AND t.user_id = COALESCE(push_subscriptions.user_id, push_subscriptions.admin_id)
                             AND t.is_revoked = 0 AND t.expires_at > ?)',
            [$idle, $idle, $now->format('Y-m-d H:i:s')],
        );

        $images = $this->files->purgeOld();

        $io->success(sprintf(
            '%d code(s) SMS, %d session(s) expirée(s), %d fichier(s) de verrou, %d notification(s) et %d appareil(s) inactif(s) supprimés ; %d capture(s) ou photo(s) de plus de %d mois effacée(s).',
            $otp, $sessions, $locks, $notifications, $devices + $teamDevices, $images, ReservationFileRetention::MONTHS,
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
