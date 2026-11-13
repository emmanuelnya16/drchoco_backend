<?php

namespace App\Security;

use App\Entity\Admin;
use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Coupe immédiatement l'accès d'un client bloqué ou d'un compte admin désactivé,
 * même si son JWT n'a pas encore expiré (sinon il garderait l'accès jusqu'à 1 h).
 */
final class AccountStatusChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && $user->isBlocked()) {
            throw new CustomUserMessageAccountStatusException('Ce compte est bloqué.');
        }

        if ($user instanceof Admin && !$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('Ce compte est désactivé.');
        }
    }

    public function checkPostAuth(UserInterface $user, mixed $token = null): void
    {
    }
}
