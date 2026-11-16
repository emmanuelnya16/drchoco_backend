<?php

namespace App\Controller\Auth;

use App\Account\AccountNormalizer;
use App\Api\ApiErrorResponse;
use App\Api\ApiJson;
use App\Entity\RefreshToken;
use App\Security\Exception\RefreshDeniedException;
use App\Service\Auth\RefreshTokenService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Session du client (contrat §2.4, §4.3, §4.4) : cookie « drc_refresh » (httpOnly, Path=/api, 90 jours
 * glissants), rotation à chaque rafraîchissement avec délai de grâce de 30 s (RefreshTokenService, P0).
 */
final class SessionController
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokens,
        private readonly AccountNormalizer $normalizer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * POST /api/auth/refresh — nouveau jeton d'accès et nouveau cookie.
     *
     * 401 NO_SESSION seulement si la session est réellement refusée (cookie absent, expiré, révoqué,
     * réutilisé, compte bloqué) : le front efface alors la session. Une panne répond 500 : le front garde
     * la session et réessaiera (contrat §2.4.4).
     */
    #[Route('/auth/refresh', name: 'api_auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        try {
            $result = $this->refreshTokens->rotateUser($request);
        } catch (RefreshDeniedException) {
            $response = ApiErrorResponse::create(401, 'NO_SESSION', 'Aucune session active.');
            $response->headers->setCookie($this->refreshTokens->clearCookie(RefreshToken::TYPE_USER, $request));

            return $response;
        }

        $response = ApiJson::noStore([
            'accessToken' => $result['accessToken'],
            'user'        => $this->normalizer->user($result['user']),
        ]);
        $response->headers->setCookie($result['cookie']);

        return $response;
    }

    /**
     * POST /api/auth/logout — 204 dans tous les cas (même session déjà expirée) : la session de CET appareil
     * est révoquée et le cookie effacé. Le jeton d'accès n'est jamais exigé.
     */
    #[Route('/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        try {
            $cookie = $this->refreshTokens->revokeCurrentSession(RefreshToken::TYPE_USER, $request);
        } catch (\Throwable $e) {
            // Panne pendant la révocation : le cookie est quand même effacé, le front déconnecte toujours.
            $this->logger->error('Déconnexion : révocation de la session impossible.', ['exception' => $e]);
            $cookie = $this->refreshTokens->clearCookie(RefreshToken::TYPE_USER, $request);
        }

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->setCookie($cookie);

        return $response;
    }
}
