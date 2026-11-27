<?php

namespace App\Auth\Whatsapp;

use App\Account\PhoneNumber;
use App\Api\ApiException;
use App\Api\ClientIp;
use App\Auth\LoginOpener;
use App\Entity\WhatsappLoginRequest;
use App\Repository\UserRepository;
use App\Repository\WhatsappLoginRequestRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Connexion par WhatsApp, côté app (WHATSAPP_OTP.md) :
 *  1. `start` : l'app enregistre le numéro saisi et la référence écrite dans le message WhatsApp prérempli (l'app la
 *     tire au sort pour ouvrir WhatsApp d'un seul geste ; sans référence, le serveur en choisit une) ;
 *  2. le client envoie le message à la boutique ; WhatsappWebhook lui répond avec un code à 6 chiffres, seulement si
 *     le message vient du WhatsApp du numéro saisi ;
 *  3. le client saisit ce code dans l'app : vérification habituelle (/auth/otp/verify).
 *
 * Limites : 5 demandes en 10 min et 20 par jour par numéro ; 30 demandes en 10 min par adresse IP (beaucoup de clients
 * partagent une adresse sur les réseaux mobiles).
 */
final class WhatsappLogin
{
    public const TTL_SECONDS = 600;
    public const WINDOW_SECONDS = 600;
    public const MAX_REQUESTS_PER_WINDOW = 5;
    public const DAY_SECONDS = 86400;
    public const MAX_REQUESTS_PER_DAY = 20;

    public function __construct(
        private readonly WhatsappConfig $config,
        private readonly WhatsappLoginRequestRepository $requests,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.whatsapp_start_ip')]
        private readonly RateLimiterFactoryInterface $startIpLimiter,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        // Journal var/log/whatsapp-AAAA-MM-JJ.log
        #[Autowire(service: 'monolog.logger.whatsapp')]
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{reference: string, waLink: string, businessNumber: string, expiresIn: int}
     *
     * @throws ApiException 403, 409, 429 ou 503
     */
    public function start(string $phone, ?string $reference, ?string $ip): array
    {
        if (!$this->config->isLoginReady()) {
            $this->logger->error('Demande de code par WhatsApp refusée (503) : WHATSAPP_BUSINESS_NUMBER, WHATSAPP_APP_SECRET, WHATSAPP_TOKEN ou WHATSAPP_PHONE_NUMBER_ID est vide.');

            throw new ApiException(503, 'WHATSAPP_UNAVAILABLE', 'Le code par WhatsApp est momentanément indisponible. Recevez-le par SMS.');
        }

        $user = $this->users->findByPhone($phone);
        if ($user !== null && $user->isBlocked()) {
            throw LoginOpener::blocked();
        }

        $now = $this->now();
        $this->assertBelowLimit($phone, $now, self::WINDOW_SECONDS, self::MAX_REQUESTS_PER_WINDOW);
        $this->assertBelowLimit($phone, $now, self::DAY_SECONDS, self::MAX_REQUESTS_PER_DAY);

        if ($reference !== null && $this->requests->isReferenceTaken($reference)) {
            throw new ApiException(409, 'REFERENCE_IN_USE', 'Cette référence est déjà prise. Recommencez.');
        }

        $limit = $this->startIpLimiter->create(ClientIp::limiterKey($ip, $this->secret))->consume();
        if (!$limit->isAccepted()) {
            throw $this->tooManyRequests(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }

        $reference ??= $this->freeReference();
        $request = new WhatsappLoginRequest($phone, $reference, $now, $now->modify(sprintf('+%d seconds', self::TTL_SECONDS)), $ip);

        // La demande précédente de ce numéro ne donnera plus de code : une seule demande valable à la fois.
        try {
            $this->em->wrapInTransaction(function () use ($phone, $request): void {
                $this->requests->cancelPendingForPhone($phone);
                $this->em->persist($request);
            });
        } catch (UniqueConstraintViolationException) {
            // Même référence enregistrée au même instant par une autre demande
            throw new ApiException(409, 'REFERENCE_IN_USE', 'Cette référence est déjà prise. Recommencez.');
        }

        $this->logger->info('Demande de code par WhatsApp depuis l’app : {phone}, référence {reference} (valable {minutes} min). En attente du message du client.', [
            'phone'     => PhoneNumber::mask($phone),
            'reference' => $reference,
            'minutes'   => intdiv(self::TTL_SECONDS, 60),
        ]);

        return [
            'reference'      => $reference,
            'waLink'         => $this->config->waLink($reference),
            'businessNumber' => (string) $this->config->businessNumber,
            'expiresIn'      => self::TTL_SECONDS,
        ];
    }

    // ── Interne ───────────────────────────────────────────────────────────

    /** Référence tirée au sort, différente de celles déjà enregistrées (tirage refait en cas d'égalité). */
    private function freeReference(): string
    {
        for ($i = 0; ; ++$i) {
            $reference = WhatsappConfig::newReference();
            if ($i >= 4 || !$this->requests->isReferenceTaken($reference)) {
                return $reference;
            }
        }
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
        $this->logger->info('Demande de code par WhatsApp refusée : trop de demandes (nouvel essai dans {seconds} s).', ['seconds' => $retryAfter]);

        return ApiException::tooManyRequests(
            'TOO_MANY_WHATSAPP_REQUESTS',
            sprintf("Trop de demandes de code. Réessayez dans %d\u{00A0}min.", max(1, (int) ceil($retryAfter / 60))),
            $retryAfter,
        );
    }

    private function now(): \DateTimeImmutable
    {
        // UTC, à la seconde près (comme les codes SMS) : les colonnes datetime ne gardent ni fuseau ni millisecondes.
        return new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
    }
}
