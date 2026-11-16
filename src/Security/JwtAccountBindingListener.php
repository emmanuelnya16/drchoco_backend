<?php

namespace App\Security;

use App\Entity\Admin;
use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Contrôle ajouté APRÈS la vérification du JWT par lexik (clés et configuration inchangées) :
 * le jeton doit appartenir au compte chargé.
 *
 * Lexik retrouve le compte par l'identifiant de connexion (l'e-mail d'un admin, le téléphone d'un client).
 * Sans ce contrôle, un jeton encore valable pourrait ouvrir un AUTRE compte qui a repris cet e-mail
 * (e-mail modifié, vendeur supprimé puis recréé). Pour l'équipe, le jeton porte aussi l'empreinte du mot de
 * passe : un changement de mot de passe coupe immédiatement les jetons déjà émis.
 *
 * Refus : InvalidTokenException => 401 (UNAUTHORIZED ou ADMIN_UNAUTHORIZED), le front rafraîchit la session.
 */
#[AsEventListener(event: CheckPassportEvent::class)]
final class JwtAccountBindingListener
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {}

    public function __invoke(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();
        $payload = $passport->getAttribute('payload');
        if (!\is_array($payload)) {
            return; // pas une connexion par JWT
        }

        $account = $passport->getUser();
        $expected = match (true) {
            $account instanceof Admin => 'admin',
            $account instanceof User  => 'user',
            default                   => null,
        };
        if ($expected === null) {
            return;
        }

        $sameAccount = ($payload['type'] ?? null) === $expected
            && (string) ($payload['id'] ?? '') === (string) $account->getId();

        if ($sameAccount && $account instanceof Admin) {
            $sameAccount = hash_equals(CredentialFingerprint::of($account, $this->secret), (string) ($payload['pwv'] ?? ''));
        }

        if (!$sameAccount) {
            throw new InvalidTokenException('Invalid JWT Token');
        }
    }
}
