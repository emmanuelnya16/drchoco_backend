<?php

namespace App\EventListener;

use App\Entity\Admin;
use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;

/**
 * Enrichit le contenu du JWT — repris du backend de référence.
 *
 * Contenu par défaut de lexik : { "iat", "exp", "roles", "username" }.
 * Ajouté ici : le type de compte (user|admin), l'identifiant et quelques informations d'affichage.
 *
 * Ajout DR Choco : la marque « aud » = "drchoco". Les clés RSA étant partagées avec le backend de
 * référence, un jeton émis par l'autre application serait sinon accepté ici pour le même numéro.
 * App\Security\JwtDecodedListener refuse tout jeton qui ne porte pas cette marque.
 */
class JwtCreatedListener
{
    public const AUDIENCE = 'drchoco';

    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $payload = $event->getData();
        $user    = $event->getUser();

        $payload['aud'] = self::AUDIENCE;

        if ($user instanceof User) {
            $payload['type']      = 'user';
            $payload['id']        = $user->getId();
            $payload['firstName'] = $user->getFirstName();
            $payload['phone']     = $user->getPhone();
            $payload['city']      = $user->getCity();
        }

        if ($user instanceof Admin) {
            $payload['type']      = 'admin';
            $payload['id']        = $user->getId();
            $payload['firstName'] = $user->getName(); // même clé que la référence
            $payload['name']      = $user->getName();
            $payload['role']      = $user->getRole();
            $payload['email']     = $user->getEmail();
            $payload['city']      = $user->getCity();
        }

        $event->setData($payload);
    }
}
