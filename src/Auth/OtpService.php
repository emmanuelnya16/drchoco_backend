<?php

namespace App\Auth;

use App\Account\PhoneNumber;
use App\Api\ApiException;
use App\Api\ClientIp;
use App\Entity\OtpCode;
use App\Entity\User;
use App\Repository\OtpCodeRepository;
use App\Repository\UserRepository;
use App\Service\Auth\RefreshTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Connexion sans mot de passe : code à 6 chiffres envoyé par SMS (contrat §4.1, §4.2, §17 question 17).
 *
 * Limites (valeurs du contrat, celles des mocks du front) :
 *  - code valable 5 min ; « Renvoyer le code » possible après 60 s ; un nouveau code annule le précédent ;
 *  - 3 demandes au plus en 10 min pour un numéro (TOO_MANY_OTP_REQUESTS) ;
 *  - 5 codes faux : numéro verrouillé 10 min (OTP_LOCKED à la vérification, TOO_MANY_ATTEMPTS à la demande).
 * Protections ajoutées contre l'envoi massif de SMS payants et les tentatives à la chaîne :
 *  - 10 codes au plus par numéro sur 24 h ;
 *  - 30 demandes par heure et par adresse IP, et 30 codes faux en 10 min par adresse IP (préfixe /64 en IPv6) ;
 *  - plafond quotidien de SMS pour toute la boutique (OTP_DAILY_SMS_LIMIT) : au-delà, plus aucun envoi.
 * Une nouvelle demande dans les 60 s qui suivent un envoi ne renvoie pas de SMS : le code déjà envoyé reste
 * valable et l'app reçoit le temps d'attente restant.
 */
final class OtpService
{
    public const CODE_TTL_SECONDS = 300;
    public const RESEND_AFTER_SECONDS = 60;
    public const WINDOW_SECONDS = 600;
    public const MAX_REQUESTS_PER_WINDOW = 3;
    public const DAY_SECONDS = 86400;
    public const MAX_REQUESTS_PER_DAY = 10;
    public const LOCK_SECONDS = 600;

    public function __construct(
        private readonly OtpCodeRepository $otpCodes,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly OtpSenderInterface $sender,
        private readonly RefreshTokenService $refreshTokens,
        private readonly ClockInterface $clock,
        private readonly LockFactory $lockFactory,
        #[Autowire(service: 'limiter.otp_request_ip')]
        private readonly RateLimiterFactoryInterface $requestIpLimiter,
        #[Autowire(service: 'limiter.otp_verify_ip')]
        private readonly RateLimiterFactoryInterface $verifyIpLimiter,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        #[Autowire('%env(int:OTP_DAILY_SMS_LIMIT)%')]
        private readonly int $dailySmsLimit,
    ) {}

