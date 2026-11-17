<?php

namespace App\Service\Auth;

use App\Entity\Admin;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\AdminRepository;
use App\Repository\RefreshTokenRepository;
use App\Repository\UserRepository;
use App\Security\Exception\RefreshDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sessions longues (jeton de rafraîchissement en cookie httpOnly) et émission du JWT.
 *
 * Même principe que le backend de référence : jeton opaque haché en base, à usage unique,
 * renouvelé à chaque rafraîchissement, JWT émis par lexik ($jwtManager->create()).
 *
 * Différence demandée pour DR Choco : client et admin ont des sessions SÉPARÉES.
 *  - Client : cookie « drc_refresh », Path=/api, SameSite=Lax, 90 jours glissants
 *    (le client reste connecté longtemps, sans se reconnecter à chaque visite).
 *  - Admin : cookie « drc_admin_refresh », Path=/api/admin/auth, SameSite=Strict,
 *    12 h maximum après la connexion (zone sensible : reconnexion obligatoire ensuite).
 *
 * Ajouts pour la robustesse : famille de jetons (une connexion = une famille), délai de grâce de 30 s
 * (deux onglets qui rafraîchissent ensemble), révocation de la famille si un vieux jeton est réutilisé,
 * verrou en base pendant la rotation.
 */
class RefreshTokenService
{
    public const CLIENT_COOKIE      = 'drc_refresh';
    public const CLIENT_COOKIE_PATH = '/api';
    public const ADMIN_COOKIE       = 'drc_admin_refresh';
    public const ADMIN_COOKIE_PATH  = '/api/admin/auth';

    // Même valeur que token_ttl de config/packages/lexik_jwt_authentication.yaml (reprise à l'identique).
    public const ACCESS_TOKEN_TTL = 3600;

    public function __construct(
        private readonly RefreshTokenRepository   $refreshTokenRepository,
        private readonly UserRepository           $userRepository,
        private readonly AdminRepository          $adminRepository,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly EntityManagerInterface   $em,
        private readonly ClockInterface           $clock,
        private readonly string                   $appEnv,
        private readonly int                      $clientTtlDays,
        private readonly int                      $adminSessionHours,
    ) {}

    // ── Création (après une connexion réussie) ────────────────────────────

    /** @return array{refreshToken: RefreshToken, cookie: Cookie} */
    public function createForUser(User $user, Request $request): array
    {
        return $this->createSession(RefreshToken::TYPE_USER, (int) $user->getId(), $request);
    }

    /** @return array{refreshToken: RefreshToken, cookie: Cookie} */
    public function createForAdmin(Admin $admin, Request $request): array
    {
        return $this->createSession(RefreshToken::TYPE_ADMIN, (int) $admin->getId(), $request);
    }

    // ── Rotation (POST /auth/refresh et POST /admin/auth/refresh) ────────

    /**
     * @return array{accessToken: string, cookie: Cookie, user: User}
     * @throws RefreshDeniedException
     */
    public function rotateUser(Request $request): array
    {
        $result = $this->rotate(RefreshToken::TYPE_USER, $request);

        return ['accessToken' => $result['accessToken'], 'cookie' => $result['cookie'], 'user' => $result['account']];
    }

    /**
     * @return array{accessToken: string, cookie: Cookie, admin: Admin}
     * @throws RefreshDeniedException
     */
    public function rotateAdmin(Request $request): array
    {
        $result = $this->rotate(RefreshToken::TYPE_ADMIN, $request);

        return ['accessToken' => $result['accessToken'], 'cookie' => $result['cookie'], 'admin' => $result['account']];
    }

    // ── Déconnexion ───────────────────────────────────────────────────────

    /**
     * Déconnecte CET appareil (la famille du cookie présenté), même si le JWT a expiré.
     * Renvoie toujours le cookie d'effacement.
     */
    public function revokeCurrentSession(string $type, Request $request): Cookie
    {
        $plain = $request->cookies->get($this->cookieName($type));
        if (\is_string($plain) && $plain !== '') {
            $token = $this->refreshTokenRepository->findByHash(RefreshToken::hash($plain));
            if ($token !== null && $token->getUserType() === $type) {
                $this->refreshTokenRepository->revokeFamily($token->getFamilyId());
            }
        }

        return $this->clearCookie($type, $request);
    }

