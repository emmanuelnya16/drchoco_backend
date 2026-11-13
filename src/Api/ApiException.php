<?php

namespace App\Api;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/**
 * Erreur métier renvoyée au front au format unique du contrat (§2.2) :
 * { "code": "STOCK_CONFLICT", "message": "…", "details": { … } }
 *
 * Le message est affiché tel quel au client : français, vouvoiement, sans détail technique.
 * Journalisée en « info » : une erreur métier attendue (code faux, stock épuisé…) n'est pas une panne.
 */
#[WithLogLevel(LogLevel::INFO)]
class ApiException extends \RuntimeException
{
    /**
     * @param array<string, mixed>  $details
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $errorCode,
        string $message,
        private readonly array $details = [],
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notFound(string $code = 'NOT_FOUND', string $message = 'La ressource demandée est introuvable.'): self
    {
        return new self(404, $code, $message);
    }

    public static function forbidden(string $message = 'Vous n’avez pas l’autorisation d’effectuer cette action.'): self
    {
        return new self(403, 'FORBIDDEN', $message);
    }

    /** @param array<string, string> $fieldErrors message par champ (clé = propertyPath) */
    public static function validation(array $fieldErrors, string $message = 'Certaines informations saisies sont invalides.'): self
    {
        return new self(422, 'VALIDATION_ERROR', $message, $fieldErrors);
    }

    public static function tooManyRequests(string $code, string $message, int $retryAfter): self
    {
        return new self(429, $code, $message, ['retryAfter' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
    }

    public function getStatusCode(): int { return $this->statusCode; }
    public function getErrorCode(): string { return $this->errorCode; }

    /** @return array<string, mixed> */
    public function getDetails(): array { return $this->details; }

    /** @return array<string, string> */
    public function getHeaders(): array { return $this->headers; }
}
