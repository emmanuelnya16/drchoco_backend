<?php

namespace App\Auth\Whatsapp;

use App\Account\PhoneNumber;
use App\Api\ApiException;
use App\Api\ClientIp;
use App\Auth\LoginOpener;
use App\Entity\User;
use App\Entity\WhatsappLoginRequest;
use App\Repository\UserRepository;
use App\Repository\WhatsappLoginRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Connexion par WhatsApp, côté app (WHATSAPP_OTP.md §4.1 et §4.2) :
 *  1. `start` : l'app reçoit un code DRC-XXXXXX, un lien WhatsApp prérempli et un jeton secret (`requestId`) ;
 *  2. le client envoie le code depuis le WhatsApp du numéro saisi (WhatsappWebhook confirme la demande) ;
 *  3. `status`, appelé par l'app toutes les 2 à 3 s : dès que la demande est confirmée, la session s'ouvre (même
 *     réponse que /auth/otp/verify) et la demande ne peut plus servir.
 *
 * Aucun message n'est envoyé pour se connecter : ni SMS, ni message WhatsApp payant.
 * Limites : 5 demandes en 10 min et 20 par jour par numéro ; 30 demandes en 10 min par adresse IP (beaucoup de clients
 * partagent une adresse sur les réseaux mobiles) ; 300 lectures d'état par minute et par adresse IP.
 */
final class WhatsappLogin
{
    public const TTL_SECONDS = 600;
    /** Après le message, l'app a encore ce temps pour récupérer la session (retour depuis WhatsApp, app relancée). */
    public const CONFIRMED_GRACE_SECONDS = 600;
    public const WINDOW_SECONDS = 600;
    public const MAX_REQUESTS_PER_WINDOW = 5;
    public const DAY_SECONDS = 86400;
    public const MAX_REQUESTS_PER_DAY = 20;

