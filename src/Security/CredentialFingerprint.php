<?php

namespace App\Security;

use App\Entity\Admin;

/**
 * Empreinte du mot de passe d'un compte de l'équipe, mise dans son jeton d'accès (« pwv »).
 * Un changement de mot de passe change l'empreinte : les jetons déjà émis sont aussitôt refusés
 * (App\Security\JwtAccountBindingListener), sans attendre leur expiration. Ni le mot de passe ni son
 * hachage ne peuvent être retrouvés à partir de l'empreinte (HMAC avec le secret de l'application).
 */
final class CredentialFingerprint
{
    public static function of(Admin $admin, string $secret): string
    {
        return substr(hash_hmac('sha256', $admin->getPassword(), $secret), 0, 16);
    }
}