    /** Coupe toutes les sessions d'un compte (vendeur désactivé, client bloqué…). */
    public function revokeAll(int $userId, string $type): void
    {
        $this->refreshTokenRepository->revokeAllForUser($userId, $type);
    }

    public function clearCookie(string $type, ?Request $request = null): Cookie
    {
        return Cookie::create($this->cookieName($type))
            ->withValue('')
            ->withExpires(new \DateTimeImmutable('@0'))
            ->withPath($this->cookiePath($type))
            ->withDomain(null)
            ->withSecure($this->isSecure($request))
            ->withHttpOnly(true)
            ->withSameSite($this->sameSite($type));
    }

    // ── Interne ───────────────────────────────────────────────────────────

    /** @return array{refreshToken: RefreshToken, cookie: Cookie} */
    private function createSession(string $type, int $userId, Request $request): array
    {
        $now = $this->now();
        $sessionEnd = $type === RefreshToken::TYPE_ADMIN
            ? $now->modify(sprintf('+%d hours', $this->adminSessionHours))
            : null;

        ['token' => $token, 'plain' => $plain] = $this->newToken(
            $type, $userId, RefreshToken::newFamilyId(), $sessionEnd, $request, $now,
        );
        $this->em->flush();

        return ['refreshToken' => $token, 'cookie' => $this->buildCookie($type, $plain, $token->getExpiresAt(), $request)];
    }

    /** @return array{accessToken: string, cookie: Cookie, account: User|Admin} */
    private function rotate(string $type, Request $request): array
    {
        $plain = $request->cookies->get($this->cookieName($type));
        if (!\is_string($plain) || $plain === '') {
            throw new RefreshDeniedException('Cookie de session absent.');
        }

        $now = $this->now();
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            $current = $this->refreshTokenRepository->findByHashForUpdate(RefreshToken::hash($plain));
            $denied = $this->checkPresentedToken($current, $type, $now);

            $account = null;
            if ($denied === null) {
                $account = $this->loadActiveAccount($type, $current->getUserId());
                if ($account === null) {
                    $denied = 'Compte introuvable, bloqué ou désactivé.';
                }
            }

            if ($denied !== null) {
                // Jeton réutilisé hors délai de grâce, expiré ou compte coupé : toute la famille est révoquée
                // (un éventuel voleur du cookie perd aussi l'accès).
                if ($current !== null && $current->getUserType() === $type && !$current->isRevoked()) {
                    $this->refreshTokenRepository->revokeFamily($current->getFamilyId());
                }
                $conn->commit();

                throw new RefreshDeniedException($denied);
            }

            $current->markAsUsed($now);
            ['token' => $next, 'plain' => $nextPlain] = $this->newToken(
                $type, $current->getUserId(), $current->getFamilyId(), $current->getSessionExpiresAt(), $request, $now,
            );

            // Le JWT est créé AVANT la validation en base : si sa création échoue (clé illisible…),
            // tout est annulé et l'ancien cookie reste utilisable — une panne ne déconnecte jamais.
            $accessToken = $this->createAccessToken($account, $current->getSessionExpiresAt(), $now);

            $this->em->flush();
            $conn->commit();
        } catch (RefreshDeniedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->em->clear(); // l'ancien jeton ne doit pas rester marqué « utilisé » en mémoire
            throw $e; // panne (base…) : 500, jamais 401 — le front garde alors la session.
        }

