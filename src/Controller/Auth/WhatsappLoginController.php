<?php

namespace App\Controller\Auth;

use App\Account\PhoneNumber;
use App\Api\ApiException;
use App\Api\ApiJson;
use App\Api\JsonBody;
use App\Auth\Whatsapp\WhatsappConfig;
use App\Auth\Whatsapp\WhatsappLogin;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Connexion du client par WhatsApp (WHATSAPP_OTP.md) : le client envoie une référence à la boutique, qui lui répond
 * avec un code à 6 chiffres, vérifié comme le code SMS (/auth/otp/verify). Route publique, jamais mise en cache.
 */
final class WhatsappLoginController
{
    public function __construct(
        private readonly WhatsappLogin $whatsapp,
    ) {}

    /** POST /api/auth/whatsapp/start — enregistre la demande (numéro saisi + référence du message WhatsApp). */
    #[Route('/auth/whatsapp/start', name: 'api_auth_whatsapp_start', methods: ['POST'])]
    public function start(Request $request): JsonResponse
    {
        $body = JsonBody::object($request);
        $phone = PhoneNumber::normalizeCameroonMobile($body['phone'] ?? null);
        if ($phone === null) {
            throw new ApiException(422, 'INVALID_PHONE', 'Ce numéro semble incomplet. Entrez les 9 chiffres commençant par 6.');
        }

        $reference = null;
        if (($body['reference'] ?? null) !== null) {
            $reference = WhatsappConfig::normalizeReference($body['reference']);
            if ($reference === null) {
                throw new ApiException(422, 'INVALID_REFERENCE', 'Référence invalide : 6 lettres ou chiffres.');
            }
        }

        return ApiJson::noStore($this->whatsapp->start($phone, $reference, $request->getClientIp()));
    }
}
