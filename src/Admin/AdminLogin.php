<?php

namespace App\Admin;

use App\Account\PhoneNumber;
use App\Api\ApiException;
use App\Api\ClientIp;
use App\Entity\Admin;
use App\Repository\AdminRepository;
use App\Service\Auth\RefreshTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Connexion de l'équipe (super admins et vendeurs) : e-mail ou téléphone + mot de passe (contrat admin §2).
 *
 * - Mot de passe vérifié par le hacheur de Symfony (bcrypt, comme le backend de référence), mis à niveau
 *   automatiquement si besoin. Un identifiant inconnu fait la même vérification sur un hachage leurre : même
 *   temps de réponse qu'un mot de passe faux (le leurre est calculé une seule fois puis gardé en cache).
 * - 20 mots de passe faux en 15 min par adresse IP (contrôlé en premier, sous verrou), puis 5 par COMPTE :
 *   e-mail et téléphone d'un même compte partagent le même compteur. Un identifiant qui n'est pas en ASCII
 *   simple (lettres accentuées, chiffres pleine largeur…) est traité comme inconnu : la base compare sans
 *   accents, une variante accentuée ne doit pas ouvrir un nouveau compteur sur le même compte.
 * - Compte désactivé : 403 ACCOUNT_DISABLED, seulement après un mot de passe juste (rien n'est révélé sinon).
 * - Session admin courte et séparée de celle des clients (12 h au plus, RefreshTokenService, P0).
 */
final class AdminLogin
{
    public function __construct(
        private readonly AdminRepository $admins,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly RefreshTokenService $refreshTokens,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly LockFactory $lockFactory,
        #[Autowire(service: 'limiter.admin_login_identifier')]
        private readonly RateLimiterFactoryInterface $accountLimiter,
        #[Autowire(service: 'limiter.admin_login_ip')]
        private readonly RateLimiterFactoryInterface $ipLimiter,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {}

    /** @return array{admin: Admin, accessToken: string, cookie: Cookie} */
    public function login(string $identifier, string $password, Request $request): array
    {
        // 1. Adresse IP : une connexion à la fois, et pas plus de 20 échecs en 15 min.
        $ipKey = ClientIp::limiterKey($request->getClientIp(), $this->secret);
        $ipLock = $this->lockFactory->createLock('admin-login-ip-' . $ipKey, 15);
        $ipLock->acquire(true);

        try {
            $byIp = $this->ipLimiter->create($ipKey);
            $this->assertNotBlocked($byIp);

            // 2. Compte : même compteur pour son e-mail et son téléphone.
            $normalized = self::normalizeIdentifier($identifier);
            $admin = $this->findAccount($normalized);
            $accountKey = $admin !== null
                ? 'compte-' . $admin->getId()
                : 'inconnu-' . substr(hash_hmac('sha256', $normalized, $this->secret), 0, 32);

            $accountLock = $this->lockFactory->createLock('admin-login-' . $accountKey, 15);
            $accountLock->acquire(true);

            try {
                return $this->checkPassword($admin, $password, $request, $this->accountLimiter->create($accountKey), $byIp);
            } finally {
                $accountLock->release();
            }
        } finally {
            $ipLock->release();
        }
    }

    /** E-mail en minuscules, ou téléphone camerounais au format +2376XXXXXXXX. */
    public static function normalizeIdentifier(string $identifier): string
    {
        return PhoneNumber::normalizeCameroonMobile($identifier) ?? mb_strtolower(trim($identifier));
    }

    // ── Interne ───────────────────────────────────────────────────────────

    private function findAccount(string $normalized): ?Admin
    {
        // Les e-mails enregistrés sont en ASCII (FILTER_VALIDATE_EMAIL), les téléphones en +237 et chiffres.
        if (preg_match('/^[\x21-\x7E]+$/D', $normalized) !== 1) {
            return null;
        }

        return str_contains($normalized, '@')
            ? $this->admins->findByEmail($normalized)
            : $this->admins->findByPhone($normalized);
    }

    /** @return array{admin: Admin, accessToken: string, cookie: Cookie} */
    private function checkPassword(?Admin $admin, string $password, Request $request, LimiterInterface $byAccount, LimiterInterface $byIp): array
    {
        $this->assertNotBlocked($byAccount);

        $valid = $admin !== null
            ? $this->hasher->isPasswordValid($admin, $password)
            : $this->spendSameTime($password);

        if (!$valid) {
            $byAccount->consume();
            $byIp->consume();
            $this->logger->notice('Connexion admin refusée : identifiant ou mot de passe incorrect.');

            throw new ApiException(401, 'INVALID_CREDENTIALS', 'Identifiant ou mot de passe incorrect.');
        }

        $byAccount->reset();

        if (!$admin->isActive()) {
            throw new ApiException(403, 'ACCOUNT_DISABLED', 'Ce compte a été désactivé par le Super Admin.');
        }

        if ($this->hasher->needsRehash($admin)) {
            $admin->setPassword($this->hasher->hashPassword($admin, $password));
        }
        $admin->setLastLoginAt(new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp()));
        $this->em->flush();

        $session = $this->refreshTokens->createForAdmin($admin, $request);
        $accessToken = $this->refreshTokens->createAccessToken($admin, $session['refreshToken']->getSessionExpiresAt());

        return ['admin' => $admin, 'accessToken' => $accessToken, 'cookie' => $session['cookie']];
    }

    private function assertNotBlocked(LimiterInterface $limiter): void
    {
        $check = $limiter->consume(0); // lecture seule : aucun jeton pris
        if ($check->getRemainingTokens() > 0) {
            return;
        }

        $retryAfter = max(1, $check->getRetryAfter()->getTimestamp() - time());

        throw ApiException::tooManyRequests(
            'TOO_MANY_ATTEMPTS',
            sprintf("Trop de tentatives. Réessayez dans %d\u{00A0}min.", max(1, (int) ceil($retryAfter / 60))),
            $retryAfter,
        );
    }

    /**
     * Identifiant inconnu : une vérification de mot de passe sur un hachage leurre, pour ne pas révéler
     * l'absence du compte par le temps de réponse. Le leurre est calculé une fois (même algorithme et même coût
     * que les vrais comptes) puis gardé dans le cache de l'application.
     */
    private function spendSameTime(string $password): bool
    {
        $probe = (new Admin())->setEmail('inconnu@drchoco.invalid');
        $probe->setPassword($this->cache->get('admin_login_decoy_hash_v1', fn () => $this->hasher->hashPassword($probe, bin2hex(random_bytes(16)))));
        $this->hasher->isPasswordValid($probe, $password);

        return false;
    }
}
