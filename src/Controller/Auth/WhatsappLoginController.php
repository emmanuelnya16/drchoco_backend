<?php

namespace App\Controller\Auth;

use App\Account\AccountNormalizer;
use App\Account\PhoneNumber;
use App\Api\ApiException;
use App\Api\ApiJson;
use App\Api\JsonBody;
use App\Auth\Whatsapp\WhatsappLogin;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Connexion du client par WhatsApp (WHATSAPP_OTP.md §4.1, §4.2) : le client envoie lui-même le code à la boutique.
 * Routes publiques, jamais mises en cache ; la connexion par SMS reste possible (/auth/otp/*).
 */
final class WhatsappLoginController
{
    public function __construct(
        private readonly WhatsappLogin $whatsapp,
        private readonly AccountNormalizer $normalizer,
    ) {}

    /** POST /api/auth/whatsapp/start — code DRC-XXXXXX à envoyer depuis le WhatsApp de ce numéro. */
    #[Route('/auth/whatsapp/start', name: 'api_auth_whatsapp_start', methods: ['POST'])]
    public function start(Request $request): JsonResponse
    {
        $phone = PhoneNumber::normalizeCameroonMobile(JsonBody::object($request)['phone'] ?? null);
        if ($phone === null) {
            throw new ApiException(422, 'INVALID_PHONE', 'Ce numéro semble incomplet. Entrez les 9 chiffres commençant par 6.');
        }

        return ApiJson::noStore($this->whatsapp->start($phone, $request->getClientIp()));
    }

    /** POST /api/auth/whatsapp/status — en attente, ou session ouverte (même réponse que /auth/otp/verify). */
    #[Route('/auth/whatsapp/status', name: 'api_auth_whatsapp_status', methods: ['POST'])]
    public function status(Request $request): JsonResponse
    {
        $requestId = JsonBody::object($request)['requestId'] ?? null;
        $result = $this->whatsapp->status(\is_string($requestId) ? $requestId : '', $request);

        if ($result['status'] === 'pending') {
            return ApiJson::noStore($result);
        }

        $response = ApiJson::noStore([
            'status'      => 'confirmed',
            'accessToken' => $result['accessToken'],
            'user'        => $this->normalizer->user($result['user']),
            'isNewUser'   => $result['isNewUser'],
        ]);
        $response->headers->setCookie($result['cookie']);

        return $response;
    }
}
