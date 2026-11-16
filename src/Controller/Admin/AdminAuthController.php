<?php

namespace App\Controller\Admin;

use App\Admin\AdminLogin;
use App\Admin\AdminNormalizer;
use App\Api\ApiErrorResponse;
use App\Api\ApiException;
use App\Api\ApiJson;
use App\Api\JsonBody;
use App\Entity\RefreshToken;
use App\Security\Exception\RefreshDeniedException;
use App\Service\Auth\RefreshTokenService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Connexion de l'équipe DR Choco (contrat admin §2) : session SÉPARÉE de celle des clients.
 * Cookie « drc_admin_refresh » (httpOnly, SameSite=Strict, Path=/api/admin/auth), 12 h au plus.
 */
final class AdminAuthController
{
    public function __construct(
        private readonly AdminLogin $login,
        private readonly RefreshTokenService $refreshTokens,
        private readonly AdminNormalizer $normalizer,
        private readonly LoggerInterface $logger,
    ) {}

    /** POST /api/admin/auth/login — `{ identifier, password }` (identifiant = e-mail ou téléphone +237). */
    #[Route('/admin/auth/login', name: 'api_admin_auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $body = JsonBody::object($request);
        $identifier = \is_string($body['identifier'] ?? null) ? trim($body['identifier']) : '';
        $password = \is_string($body['password'] ?? null) ? $body['password'] : '';
        if ($identifier === '' || $password === '' || mb_strlen($identifier) > 180 || \strlen($password) > 4096) {
            throw ApiException::validation(
                ['identifier' => 'Identifiant ou mot de passe manquant.'],
                'Veuillez renseigner votre identifiant et votre mot de passe.',
            );
        }

        $result = $this->login->login($identifier, $password, $request);

        $response = ApiJson::noStore(['accessToken' => $result['accessToken'], 'admin' => $this->normalizer->admin($result['admin'])]);
        $response->headers->setCookie($result['cookie']);

        return $response;
    }

    /**
     * POST /api/admin/auth/refresh — 401 ADMIN_UNAUTHORIZED si la session est refusée (expirée après 12 h,
     * révoquée, compte désactivé) ; une panne répond 500 et le front garde la session.
     */
    #[Route('/admin/auth/refresh', name: 'api_admin_auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        try {
            $result = $this->refreshTokens->rotateAdmin($request);
        } catch (RefreshDeniedException) {
            $response = ApiErrorResponse::create(401, 'ADMIN_UNAUTHORIZED', 'Session administrateur expirée. Veuillez vous reconnecter.');
            $response->headers->setCookie($this->refreshTokens->clearCookie(RefreshToken::TYPE_ADMIN, $request));

            return $response;
        }

        $response = ApiJson::noStore(['accessToken' => $result['accessToken'], 'admin' => $this->normalizer->admin($result['admin'])]);
        $response->headers->setCookie($result['cookie']);

        return $response;
    }

    /** POST /api/admin/auth/logout — 204 dans tous les cas ; la session de cet appareil est révoquée. */
    #[Route('/admin/auth/logout', name: 'api_admin_auth_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        try {
            $cookie = $this->refreshTokens->revokeCurrentSession(RefreshToken::TYPE_ADMIN, $request);
        } catch (\Throwable $e) {
            $this->logger->error('Déconnexion admin : révocation de la session impossible.', ['exception' => $e]);
            $cookie = $this->refreshTokens->clearCookie(RefreshToken::TYPE_ADMIN, $request);
        }

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->setCookie($cookie);

        return $response;
    }
}
