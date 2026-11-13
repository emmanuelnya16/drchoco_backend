<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Contrat §2.4.5 : sur une route PUBLIQUE (catalogue…), un jeton absent, expiré ou invalide est ignoré.
 *
 * L'app envoie le jeton sur toutes les routes dès que le client est connecté. Sans cette règle,
 * un jeton expiré, d'un compte bloqué ou supprimé ferait répondre 401 au catalogue, et l'app
 * afficherait « Impossible de charger le catalogue ».
 *
 * Fonctionnement : quand l'authentification JWT échoue et que access_control déclare la route
 * en PUBLIC_ACCESS, on annule la réponse d'erreur : la requête continue en visiteur.
 * Sur une route connectée, rien ne change (401 ou 403 normal). La configuration JWT n'est pas modifiée.
 */
#[AsEventListener(event: LoginFailureEvent::class)]
final class PublicRouteAuthFailureListener
{
    public function __construct(
        #[Autowire(service: 'security.access_map')]
        private readonly AccessMapInterface $accessMap,
    ) {}

    public function __invoke(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        [$attributes] = $this->accessMap->getPatterns($request);
        if ($attributes === [AuthenticatedVoter::PUBLIC_ACCESS]) {
            $event->setResponse(null);
        }
    }
}
