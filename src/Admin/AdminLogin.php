<?php

namespace App\Admin;

use App\Account\PhoneNumber;
use App\Api\ApiException;
use App\Api\ClientIp;
use App\Api\LimiterStorage;
use App\Audit\AuditLog;
use App\Entity\Admin;
use App\Entity\RefreshToken;
use App\Push\PushSubscriptions;
use App\Repository\AdminRepository;
use App\Service\Auth\RefreshTokenService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
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
 * - Essais faux, en 15 min :
 *     • 20 par adresse IP ;
 *     • 5 pour un identifiant saisi depuis une même adresse IP (429, le front annonce 15 min) ;
 *     • 5 pour un COMPTE depuis une même adresse IP, e-mail et téléphone confondus (même réponse qu'un mot de passe
 *       faux : rien ne relie l'e-mail et le téléphone d'un compte) ;
 *     • 50 pour un compte, toutes adresses confondues : un inconnu ne peut pas bloquer un compte à lui seul. Un appareil
 *       déjà connu du compte (App\Admin\AdminDevice) n'est pas concerné : il a son propre compteur de 5 essais.
 *   Un identifiant qui n'est pas en ASCII simple (lettres accentuées, chiffres pleine largeur…) est traité comme
 *   inconnu : la base compare sans accents, une variante accentuée ne doit pas ouvrir un nouveau compteur.
 * - Compte désactivé : 403 ACCOUNT_DISABLED, seulement après un mot de passe juste (rien n'est révélé sinon).
 * - Session admin courte et séparée de celle des clients (12 h au plus, RefreshTokenService, P0). Elle est ouverte dans
 *   la transaction qui relit le compte : un mot de passe changé au même moment la refuse.
 * - Compteurs impossibles à écrire (disque plein) : 503, jamais d'essais sans limite.
 * - Connexions et refus sont écrits dans le journal d'audit.
 */
final class AdminLogin
{
    private const LOCK_WAIT_MILLISECONDS = 4000;

    public function __construct(
        private readonly AdminRepository $admins,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly RefreshTokenService $refreshTokens,
        private readonly PushSubscriptions $pushDevices,
        private readonly AdminDevice $devices,
        private readonly AuditLog $audit,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly LockFactory $lockFactory,
        #[Autowire(service: 'limiter.admin_login_identifier')]
        private readonly RateLimiterFactoryInterface $identifierLimiter,
        #[Autowire(service: 'limiter.admin_login_account')]
        private readonly RateLimiterFactoryInterface $accountLimiter,
        #[Autowire(service: 'limiter.admin_login_ip')]
        private readonly RateLimiterFactoryInterface $ipLimiter,
        private readonly CacheInterface $cache,
        private readonly LimiterStorage $limiterStorage,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {}

    /** @return array{admin: Admin, accessToken: string, cookies: list<Cookie>} */
    public function login(string $identifier, string $password, Request $request): array
    {
        $ipKey = ClientIp::limiterKey($request->getClientIp(), $this->secret);
        $byIp = $this->ipLimiter->create($ipKey);
        $this->assertNotBlocked($byIp); // une adresse déjà refusée n'attend même pas le verrou
        $this->limiterStorage->assertWritable();

        // Une connexion à la fois par adresse IP : contrôle et décompte sous le même verrou.
        $ipLock = $this->lock('admin-login-ip-' . $ipKey);
        try {
            $this->assertNotBlocked($byIp);

            $normalized = self::normalizeIdentifier($identifier);
            $admin = $this->findAccount($normalized);
            $identifierKey = 'identifiant-' . substr(hash_hmac('sha256', $normalized, $this->secret), 0, 32) . '-' . $ipKey;

            // Une connexion à la fois par compte (ou par identifiant inconnu).
            $accountLock = $this->lock('admin-login-' . ($admin !== null ? 'compte-' . $admin->getId() : $identifierKey));
            try {
                return $this->checkPassword($admin, $normalized, $password, $request, $this->identifierLimiter->create($identifierKey), $byIp, $ipKey);
            } finally {
                $accountLock->release();
            }
        } finally {
            $ipLock->release();
        }
    }

    /**
     * Nouveau mot de passe choisi par le titulaire du compte : l'ancien est demandé (5 essais par adresse IP, sous les mêmes
     * verrous que la connexion), ses autres sessions et ses appareils abonnés aux notifications sont coupés, une nouvelle
     * session est ouverte ici. Un mot de passe remplacé (ou un compte désactivé) au même moment par un super admin l'emporte.
     *
     * @return array{admin: Admin, accessToken: string, cookies: list<Cookie>}
     */
    public function changePassword(Admin $admin, string $current, string $new, Request $request): array
    {
        $ipKey = ClientIp::limiterKey($request->getClientIp(), $this->secret);
        $this->assertNotBlocked($this->ipLimiter->create($ipKey)); // une adresse déjà refusée n'attend même pas le verrou
        $this->limiterStorage->assertWritable();

        $ipLock = $this->lock('admin-login-ip-' . $ipKey);
        try {
            $accountLock = $this->lock('admin-login-compte-' . $admin->getId());
            try {
                return $this->replacePassword($admin, $current, $new, $request, $ipKey);
            } finally {
                $accountLock->release();
            }
        } finally {
            $ipLock->release();
        }
    }

    /** @return array{admin: Admin, accessToken: string, cookies: list<Cookie>} */
    private function replacePassword(Admin $admin, string $current, string $new, Request $request, string $ipKey): array
    {
        $byIp = $this->ipLimiter->create($ipKey);
        $byAccount = $this->identifierLimiter->create('compte-' . $admin->getId() . '-' . $ipKey);
        $this->assertNotBlocked($byIp);
        $this->assertNotBlocked($byAccount);

        $verifiedHash = $admin->getPassword();
        if (!$this->hasher->isPasswordValid($admin, $current)) {
            $byAccount->consume();
            $byIp->consume();
            $this->audit->record('admin.password_refused', $admin);

            throw ApiException::validation(['currentPassword' => 'Mot de passe actuel incorrect.'], 'Mot de passe actuel incorrect.');
        }
        $problem = AdminPassword::problem($new, [$admin->getEmail(), $admin->getPhone()])
            ?? ($new === $current ? 'Choisissez un mot de passe différent de l’actuel.' : null);
        if ($problem !== null) {
            throw ApiException::validation(['newPassword' => $problem], $problem);
        }

        $hash = $this->hasher->hashPassword($admin, $new);
        $session = $this->em->wrapInTransaction(function () use ($admin, $hash, $verifiedHash, $request): ?array {
            $this->em->refresh($admin, LockMode::PESSIMISTIC_WRITE);
            if ($admin->getPassword() !== $verifiedHash || !$admin->isActive()) {
                return null; // remplacé ou désactivé par un super admin pendant la vérification
            }
            $admin->setPassword($hash);
            $this->em->flush();
            $this->refreshTokens->revokeAll((int) $admin->getId(), RefreshToken::TYPE_ADMIN);
            $this->pushDevices->forgetAdmin((int) $admin->getId());

            return $this->refreshTokens->createForAdmin($admin, $request);
        });
        if ($session === null) {
            throw new ApiException(401, 'ADMIN_UNAUTHORIZED', 'Votre session a été fermée. Veuillez vous reconnecter.');
        }
        $byAccount->reset();
        $this->audit->record('admin.password_changed', $admin, ['by' => 'titulaire']);

        return $this->opened($admin, $session, $request);
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

    /** @return array{admin: Admin, accessToken: string, cookies: list<Cookie>} */
    private function checkPassword(?Admin $admin, string $normalized, string $password, Request $request, LimiterInterface $byIdentifier, LimiterInterface $byIp, string $ipKey): array
    {
        $this->assertNotBlocked($byIdentifier);

        $hidden = false;   // compte déjà bloqué depuis cette adresse par son autre identifiant
        $counters = [$byIdentifier, $byIp];
        if ($admin !== null) {
            $device = $this->devices->knownTo($admin, $request);
            $byAccount = $device !== null
                ? $this->identifierLimiter->create('appareil-' . $device)
                : $this->accountLimiter->create('compte-' . $admin->getId());
            $this->assertNotBlocked($byAccount);

            $byAccountHere = $this->identifierLimiter->create('compte-' . $admin->getId() . '-' . $ipKey);
            $hidden = $byAccountHere->consume(0)->getRemainingTokens() <= 0;
            $counters = [$byIdentifier, $byIp, $byAccount, $byAccountHere];
        }

        // Le mot de passe est toujours vérifié (même durée), mais jamais accepté quand le compte est bloqué.
        $verifiedHash = $admin?->getPassword();
        $valid = ($admin !== null ? $this->hasher->isPasswordValid($admin, $password) : $this->spendSameTime($password)) && !$hidden;

        if (!$valid) {
            $this->refuse($admin, $normalized, $hidden ? [$byIdentifier, $byIp] : $counters);
        }
        if (!$admin->isActive()) {
            $this->resetAll($counters, $byIp);

            throw new ApiException(403, 'ACCOUNT_DISABLED', 'Ce compte a été désactivé par le Super Admin.');
        }

        // Compte relu et verrouillé : un mot de passe changé (ou un compte désactivé) pendant la vérification l'emporte.
        $session = $this->em->wrapInTransaction(function () use ($admin, $password, $verifiedHash, $request): ?array {
            $this->em->refresh($admin, LockMode::PESSIMISTIC_WRITE);
            if ($admin->getPassword() !== $verifiedHash || !$admin->isActive()) {
                return null;
            }
            if ($this->hasher->needsRehash($admin)) {
                $admin->setPassword($this->hasher->hashPassword($admin, $password));
            }
            $admin->setLastLoginAt(new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp()));
            $this->em->flush();

            return $this->refreshTokens->createForAdmin($admin, $request);
        });
        if ($session === null) {
            $this->refuse($admin, $normalized, $counters);
        }

        $this->resetAll($counters, $byIp);
        $this->audit->record('admin.login', $admin);

        return $this->opened($admin, $session, $request);
    }

    /**
     * @param array{refreshToken: RefreshToken, cookie: Cookie} $session
     *
     * @return array{admin: Admin, accessToken: string, cookies: list<Cookie>}
     */
    private function opened(Admin $admin, array $session, Request $request): array
    {
        return [
            'admin'       => $admin,
            'accessToken' => $this->refreshTokens->createAccessToken($admin, $session['refreshToken']->getSessionExpiresAt()),
            'cookies'     => [$session['cookie'], $this->devices->cookieFor($admin, $request)],
        ];
    }

    /** @param list<LimiterInterface> $counters */
    private function refuse(?Admin $admin, string $normalized, array $counters): never
    {
        foreach ($counters as $counter) {
            $counter->consume();
        }
        // Jamais l'identifiant saisi en clair (ce peut être un mot de passe tapé dans la mauvaise case).
        $this->audit->record('admin.login_refused', null, $admin !== null
            ? ['account' => $admin->getId()]
            : ['account' => 'inconnu', 'kind' => str_contains($normalized, '@') ? 'e-mail' : 'téléphone']);

        throw new ApiException(401, 'INVALID_CREDENTIALS', 'Identifiant ou mot de passe incorrect.');
    }

    /**
     * Connexion réussie : les compteurs du compte repartent de zéro, jamais celui de l'adresse IP.
     *
     * @param list<LimiterInterface> $counters
     */
    private function resetAll(array $counters, LimiterInterface $byIp): void
    {
        foreach ($counters as $counter) {
            if ($counter !== $byIp) {
                $counter->reset();
            }
        }
    }

    /** Verrou attendu 4 s au plus : des requêtes en rafale n'occupent pas tous les processus PHP. */
    private function lock(string $name): LockInterface
    {
        $lock = $this->lockFactory->createLock($name, 15);
        for ($waited = 0; !$lock->acquire(); $waited += 50) {
            if ($waited >= self::LOCK_WAIT_MILLISECONDS) {
                throw ApiException::tooManyRequests('TOO_MANY_ATTEMPTS', 'Trop de tentatives en même temps. Réessayez dans quelques instants.', 5);
            }
            usleep(50_000);
        }

        return $lock;
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
