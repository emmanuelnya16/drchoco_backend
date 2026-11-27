<?php

namespace App\Controller\Catalog;

use App\Api\ApiJson;
use App\Auth\Whatsapp\WhatsappConfig;
use App\Settings\PublicSettingsProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/settings/public — boutique, WhatsApp et paiement Mobile Money (contrat §7.1). Jamais gardée par le navigateur :
 * un code marchand corrigé est vu aussitôt.
 * `whatsappLogin` : numéro à qui demander son code de connexion sur WhatsApp (WHATSAPP_OTP.md), ou null si ce n'est pas
 * possible (réglages Meta incomplets) : l'app propose alors seulement le code par SMS.
 */
final class SettingsController
{
    #[Route('/settings/public', name: 'api_settings_public', methods: ['GET'])]
    public function __invoke(Request $request, PublicSettingsProvider $settings, WhatsappConfig $whatsapp): JsonResponse
    {
        return ApiJson::publicRevalidated($request, $settings->get() + [
            'whatsappLogin' => $whatsapp->isLoginReady() ? ['number' => (string) $whatsapp->businessNumber] : null,
        ]);
    }
}
