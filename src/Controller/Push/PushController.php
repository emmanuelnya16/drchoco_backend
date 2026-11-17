<?php

namespace App\Controller\Push;

use App\Api\ApiException;
use App\Api\ApiJson;
use App\Api\ClientIp;
use App\Api\JsonBody;
use App\Entity\Admin;
use App\Entity\User;
use App\Push\PushSubscriptions;
use App\Service\Auth\RefreshTokenService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Abonnement des appareils aux notifications push (contrat §2.11, P7).
 *
 *  - client : `POST /api/push/subscribe` (jeton client, ou cookie de session pour le service worker qui renouvelle
 *    l'abonnement sans jeton) et `POST /api/push/unsubscribe` ;
 *  - équipe : `POST /api/admin/push/subscribe` et `POST /api/admin/push/unsubscribe` (jeton admin).
 */
final class PushController
{
    public function __construct(
        private readonly PushSubscriptions $subscriptions,
        private readonly Security $security,
        private readonly RefreshTokenService $sessions,
        #[Autowire(service: 'limiter.push_subscribe')]
        private readonly RateLimiterFactory $limiter,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {}

    #[Route('/push/subscribe', name: 'api_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        $limit = $this->limiter->create(ClientIp::limiterKey($request->getClientIp(), $this->secret))->consume();
        if (!$limit->isAccepted()) {
            throw ApiException::tooManyRequests('TOO_MANY_REQUESTS', 'Trop de demandes. Réessayez dans quelques minutes.', max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }
        $account = $this->security->getUser();
        $user = $account instanceof User ? $account : ($account === null ? $this->sessions->userFromSessionCookie($request) : null);
        if (!$user instanceof User) {
            throw new ApiException(401, 'UNAUTHORIZED', 'Connectez-vous pour activer les notifications.');
        }

        return $this->subscribed($this->subscriptions->subscribe($user, JsonBody::object($request)));
    }

    #[Route('/push/unsubscribe', name: 'api_push_unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request, #[CurrentUser] User $user): Response
    {
        $this->subscriptions->unsubscribe($user, JsonBody::object($request, allowEmpty: true)['endpoint'] ?? null);

        return new Response(null, 204, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/admin/push/subscribe', name: 'api_admin_push_subscribe', methods: ['POST'])]
    public function adminSubscribe(Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        return $this->subscribed($this->subscriptions->subscribe($admin, JsonBody::object($request)));
    }

    #[Route('/admin/push/unsubscribe', name: 'api_admin_push_unsubscribe', methods: ['POST'])]
    public function adminUnsubscribe(Request $request, #[CurrentUser] Admin $admin): Response
    {
        $this->subscriptions->unsubscribe($admin, JsonBody::object($request, allowEmpty: true)['endpoint'] ?? null);

        return new Response(null, 204, ['Cache-Control' => 'no-store, private']);
    }

    private function subscribed(int $devices): JsonResponse
    {
        return ApiJson::noStore(['ok' => true, 'devices' => $devices], 201);
    }
}
