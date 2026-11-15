<?php

namespace App\Controller\Catalog;

use App\Api\ApiJson;
use App\Settings\PublicSettingsProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/settings/public — boutique, WhatsApp et paiement Mobile Money (contrat §7.1).
 */
final class SettingsController
{
    #[Route('/settings/public', name: 'api_settings_public', methods: ['GET'])]
    public function __invoke(Request $request, PublicSettingsProvider $settings): JsonResponse
    {
        return ApiJson::publicCached($request, $settings->get());
    }
}
