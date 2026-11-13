<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Réponse d'erreur au format unique du contrat (§2.2), jamais mise en cache.
 */
final class ApiErrorResponse
{
    /**
     * @param array<string, mixed>  $details
     * @param array<string, string> $headers
     */
    public static function create(int $status, string $code, string $message, array $details = [], array $headers = []): JsonResponse
    {
        $body = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $body['details'] = $details;
        }

        $response = new JsonResponse($body, $status, $headers);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