    /**
     * Envoie un code à ce numéro (déjà normalisé au format +2376XXXXXXXX).
     *
     * @return array{expiresIn: int, resendIn: int}
     *
     * @throws ApiException 403, 429 ou 503
     */
    public function request(string $phone, ?string $ip): array
    {
        $limit = $this->requestIpLimiter->create(ClientIp::limiterKey($ip, $this->secret))->consume();
        if (!$limit->isAccepted()) {
            throw $this->tooManyRequests(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }

        $user = $this->users->findByPhone($phone);
        if ($user !== null && $user->isBlocked()) {
            throw $this->blocked();
        }

        // Une demande à la fois par numéro (double appui, deux onglets) : la seconde voit l'envoi de la première.
        // Le nom du verrou (un fichier) contient une empreinte secrète (HMAC) du numéro, jamais le numéro lui-même.
        $lock = $this->lockFactory->createLock('otp-request-' . substr(hash_hmac('sha256', $phone, $this->secret), 0, 32), 30);
        $lock->acquire(true);

        try {
            return $this->sendNewCode($phone, $ip);
        } finally {
            $lock->release();
        }
    }

    /**
     * Vérifie le code, crée le compte à la première connexion et ouvre la session (jeton d'accès + cookie).
     *
     * Tout se fait dans une seule transaction : si l'ouverture de la session échoue (panne de la base…),
     * le code n'est pas consommé et le client peut le saisir à nouveau.
     *
     * @return array{user: User, isNewUser: bool, accessToken: string, cookie: Cookie}
     *
     * @throws ApiException 403, 410, 422 ou 429
     */
    public function verify(string $phone, string $code, Request $request): array
    {
        // Codes faux, expirés ou verrouillés : 30 au plus en 10 min par adresse IP (une connexion réussie ne compte
        // pas). Une seule machine ne peut pas sonder ni verrouiller les numéros des clients à la chaîne.
        // Une vérification à la fois par adresse IP : contrôle et décompte se font sous le même verrou, la limite
        // ne peut donc pas être dépassée en envoyant des requêtes en parallèle (chaque vérification est brève).
        $ipKey = ClientIp::limiterKey($request->getClientIp(), $this->secret);
        $ipLock = $this->lockFactory->createLock('otp-verify-' . $ipKey, 15);
        $ipLock->acquire(true);

        try {
            return $this->verifyCode($phone, $code, $request, $this->verifyIpLimiter->create($ipKey));
        } finally {
            $ipLock->release();
        }
    }

    /** @return array{user: User, isNewUser: bool, accessToken: string, cookie: Cookie} */
    private function verifyCode(string $phone, string $code, Request $request, LimiterInterface $verifyLimiter): array
    {
        $check = $verifyLimiter->consume(0); // lecture seule : aucun jeton pris
        if ($check->getRemainingTokens() <= 0) {
            $retryAfter = max(1, $check->getRetryAfter()->getTimestamp() - time());

            throw ApiException::tooManyRequests('TOO_MANY_ATTEMPTS', $this->retryMessage($retryAfter), $retryAfter);
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $now = $this->now();
            $otp = $this->otpCodes->findLatestForPhone($phone, forUpdate: true);

            $failure = match (true) {
                $otp === null             => $this->expired(),
                $otp->isLocked($now)      => $this->locked($this->secondsUntil($otp->getLockedUntil(), $now)),
                !$otp->isUsable($now)     => $this->expired(),
                default                   => null,
            };

            if ($failure === null && !hash_equals($otp->getCodeHash(), $this->hash($phone, $code))) {
                $attemptsLeft = $otp->registerFailure($now, self::LOCK_SECONDS);
                $failure = $attemptsLeft === 0 ? $this->locked(self::LOCK_SECONDS) : $this->invalidCode($attemptsLeft);
                if ($attemptsLeft === 0) {
                    $this->logger->notice('Numéro {phone} verrouillé 10 min après 5 codes faux.', ['phone' => PhoneNumber::mask($phone)]);
                }
            }

            if ($failure !== null) {
                // L'essai raté est enregistré AVANT de répondre : il compte même si le client recommence aussitôt.
                $this->em->flush();
                $connection->commit();
                $verifyLimiter->consume();

                throw $failure;
            }

            $otp->consume($now);

            $user = $this->users->findByPhone($phone);
            $isNewUser = false;
            if ($user === null) {
                $user = (new User($now))->setPhone($phone);
                $this->em->persist($user);
                $isNewUser = true;
            } elseif ($user->isBlocked()) {
                $this->em->flush();
                $connection->commit();

                throw $this->blocked();
            }

            $user->setLastLoginAt($now);
            $this->em->flush(); // un nouveau compte reçoit ici son identifiant, nécessaire au JWT et à la session

            // Jeton d'accès (JWT lexik, configuration inchangée) et session longue (cookie httpOnly, 90 jours).
            $accessToken = $this->refreshTokens->createAccessToken($user);
            $session = $this->refreshTokens->createForUser($user, $request);

            $connection->commit();
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->em->clear(); // rien de la tentative ne reste en mémoire (code consommé, compte, session)
            throw $e;
        }

        return ['user' => $user, 'isNewUser' => $isNewUser, 'accessToken' => $accessToken, 'cookie' => $session['cookie']];
    }

    // ── Interne ───────────────────────────────────────────────────────────

    /** @return array{expiresIn: int, resendIn: int} */
    private function sendNewCode(string $phone, ?string $ip): array
    {
        $now = $this->now();
        $latest = $this->otpCodes->findLatestForPhone($phone);

        if ($latest !== null && $latest->isLocked($now)) {
            throw ApiException::tooManyRequests(
                'TOO_MANY_ATTEMPTS',
                $this->retryMessage($this->secondsUntil($latest->getLockedUntil(), $now)),
                $this->secondsUntil($latest->getLockedUntil(), $now),
            );
        }

        // Code envoyé il y a moins de 60 s et toujours valable : pas de nouveau SMS.
        if ($latest !== null && $latest->isUsable($now) && $latest->ageInSeconds($now) < self::RESEND_AFTER_SECONDS) {
            return [
                'expiresIn' => $this->secondsUntil($latest->getExpiresAt(), $now),
                'resendIn'  => max(1, self::RESEND_AFTER_SECONDS - $latest->ageInSeconds($now)),
            ];
        }

        $this->assertBelowLimit($phone, $now, self::WINDOW_SECONDS, self::MAX_REQUESTS_PER_WINDOW);
        $this->assertBelowLimit($phone, $now, self::DAY_SECONDS, self::MAX_REQUESTS_PER_DAY);

        if ($this->otpCodes->countAllSince($now->modify('-1 day')) >= $this->dailySmsLimit) {
            $this->logger->critical('Plafond quotidien de SMS atteint ({limit}) : plus aucun code envoyé (OTP_DAILY_SMS_LIMIT).', [
                'limit' => $this->dailySmsLimit,
            ]);
            throw $this->smsUnavailable();
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
        $otp = new OtpCode($phone, $this->hash($phone, $code), $now, $now->modify(sprintf('+%d seconds', self::CODE_TTL_SECONDS)), $ip);
        $this->em->persist($otp);
        $this->em->flush();

        try {
            $sent = $this->sender->send($phone, $code);
        } catch (\Throwable $e) {
            // Ne devrait jamais arriver (l'envoi ne lève pas d'exception) : traité comme un échec d'envoi.
            $this->logger->error('Envoi du code de connexion impossible ({phone}) : {error}', [
                'phone' => PhoneNumber::mask($phone),
                'error' => $e::class,
                'exception' => $e,
            ]);
            $sent = false;
        }

        if (!$sent) {
            // SMS non parti : le code n'existe pas (il ne compte pas dans les limites) et le précédent,
            // s'il y en a un, reste celui à saisir.
            $this->em->remove($otp);
            $this->em->flush();

            throw $this->smsUnavailable();
        }

        $this->logger->info('Code de connexion envoyé à {phone}.', ['phone' => PhoneNumber::mask($phone)]);

        return ['expiresIn' => self::CODE_TTL_SECONDS, 'resendIn' => self::RESEND_AFTER_SECONDS];
    }

    private function assertBelowLimit(string $phone, \DateTimeImmutable $now, int $windowSeconds, int $max): void
    {
        $stats = $this->otpCodes->statsForPhoneSince($phone, $now->modify(sprintf('-%d seconds', $windowSeconds)));
        if ($stats['count'] < $max || $stats['oldest'] === null) {
            return;
        }

        $retryAfter = max(1, $stats['oldest']->getTimestamp() + $windowSeconds - $now->getTimestamp());

        throw $this->tooManyRequests($retryAfter);
    }

    /** Empreinte du code, liée au numéro et au secret de l'application. */
    private function hash(string $phone, string $code): string
    {
        return hash_hmac('sha256', $phone . '|' . $code, $this->secret);
    }

    private function secondsUntil(?\DateTimeImmutable $when, \DateTimeImmutable $now): int
    {
        return $when === null ? 1 : max(1, $when->getTimestamp() - $now->getTimestamp());
    }

    private function retryMessage(int $seconds): string
    {
        return sprintf("Trop de tentatives. Réessayez dans %d\u{00A0}min.", max(1, (int) ceil($seconds / 60)));
    }

    private function tooManyRequests(int $retryAfter): ApiException
    {
        return ApiException::tooManyRequests(
            'TOO_MANY_OTP_REQUESTS',
            sprintf("Trop de demandes de code. Réessayez dans %d\u{00A0}min.", max(1, (int) ceil($retryAfter / 60))),
            $retryAfter,
        );
    }

    private function locked(int $retryAfter): ApiException
    {
        return new ApiException(
            429,
            'OTP_LOCKED',
            $this->retryMessage($retryAfter),
            ['retryAfter' => $retryAfter, 'attemptsLeft' => 0],
            ['Retry-After' => (string) $retryAfter],
        );
    }

    private function invalidCode(int $attemptsLeft): ApiException
    {
        return new ApiException(
            422,
            'INVALID_OTP_CODE',
            sprintf('Code incorrect. Il vous reste %d essai%s.', $attemptsLeft, $attemptsLeft > 1 ? 's' : ''),
            ['attemptsLeft' => $attemptsLeft],
        );
    }

    private function expired(): ApiException
    {
        return new ApiException(410, 'OTP_EXPIRED', 'Ce code a expiré. Demandez-en un nouveau.');
    }

    private function blocked(): ApiException
    {
        return new ApiException(403, 'ACCOUNT_BLOCKED', 'Ce compte est suspendu. Contactez la boutique sur WhatsApp.');
    }

    private function smsUnavailable(): ApiException
    {
        return new ApiException(503, 'SMS_UNAVAILABLE', 'Impossible d’envoyer le SMS pour le moment. Réessayez dans quelques instants.');
    }

    private function now(): \DateTimeImmutable
    {
        // UTC, à la seconde près : les colonnes datetime ne gardent ni le fuseau ni les millisecondes
        // (la réponse de connexion montre ainsi la même date de création que les réponses suivantes).
        return new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
    }
}
