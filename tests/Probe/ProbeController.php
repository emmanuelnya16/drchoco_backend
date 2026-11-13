<?php

namespace App\Tests\Probe;

use App\Entity\Admin;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Routes de sonde chargées UNIQUEMENT en environnement de test (config/routes.yaml, when@test).
 * Elles servent à vérifier les pare-feux JWT avant que les vraies routes existent.
 */
final class ProbeController
{
    public function __construct(private readonly Security $security) {}

    /** Route client : protégée par la règle par défaut « ^/api => ROLE_USER ». */
    #[Route('/_probe/me', name: 'test_probe_user', methods: ['GET'])]
    public function user(): JsonResponse
    {
        return $this->identity();
    }

    /** Route admin : protégée par « ^/api/admin => ROLE_ADMIN ». */
    #[Route('/admin/_probe/me', name: 'test_probe_admin', methods: ['GET'])]
    public function admin(): JsonResponse
    {
        return $this->identity();
    }

    /** Route publique (même règle que /api/settings/public) : renvoie l'identité si un jeton valide est présent. */
    #[Route('/settings/public/_probe', name: 'test_probe_public', methods: ['GET'])]
    public function public(): JsonResponse
    {
        return $this->identity();
    }

    private function identity(): JsonResponse
    {
        $user = $this->security->getUser();

        return new JsonResponse([
            'identifier' => $user?->getUserIdentifier(),
            'type'       => match (true) {
                $user instanceof User  => 'user',
                $user instanceof Admin => 'admin',
                default                => null,
            },
            'roles'      => $user?->getRoles() ?? [],
        ]);
    }
}
