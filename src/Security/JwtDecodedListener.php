<?php

namespace App\Security;

use App\EventListener\JwtCreatedListener;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Contrôles ajoutés APRÈS la vérification de signature de lexik (clés et configuration inchangées) :
 *
 * 1. Le jeton doit avoir été émis par DR Choco (aud = "drchoco"). Les clés RSA sont les mêmes que
 *    celles du backend de référence : sans ce contrôle, un jeton émis là-bas (où l'inscription ne
 *    demande aucun code SMS) ouvrirait le compte DR Choco qui a le même numéro.
 * 2. Le type de compte doit correspondre à la zone : « admin » sous /api/admin, « user » ailleurs.
 *    Un jeton valide du mauvais type reçoit 403 (contrat §2.10), pas 401.
 */
#[AsEventListener(event: Events::JWT_DECODED)]
final class JwtDecodedListener
{
    public const FORBIDDEN_ATTRIBUTE = '_drc_jwt_wrong_type';

    public function __construct(private readonly RequestStack $requestStack) {}

    public function __invoke(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();

        $audience = $payload['aud'] ?? null;
        $audiences = \is_array($audience) ? $audience : [$audience];
        if (!\in_array(JwtCreatedListener::AUDIENCE, $audiences, true)) {
            $event->markAsInvalid();

            return;
        }

        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return;
        }

        $expectedType = str_starts_with($request->getPathInfo(), '/api/admin') ? 'admin' : 'user';
        if (($payload['type'] ?? null) !== $expectedType) {
            $request->attributes->set(self::FORBIDDEN_ATTRIBUTE, true);
            $event->markAsInvalid();
        }
    }
}
