<?php

namespace App\Controller\Auth;

use App\Account\AccountNormalizer;
use App\Account\PhoneNumber;
use App\Api\ApiException;
use App\Api\ApiJson;
use App\Api\JsonBody;
use App\Auth\OtpService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Connexion du client par code SMS (contrat §4.1 et §4.2). Routes publiques, jamais mises en cache.
 */
final class OtpController
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AccountNormalizer $normalizer,
    ) {}

    /** POST /api/auth/otp/request — envoie un code à 6 chiffres par SMS. */
    #[Route('/auth/otp/request', name: 'api_auth_otp_request', methods: ['POST'])]
    public function request(Request $request): JsonResponse
    {
        $phone = $this->phone(JsonBody::object($request));
        $result = $this->otp->request($phone, $request->getClientIp());

        return ApiJson::noStore([
            'message'          => 'Code envoyé par SMS.',
            'expiresIn'        => $result['expiresIn'],
            'resendIn'         => $result['resendIn'],
            'expiresInSeconds' => $result['expiresIn'],
        ]);
    }

    /** POST /api/auth/otp/verify — vérifie le code, crée le compte au besoin et ouvre la session. */
    #[Route('/auth/otp/verify', name: 'api_auth_otp_verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $body = JsonBody::object($request);
        $phone = $this->phone($body);

        $code = \is_string($body['code'] ?? null) ? preg_replace('/\s+/', '', $body['code']) : null;
        if (!\is_string($code) || preg_match('/^\d{6}\z/', $code) !== 1) {
            throw new ApiException(422, 'INVALID_OTP_CODE', 'Le code doit contenir 6 chiffres.');
        }

        // Compte créé au besoin, jeton d'accès (JWT lexik, inchangé) et session longue (cookie httpOnly, 90 jours).
        $result = $this->otp->verify($phone, $code, $request);

        $response = ApiJson::noStore([
            'accessToken' => $result['accessToken'],
            'user'        => $this->normalizer->user($result['user']),
            'isNewUser'   => $result['isNewUser'],
            // Pièces offertes à la création du compte (0 sinon) : l'app fête l'arrivée du client
            'welcomeCoins' => $result['welcomeCoins'],
        ]);
        $response->headers->setCookie($result['cookie']);

        return $response;
    }

    /** @param array<string, mixed> $body */
    private function phone(array $body): string
    {
        $phone = PhoneNumber::normalizeCameroonMobile($body['phone'] ?? null);
        if ($phone === null) {
            throw new ApiException(422, 'INVALID_PHONE', 'Ce numéro semble incomplet. Entrez les 9 chiffres commençant par 6.');
        }

        return $phone;
    }
}