        return [
            'accessToken' => $accessToken,
            'cookie'      => $this->buildCookie($type, $nextPlain, $next->getExpiresAt(), $request),
            'account'     => $account,
        ];
    }

    /**
     * Émet le JWT (lexik, configuration inchangée). Pour l'admin, sa durée est plafonnée à la fin
     * absolue de la session : un admin ne peut jamais agir plus de 12 h après sa connexion.
     */
    public function createAccessToken(User|Admin $account, ?\DateTimeImmutable $sessionEnd = null, ?\DateTimeImmutable $now = null): string
    {
        if ($account instanceof Admin && $sessionEnd !== null) {
            $now ??= $this->now();
            $exp = min($now->getTimestamp() + self::ACCESS_TOKEN_TTL, $sessionEnd->getTimestamp());

            return $this->jwtManager->createFromPayload($account, ['exp' => $exp]);
        }

        return $this->jwtManager->create($account);
    }

    /**
     * Compte client de la session du cookie, sans le faire tourner (lecture seule) : réabonnement aux notifications
     * envoyé par le service worker, qui n'a pas de jeton d'accès (P7). Jeton inconnu, révoqué, expiré, déjà
     * utilisé hors délai de grâce, ou compte bloqué : null.
     */
    public function userFromSessionCookie(Request $request): ?User
    {
        $plain = $request->cookies->get(self::CLIENT_COOKIE);
        if (!\is_string($plain) || $plain === '') {
            return null;
        }
        $token = $this->refreshTokenRepository->findByHash(RefreshToken::hash($plain));
        if ($this->checkPresentedToken($token, RefreshToken::TYPE_USER, $this->now()) !== null) {
            return null;
        }
        $account = $this->loadActiveAccount(RefreshToken::TYPE_USER, $token->getUserId());

        return $account instanceof User ? $account : null;
    }

    private function checkPresentedToken(?RefreshToken $token, string $type, \DateTimeImmutable $now): ?string
    {
        if ($token === null || $token->getUserType() !== $type) {
            return 'Jeton de session inconnu.';
        }
        if ($token->isRevoked()) {
            return 'Session révoquée.';
        }
        if ($token->isExpired($now)) {
            return 'Session expirée.';
        }
        if ($token->isUsed() && !$token->isWithinGrace($now)) {
            return 'Jeton déjà utilisé (réutilisation détectée).';
        }

        return null;
    }

    private function loadActiveAccount(string $type, int $userId): User|Admin|null
    {
        if ($type === RefreshToken::TYPE_USER) {
            $user = $this->userRepository->find($userId);

            return ($user !== null && !$user->isBlocked()) ? $user : null;
        }

        $admin = $this->adminRepository->find($userId);

        return ($admin !== null && $admin->isActive()) ? $admin : null;
    }

    /** @return array{token: RefreshToken, plain: string} */
    private function newToken(
        string $type,
        int $userId,
        string $familyId,
        ?\DateTimeImmutable $sessionEnd,
        Request $request,
        \DateTimeImmutable $now,
    ): array {
        ['plain' => $plain, 'hash' => $hash] = RefreshToken::generate();

        $expiresAt = $type === RefreshToken::TYPE_ADMIN
            ? $sessionEnd                                               // admin : fin absolue de session
            : $now->modify(sprintf('+%d days', $this->clientTtlDays));  // client : 90 jours glissants

        $token = new RefreshToken($now, $expiresAt);
        $token->setUserType($type)
            ->setUserId($userId)
            ->setFamilyId($familyId)
            ->setSessionExpiresAt($sessionEnd)
            ->setTokenHash($hash)
            ->setCreatedFromIp($request->getClientIp())
            ->setUserAgent(mb_substr((string) $request->headers->get('User-Agent', ''), 0, 255));

        $this->refreshTokenRepository->save($token);

        return ['token' => $token, 'plain' => $plain];
    }

    private function buildCookie(string $type, string $plain, \DateTimeImmutable $expiresAt, Request $request): Cookie
    {
        return Cookie::create($this->cookieName($type))
            ->withValue($plain)
            ->withExpires($expiresAt)
            ->withPath($this->cookiePath($type))
            ->withDomain(null)                       // limité à l'hôte de l'API
            ->withSecure($this->isSecure($request))  // HTTPS obligatoire en production
            ->withHttpOnly(true)                     // invisible au JavaScript
            ->withSameSite($this->sameSite($type));
    }

    private function cookieName(string $type): string
    {
        return $type === RefreshToken::TYPE_ADMIN ? self::ADMIN_COOKIE : self::CLIENT_COOKIE;
    }

    private function cookiePath(string $type): string
    {
        return $type === RefreshToken::TYPE_ADMIN ? self::ADMIN_COOKIE_PATH : self::CLIENT_COOKIE_PATH;
    }

    private function sameSite(string $type): string
    {
        return $type === RefreshToken::TYPE_ADMIN ? Cookie::SAMESITE_STRICT : Cookie::SAMESITE_LAX;
    }

    private function isSecure(?Request $request): bool
    {
        return $this->appEnv === 'prod' || ($request !== null && $request->isSecure());
    }

    private function now(): \DateTimeImmutable
    {
        // Toujours en UTC : les colonnes datetime ne gardent pas le fuseau.
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