    public function __construct(
        private readonly WhatsappConfig $config,
        private readonly WhatsappLoginRequestRepository $requests,
        private readonly UserRepository $users,
        private readonly LoginOpener $loginOpener,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.whatsapp_start_ip')]
        private readonly RateLimiterFactoryInterface $startIpLimiter,
        #[Autowire(service: 'limiter.whatsapp_status_ip')]
        private readonly RateLimiterFactoryInterface $statusIpLimiter,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {}

    /**
     * @return array{requestId: string, code: string, waLink: string, businessNumber: string, expiresIn: int}
     *
     * @throws ApiException 403, 429 ou 503
     */
    public function start(string $phone, ?string $ip): array
    {
        if (!$this->config->isLoginReady()) {
            throw new ApiException(503, 'WHATSAPP_UNAVAILABLE', 'La vérification par WhatsApp est momentanément indisponible. Recevez un code par SMS.');
        }

        $user = $this->users->findByPhone($phone);
        if ($user !== null && $user->isBlocked()) {
            throw LoginOpener::blocked();
        }

        $now = $this->now();
        $this->assertBelowLimit($phone, $now, self::WINDOW_SECONDS, self::MAX_REQUESTS_PER_WINDOW);
        $this->assertBelowLimit($phone, $now, self::DAY_SECONDS, self::MAX_REQUESTS_PER_DAY);

        $limit = $this->startIpLimiter->create(ClientIp::limiterKey($ip, $this->secret))->consume();
        if (!$limit->isAccepted()) {
            throw $this->tooManyRequests(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }

        $requestId = bin2hex(random_bytes(32));
        $code = $this->newCode($now);
        $request = new WhatsappLoginRequest($phone, $code, $this->tokenHash($requestId), $now, $now->modify(sprintf('+%d seconds', self::TTL_SECONDS)), $ip);

        // La demande précédente de ce numéro ne peut plus être confirmée : un seul code valable à la fois.
        $this->em->wrapInTransaction(function () use ($phone, $request): void {
            $this->requests->cancelPendingForPhone($phone);
            $this->em->persist($request);
        });

        return [
            'requestId'      => $requestId,
            'code'           => $code,
            'waLink'         => $this->config->waLink($code),
            'businessNumber' => (string) $this->config->businessNumber,
            'expiresIn'      => self::TTL_SECONDS,
        ];
    }

    /**
     * @return array{status: 'pending', expiresIn: int, wrongSender: bool}|array{status: 'confirmed', user: User, isNewUser: bool, accessToken: string, cookie: Cookie}
     *
     * @throws ApiException 403, 410 ou 429
     */
    public function status(string $requestId, Request $httpRequest): array
    {
        $limit = $this->statusIpLimiter->create(ClientIp::limiterKey($httpRequest->getClientIp(), $this->secret))->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw ApiException::tooManyRequests('RATE_LIMITED', 'Trop de vérifications en peu de temps. Réessayez dans quelques instants.', $retryAfter);
        }

        if (preg_match('/^[a-f0-9]{64}\z/', $requestId) !== 1) {
            throw $this->expired();
        }

        $now = $this->now();
        $request = $this->requests->findByTokenHash($this->tokenHash($requestId));
        if ($request !== null && $request->isPending($now)) {
            return ['status' => 'pending', 'expiresIn' => $request->secondsLeft($now), 'wrongSender' => $request->hasWrongSender()];
        }
        if ($request === null || !$request->isConfirmed($now)) {
            throw $this->expired();
        }

        // Message reçu : la session s'ouvre une seule fois, même si l'app interroge deux fois en même temps.
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $request = $this->requests->findByTokenHash($this->tokenHash($requestId), forUpdate: true);
            if ($request === null || !$request->isConfirmed($now)) {
                $connection->commit();

                throw $this->expired();
            }

            $request->consume($now);
            try {
                $opened = $this->loginOpener->open($request->getPhone(), $now, $httpRequest);
            } catch (ApiException $blocked) {
                // Compte suspendu pendant l'attente : la demande est utilisée quand même.
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
            $this->em->clear(); // la demande reste confirmée en base : l'app peut réessayer
            throw $e;
        }

        $this->logger->info('Connexion par WhatsApp : {phone}.', ['phone' => PhoneNumber::mask($request->getPhone())]);

        return ['status' => 'confirmed'] + $opened;
    }

    // ── Interne ───────────────────────────────────────────────────────────

    /** DRC- et 6 chiffres, différent de ceux des demandes encore ouvertes (tirage refait en cas d'égalité). */
    private function newCode(\DateTimeImmutable $now): string
    {
        for ($i = 0; ; ++$i) {
            $code = 'DRC-' . str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
            if ($i >= 4 || !$this->requests->isCodeInUse($code, $now)) {
                return $code;
            }
        }
    }

    private function tokenHash(string $requestId): string
    {
        return hash_hmac('sha256', 'whatsapp-login|' . $requestId, $this->secret);
    }

    private function assertBelowLimit(string $phone, \DateTimeImmutable $now, int $windowSeconds, int $max): void
    {
        $stats = $this->requests->statsForPhoneSince($phone, $now->modify(sprintf('-%d seconds', $windowSeconds)));
        if ($stats['count'] < $max || $stats['oldest'] === null) {
            return;
        }

        throw $this->tooManyRequests(max(1, $stats['oldest']->getTimestamp() + $windowSeconds - $now->getTimestamp()));
    }

    private function tooManyRequests(int $retryAfter): ApiException
    {
        return ApiException::tooManyRequests(
            'TOO_MANY_WHATSAPP_REQUESTS',
            sprintf("Trop de demandes de vérification. Réessayez dans %d\u{00A0}min.", max(1, (int) ceil($retryAfter / 60))),
            $retryAfter,
        );
    }

    private function expired(): ApiException
    {
        return new ApiException(410, 'WHATSAPP_REQUEST_EXPIRED', 'Cette vérification a expiré. Recommencez la connexion.');
    }

    private function now(): \DateTimeImmutable
    {
        // UTC, à la seconde près (comme les codes SMS) : les colonnes datetime ne gardent ni fuseau ni millisecondes.
        return new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
    }
}
