<?php

namespace App\Controller\Catalog;

use App\Api\ApiException;
use App\Api\ClientIp;
use App\Api\JsonBody;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/client-errors — erreurs remontées par l'application (contrat §13.1).
 *
 * Écrites dans un journal dédié (var/log/client-errors-*.log, gardé 30 jours), sans aucune donnée
 * personnelle : ni compte, ni adresse IP. Limite : 20 envois par heure et par adresse IP.
 * Jamais de 401, même avec un jeton invalide (route publique). JSON exigé (un autre site ne peut pas faire envoyer des
 * rapports par ses visiteurs), 300 rapports par heure pour toute la boutique, 4 Ko au plus par rapport écrit.
 */
final class ClientErrorController
{
    private const MAX_BODY_BYTES = 32_768;
    /** Taille d'une ligne du journal : au-delà, la pile d'appels puis les données annexes sont retirées. */
    private const MAX_RECORD_BYTES = 4096;

    public function __construct(
        #[Autowire(service: 'monolog.logger.client_errors')]
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.client_errors')]
        private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire(service: 'limiter.client_errors_total')]
        private readonly RateLimiterFactoryInterface $totalLimiter,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {}

    #[Route('/client-errors', name: 'api_client_errors', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if ($request->getContentTypeFormat() !== 'json') {
            throw new ApiException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Requête invalide : le corps doit être envoyé en JSON.');
        }
        foreach ([[$this->limiter, ClientIp::limiterKey($request->getClientIp(), $this->secret)], [$this->totalLimiter, 'boutique']] as [$factory, $key]) {
            $limit = $factory->create($key)->consume();
            if (!$limit->isAccepted()) {
                throw ApiException::tooManyRequests('RATE_LIMITED', 'Trop d’envois. Veuillez réessayer plus tard.', max(1, $limit->getRetryAfter()->getTimestamp() - time()));
            }
        }

        $content = JsonBody::content($request, self::MAX_BODY_BYTES);

        try {
            $body = json_decode($content, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = null;
        }
        if (!\is_array($body) || array_is_list($body)) {
            throw ApiException::validation(['_' => 'Le contenu envoyé est invalide.']);
        }

        $errors = [];
        foreach (['message', 'page', 'appVersion', 'userAgent', 'occurredAt'] as $field) {
            if (!\is_string($body[$field] ?? null) || trim($body[$field]) === '') {
                $errors[$field] = 'Champ obligatoire.';
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        $record = array_filter([
            'message'    => $this->text($body['message'], 500),
            'stack'      => \is_string($body['stack'] ?? null) ? $this->stack($body['stack']) : null,
            // Chemin seul : jamais de paramètres ni d'ancre (contrat §13.1).
            'page'       => $this->text((string) strtok($body['page'], '?#'), 200),
            'appVersion' => $this->text($body['appVersion'], 40),
            'userAgent'  => $this->text($body['userAgent'], 300),
            'zone'       => \is_string($body['zone'] ?? null) ? $this->text($body['zone'], 80) : null,
            'route'      => \is_string($body['route'] ?? null) ? $this->text($body['route'], 200) : null,
            'extra'      => \is_array($body['extra'] ?? null) ? $this->extra($body['extra']) : null,
            'occurredAt' => $this->text($body['occurredAt'], 40),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
        foreach (['stack', 'extra'] as $optional) {
            if (\strlen((string) json_encode($record, \JSON_UNESCAPED_UNICODE)) > self::MAX_RECORD_BYTES) {
                unset($record[$optional]);
            }
        }
        $this->logger->warning('Erreur de l’application', $record);

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** Texte sur une ligne, sans caractères de contrôle, coupé à la longueur donnée. */
    private function text(string $value, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, $max);
    }

    /** Pile d'appels : 15 lignes et 2 000 caractères au plus. */
    private function stack(string $value): string
    {
        $lines = \array_slice(preg_split('/\R/u', $value) ?: [], 0, 15);

        return mb_substr(implode("\n", array_map(fn (string $l) => $this->text($l, 300), $lines)), 0, 2000);
    }

    /**
     * Valeurs simples seulement (texte de 200 caractères au plus, nombre, booléen), 20 au plus.
     *
     * @param array<mixed> $extra
     *
     * @return array<string, scalar>
     */
    private function extra(array $extra): array
    {
        $clean = [];
        foreach (\array_slice($extra, 0, 20, true) as $key => $value) {
            $key = $this->text((string) $key, 40);
            if (\is_string($value)) {
                $clean[$key] = $this->text($value, 200);
            } elseif (\is_int($value) || \is_float($value) || \is_bool($value)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
