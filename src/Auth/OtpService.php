<?php

namespace App\Auth;

use App\Account\PhoneNumber;
use App\Api\ApiException;
use App\Api\ClientIp;
use App\Audit\AuditLog;
use App\Entity\OtpCode;
use App\Entity\User;
use App\Repository\OtpCodeRepository;
use App\Repository\UserRepository;
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
 * Connexion sans mot de passe : code à 6 chiffres envoyé par SMS (contrat §4.1, §4.2, §17 question 17), ou par WhatsApp
 * en réponse au message du client (issueForWhatsapp, WHATSAPP_OTP.md). Les deux se vérifient de la même façon.
 *
 * Limites (valeurs du contrat, celles des mocks du front) :
 *  - code valable 5 min ; « Renvoyer le code » possible après 60 s ; un nouveau code annule le précédent ;
 *  - 3 demandes au plus en 10 min pour un numéro (TOO_MANY_OTP_REQUESTS) ;
 *  - 5 codes faux : numéro verrouillé 10 min (OTP_LOCKED à la vérification, TOO_MANY_ATTEMPTS à la demande).
 * Protections ajoutées contre l'envoi massif de SMS payants et les tentatives à la chaîne :
 *  - 10 codes au plus par numéro sur 24 h ;
 *  - 30 SMS par heure et par adresse IP, et 30 codes faux en 10 min par adresse IP (préfixe /64 en IPv6). Seuls les
 *    SMS réellement demandés et les codes faux sur un code en cours comptent : au Cameroun, beaucoup de clients
 *    partagent une même adresse (réseau mobile), une demande sans effet ne doit pas les bloquer ;
 *  - plafond quotidien de SMS pour toute la boutique (OTP_DAILY_SMS_LIMIT) : au-delà, plus aucun envoi. Un tiers est
 *    réservé aux numéros qui ont déjà un compte : des numéros pris au hasard ne peuvent pas priver les clients de
 *    connexion.
 * Une nouvelle demande dans les 60 s qui suivent un SMS ne renvoie pas de SMS : le code déjà envoyé reste
 * valable et l'app reçoit le temps d'attente restant. Après un code WhatsApp, un SMS part tout de suite.
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

    /** Part du plafond quotidien de SMS réservée aux numéros qui ont déjà un compte. */
    public const KNOWN_PHONES_SHARE = 1 / 3;

    /** Attente d'une autre demande du même numéro avant de répondre comme elle (l'envoi dure 10 s au plus). */
    private const LOCK_WAIT_MILLISECONDS = 3000;

    public function __construct(
        private readonly OtpCodeRepository $otpCodes,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly OtpSenderInterface $sender,
        private readonly LoginOpener $loginOpener,
        private readonly ClockInterface $clock,
        private readonly LockFactory $lockFactory,
        #[Autowire(service: 'limiter.otp_request_ip')]
        private readonly RateLimiterFactoryInterface $requestIpLimiter,
        #[Autowire(service: 'limiter.otp_verify_ip')]
        private readonly RateLimiterFactoryInterface $verifyIpLimiter,
        private readonly LoggerInterface $logger,
        private readonly AuditLog $audit,
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
        $user = $this->users->findByPhone($phone);
        if ($user !== null && $user->isBlocked()) {
            throw $this->blocked();
        }

        // Demande sans effet (code déjà envoyé, limite atteinte) : réponse immédiate, sans verrou. Chaque verrou est un
        // fichier : une machine qui essaie des milliers de numéros ne doit pas en créer un par numéro.
        $knownPhone = $user !== null;
        $answer = $this->answerWithoutSms($phone, $ip, $knownPhone);
        if ($answer !== null) {
            return $answer;
        }

        // Une demande à la fois par numéro (double appui, deux onglets) : la seconde voit l'envoi de la première.
        // Le nom du verrou (un fichier) contient une empreinte secrète (HMAC) du numéro, jamais le numéro lui-même.
        $lock = $this->lockFactory->createLock('otp-request-' . substr(hash_hmac('sha256', $phone, $this->secret), 0, 32), 30);
        for ($waited = 0; !$lock->acquire(); $waited += 100) {
            if ($waited >= self::LOCK_WAIT_MILLISECONDS) {
                // Envoi toujours en cours (fournisseur lent) : même réponse que lui, sans occuper un processus PHP.
                return ['expiresIn' => self::CODE_TTL_SECONDS, 'resendIn' => self::RESEND_AFTER_SECONDS];
            }
            usleep(100_000);
        }

        try {
            return $this->answerWithoutSms($phone, $ip, $knownPhone) ?? $this->sendNewCode($phone, $ip);
        } finally {
            $lock->release();
        }
    }

    /**
     * Code demandé par WhatsApp (WhatsappWebhook) : créé tout de suite et renvoyé en clair pour la réponse WhatsApp au
     * client. Il remplace le code précédent (SMS ou WhatsApp) et se vérifie comme lui (/auth/otp/verify).
     * Mêmes limites par numéro que les SMS (3 codes en 10 min, 10 par jour, verrouillage après 5 codes faux), mais il
     * ne compte pas dans le plafond quotidien de SMS. Pas d'attente de 60 s : chaque demande WhatsApp a sa propre
     * référence et n'obtient qu'un code.
     *
     * @return array{code: string, expiresIn: int}
     *
     * @throws ApiException 403 (compte suspendu) ou 429
     */
    public function issueForWhatsapp(string $phone): array
    {
        $user = $this->users->findByPhone($phone);
        if ($user !== null && $user->isBlocked()) {
            throw $this->blocked();
        }

        $now = $this->now();
        $latest = $this->otpCodes->findLatestForPhone($phone);
        if ($latest !== null && $latest->isLocked($now)) {
            $retryAfter = $this->secondsUntil($latest->getLockedUntil(), $now);

            throw ApiException::tooManyRequests('TOO_MANY_ATTEMPTS', $this->retryMessage($retryAfter), $retryAfter);
        }
        $this->assertBelowLimit($phone, $now, self::WINDOW_SECONDS, self::MAX_REQUESTS_PER_WINDOW);
        $this->assertBelowLimit($phone, $now, self::DAY_SECONDS, self::MAX_REQUESTS_PER_DAY);

        $code = $this->newCode();
        $otp = new OtpCode($phone, $this->hash($phone, $code), $now, $now->modify(sprintf('+%d seconds', self::CODE_TTL_SECONDS)), null, OtpCode::CHANNEL_WHATSAPP);
        // Réponse WhatsApp envoyée juste après (WhatsappReplies) : le code peut être saisi dès maintenant.
        $otp->markSent($now);
        $this->em->persist($otp);
        $this->em->flush();

        $this->logger->info('Code de connexion envoyé par WhatsApp à {phone}.', ['phone' => PhoneNumber::mask($phone)]);

        return ['code' => $code, 'expiresIn' => self::CODE_TTL_SECONDS];
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
            $wrongGuess = false;

            $failure = match (true) {
                $otp === null             => $this->expired(),
                $otp->isLocked($now)      => $this->locked($this->secondsUntil($otp->getLockedUntil(), $now)),
                !$otp->isUsable($now)     => $this->expired(),
                default                   => null,
            };

            if ($failure === null && !hash_equals($otp->getCodeHash(), $this->hash($phone, $code))) {
                $attemptsLeft = $otp->registerFailure($now, self::LOCK_SECONDS);
                $wrongGuess = true;
                $failure = $attemptsLeft === 0 ? $this->locked(self::LOCK_SECONDS) : $this->invalidCode($attemptsLeft);
                if ($attemptsLeft === 0) {
                    $this->audit->record('otp.locked', null, ['phone' => PhoneNumber::mask($phone)], withIp: false);
                }
            }

            if ($failure !== null) {
                // L'essai raté est enregistré AVANT de répondre : il compte même si le client recommence aussitôt.
                $this->em->flush();
                $connection->commit();
                if ($wrongGuess) {
                    $verifyLimiter->consume();
                }

                throw $failure;
            }

            $otp->consume($now);

            // Compte créé au besoin, jeton d'accès (JWT lexik, configuration inchangée) et session longue (cookie
            // httpOnly, 90 jours) : même fin que la connexion par WhatsApp.
            try {
                $opened = $this->loginOpener->open($phone, $now, $request);
            } catch (ApiException $blocked) {
                // Compte suspendu : le code est consommé quand même.
                $this->em->flush();
                $connection->commit();

                throw $blocked;
            }

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

        return $opened;
    }

    // ── Interne ───────────────────────────────────────────────────────────

    /**
     * Réponse à une demande qui n'enverra pas de SMS (contrôles en lecture seule), ou null si un SMS doit partir.
     *
     * @return array{expiresIn: int, resendIn: int}|null
     *
     * @throws ApiException 429 ou 503
     */
    private function answerWithoutSms(string $phone, ?string $ip, bool $knownPhone): ?array
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

        // SMS envoyé il y a moins de 60 s et toujours valable : pas de nouveau SMS. Un code reçu par WhatsApp ne retient
        // pas le SMS (le client ne l'a peut-être pas reçu, ou n'a pas WhatsApp sur ce numéro).
        if (
            $latest !== null
            && $latest->getChannel() === OtpCode::CHANNEL_SMS
            && $latest->isUsable($now)
            && $latest->ageInSeconds($now) < self::RESEND_AFTER_SECONDS
        ) {
            return [
                'expiresIn' => $this->secondsUntil($latest->getExpiresAt(), $now),
                'resendIn'  => max(1, self::RESEND_AFTER_SECONDS - $latest->ageInSeconds($now)),
            ];
        }

        $this->assertBelowLimit($phone, $now, self::WINDOW_SECONDS, self::MAX_REQUESTS_PER_WINDOW);
        $this->assertBelowLimit($phone, $now, self::DAY_SECONDS, self::MAX_REQUESTS_PER_DAY);

        $limit = $this->requestIpLimiter->create(ClientIp::limiterKey($ip, $this->secret))->consume(0); // lecture seule
        if ($limit->getRemainingTokens() <= 0) {
            throw $this->tooManyRequests(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }

        $ceiling = $knownPhone ? $this->dailySmsLimit : (int) floor($this->dailySmsLimit * (1 - self::KNOWN_PHONES_SHARE));
        if ($this->otpCodes->countSmsSince($now->modify('-1 day')) >= $ceiling) {
            $this->logger->critical('Plafond quotidien de SMS atteint ({limit}{scope}) : plus aucun code envoyé (OTP_DAILY_SMS_LIMIT).', [
                'limit' => $ceiling,
                'scope' => $knownPhone ? '' : ', nouveaux numéros',
            ]);
            throw $this->smsUnavailable();
        }

        return null;
    }

    /** @return array{expiresIn: int, resendIn: int} */
    private function sendNewCode(string $phone, ?string $ip): array
    {
        $limit = $this->requestIpLimiter->create(ClientIp::limiterKey($ip, $this->secret))->consume();
        if (!$limit->isAccepted()) {
            throw $this->tooManyRequests(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }

        $now = $this->now();
        $code = $this->newCode();
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

        // SMS accepté : le code peut maintenant être saisi.
        $otp->markSent($this->now());
        $this->em->flush();

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

    private function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
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
        return LoginOpener::blocked();
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
