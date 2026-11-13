<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/health — état de l'API (supervision, vérification après une mise en ligne).
 * Ne renvoie aucune donnée sensible.
 */
final class HealthController
{
    #[Route('/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(Connection $connection, ClockInterface $clock): JsonResponse
    {
        try {
            $connection->executeQuery('SELECT 1')->fetchOne();
            $database = 'ok';
        } catch (\Throwable) {
            $database = 'error';
        }

        $body = [
            'status'   => $database === 'ok' ? 'ok' : 'degraded',
            'service'  => 'drchoco-api',
            'time'     => $clock->now()->format(\DATE_ATOM),
            'database' => $database,
        ];
        if ($database !== 'ok') {
            // Toute réponse 5xx de /api suit aussi le format d'erreur du contrat (§2.2).
            $body = ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'Service momentanément indisponible. Veuillez réessayer dans quelques instants.'] + $body;
        }

        $response = new JsonResponse($body, $database === 'ok' ? 200 : 503);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
