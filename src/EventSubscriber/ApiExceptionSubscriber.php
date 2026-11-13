<?php

namespace App\EventSubscriber;

use App\Api\ApiErrorResponse;
use App\Api\ApiException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\Exception\RateLimitExceededException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Toute erreur sous /api renvoie le format unique du contrat (§2.2) : { code, message, details? }.
 * Jamais de page HTML d'erreur, jamais de trace technique pour le client.
 *
 * Priorité -10 : après l'écouteur de sécurité (priorité 1), qui transforme d'abord un accès refusé
 * en 401 (non connecté) ou 403 (droit refusé), et après la journalisation de Symfony (priorité 0) ;
 * avant le rendu HTML par défaut (-128).
 *
 * Les codes SERVER_ERROR, HTTP_ERROR, SESSION_EXPIRED… sont réservés à l'app (§2.2) : le serveur
 * utilise INTERNAL_ERROR, SERVICE_UNAVAILABLE, CONFLICT, GONE, REQUEST_ERROR.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    private const MSG_SERVER = 'Erreur serveur momentanée. Veuillez réessayer dans quelques instants.';
    private const MSG_FORBIDDEN = 'Vous n’avez pas l’autorisation d’effectuer cette action.';
    private const MSG_INVALID = 'Certaines informations saisies sont invalides.';

    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $e = $event->getThrowable();

        $response = match (true) {
            $e instanceof ApiException => ApiErrorResponse::create(
                $e->getStatusCode(), $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getHeaders(),
            ),
            $e instanceof RateLimitExceededException => $this->tooManyRequests(
                ['Retry-After' => (string) max(1, $e->getRetryAfter()->getTimestamp() - time())],
            ),
            $e instanceof AccessDeniedException => ApiErrorResponse::create(403, 'FORBIDDEN', self::MSG_FORBIDDEN),
            $e instanceof HttpExceptionInterface => $this->fromHttpException($e, $request),
            // Erreur inattendue (déjà journalisée par Symfony en « critical ») : message neutre.
            default => ApiErrorResponse::create(
                500, 'INTERNAL_ERROR', self::MSG_SERVER,
                $this->debug ? ['debug' => \get_class($e) . ': ' . $e->getMessage()] : [],
            ),
        };

        $event->setResponse($response);
    }

    private function fromHttpException(HttpExceptionInterface $e, Request $request): JsonResponse
    {
        $status = $e->getStatusCode();
        $headers = $e->getHeaders();

        // #[MapRequestPayload] : corps invalide => 422 avec un message par champ.
        $previous = $e instanceof \Throwable ? $e->getPrevious() : null;
        if ($status === 422 && $previous instanceof ValidationFailedException) {
            return ApiErrorResponse::create(
                422, 'VALIDATION_ERROR', self::MSG_INVALID, $this->violationsToDetails($previous->getViolations()), $headers,
            );
        }

        $isAdmin = str_starts_with($request->getPathInfo(), '/api/admin');

        return match (true) {
            $status === 400 => ApiErrorResponse::create(400, 'INVALID_PAYLOAD', 'La requête est invalide.', [], $headers),
            $status === 401 => ApiErrorResponse::create(401, $isAdmin ? 'ADMIN_UNAUTHORIZED' : 'UNAUTHORIZED', 'Veuillez vous connecter pour continuer.', [], $headers),
            $status === 403 => ApiErrorResponse::create(403, 'FORBIDDEN', self::MSG_FORBIDDEN, [], $headers),
            $status === 404 => ApiErrorResponse::create(404, 'NOT_FOUND', 'La ressource demandée est introuvable.', [], $headers),
            $status === 405 => ApiErrorResponse::create(405, 'METHOD_NOT_ALLOWED', 'Cette action n’est pas possible à cette adresse.', [], $headers),
            $status === 409 => ApiErrorResponse::create(409, 'CONFLICT', 'Cette action est en conflit avec l’état actuel. Veuillez réessayer.', [], $headers),
            $status === 410 => ApiErrorResponse::create(410, 'GONE', 'Cette ressource n’est plus disponible.', [], $headers),
            $status === 413 => ApiErrorResponse::create(413, 'FILE_TOO_LARGE', 'Le fichier envoyé est trop volumineux.', [], $headers),
            $status === 415 => ApiErrorResponse::create(415, 'INVALID_PAYLOAD', 'Le format de la requête n’est pas accepté.', [], $headers),
            $status === 422 => ApiErrorResponse::create(422, 'VALIDATION_ERROR', self::MSG_INVALID, [], $headers),
            $status === 429 => $this->tooManyRequests($headers),
            $status === 503 => ApiErrorResponse::create(503, 'SERVICE_UNAVAILABLE', self::MSG_SERVER, [], $headers),
            $status >= 500 => ApiErrorResponse::create($status, 'INTERNAL_ERROR', self::MSG_SERVER, [], $headers),
            default => ApiErrorResponse::create($status, 'REQUEST_ERROR', 'La requête n’a pas pu aboutir.', [], $headers),
        };
    }

    /** @param array<string, string> $headers */
    private function tooManyRequests(array $headers): JsonResponse
    {
        $retryAfter = $this->retryAfterSeconds($headers['Retry-After'] ?? null);
        $headers['Retry-After'] = (string) $retryAfter;

        return ApiErrorResponse::create(
            429, 'RATE_LIMITED', 'Trop de tentatives. Veuillez patienter avant de réessayer.', ['retryAfter' => $retryAfter], $headers,
        );
    }

    /** Retry-After peut être un nombre de secondes ou une date HTTP. */
    private function retryAfterSeconds(?string $value): int
    {
        if ($value === null || $value === '') {
            return 60;
        }
        if (ctype_digit($value)) {
            return max(1, (int) $value);
        }
        $date = strtotime($value);

        return $date !== false ? max(1, $date - time()) : 60;
    }

    /** @return array<string, string> un message par champ, la clé étant le propertyPath */
    private function violationsToDetails(ConstraintViolationListInterface $violations): array
    {
        $details = [];
        foreach ($violations as $violation) {
            $path = $violation->getPropertyPath() !== '' ? $violation->getPropertyPath() : '_';
            $details[$path] ??= (string) $violation->getMessage();
        }

        return $details;
    }
}
