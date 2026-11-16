<?php

namespace App\Security;

use App\Api\RequestPath;
use App\Api\ApiErrorResponse;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Traduit les refus du JWT (lexik) au format d'erreur des contrats :
 *  - jeton absent, expiré ou invalide sur une route connectée => 401
 *    (UNAUTHORIZED côté client, ADMIN_UNAUTHORIZED sous /api/admin) ; le front rafraîchit puis rejoue ;
 *  - jeton valide mais du mauvais type (client sur /api/admin, ou l'inverse) => 403 FORBIDDEN (§2.10).
 *
 * La vérification du jeton elle-même (clés, RS256, durée) reste celle de lexik, inchangée.
 */
final class JwtErrorSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly RequestStack $requestStack) {}

    public static function getSubscribedEvents(): array
    {
        return [
            Events::JWT_NOT_FOUND => 'onNotFound',
            Events::JWT_EXPIRED   => 'onExpired',
            Events::JWT_INVALID   => 'onInvalid',
        ];
    }

    public function onNotFound(JWTNotFoundEvent $event): void
    {
        $event->setResponse($this->unauthorized('Veuillez vous connecter pour continuer.'));
    }

    public function onExpired(JWTExpiredEvent $event): void
    {
        $event->setResponse($this->unauthorized('Votre session a expiré. Veuillez vous reconnecter.'));
    }

    public function onInvalid(JWTInvalidEvent $event): void
    {
        $request = $this->requestStack->getMainRequest();
        if ($request !== null && $request->attributes->get(JwtDecodedListener::FORBIDDEN_ATTRIBUTE) === true) {
            $event->setResponse(ApiErrorResponse::create(403, 'FORBIDDEN', 'Vous n’avez pas l’autorisation d’effectuer cette action.'));

            return;
        }

        $event->setResponse($this->unauthorized('Veuillez vous connecter pour continuer.'));
    }

    private function unauthorized(string $message): JsonResponse
    {
        $request = $this->requestStack->getMainRequest();
        $isAdmin = $request !== null && str_starts_with(RequestPath::of($request), '/api/admin');

        return ApiErrorResponse::create(401, $isAdmin ? 'ADMIN_UNAUTHORIZED' : 'UNAUTHORIZED', $message);
    }
}
