<?php

namespace App\Tests\Api;

use App\Entity\Admin;
use App\Entity\User;
use App\Tests\ApiTestCase;

/**
 * P0 — JWT (mêmes clés et même configuration lexik que le backend de référence) et pare-feux.
 */
final class JwtFirewallTest extends ApiTestCase
{
    public function testProtectedRouteWithoutTokenIs401Unauthorized(): void
    {
        $body = $this->getJson('/api/_probe/me');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('UNAUTHORIZED', $body['code']);
    }

    public function testClientTokenOpensClientRoute(): void
    {
        $user = $this->createUser();
        $body = $this->getJson('/api/_probe/me', $this->tokenFor($user));

        self::assertResponseIsSuccessful();
        self::assertSame('+237690000001', $body['identifier']);
        self::assertSame('user', $body['type']);
    }

    public function testExpiredTokenOnProtectedRouteIs401(): void
    {
        $user = $this->createUser();
        $body = $this->getJson('/api/_probe/me', $this->expiredTokenFor($user->getPhone()));

        self::assertResponseStatusCodeSame(401);
        self::assertSame('UNAUTHORIZED', $body['code']);
        self::assertSame('Votre session a expiré. Veuillez vous reconnecter.', $body['message']);
    }

    /** Contrat §2.10 : un jeton client valide sur /api/admin reçoit 403 (droit refusé), pas 401. */
    public function testClientTokenIsForbiddenOnAdminRoutes(): void
    {
        $user = $this->createUser();
        $body = $this->getJson('/api/admin/_probe/me', $this->tokenFor($user));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('FORBIDDEN', $body['code']);
    }

    public function testAdminTokenIsForbiddenOnClientRoutes(): void
    {
        $owner = $this->createAdmin();
        $body = $this->getJson('/api/_probe/me', $this->tokenFor($owner));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('FORBIDDEN', $body['code']);
    }

    public function testAdminRouteWithoutTokenUsesAdminCode(): void
    {
        $body = $this->getJson('/api/admin/_probe/me');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('ADMIN_UNAUTHORIZED', $body['code']);
    }

    /**
     * Les clés RSA sont partagées avec le backend de référence : un jeton émis là-bas
     * (sans la marque « drchoco ») ne doit JAMAIS ouvrir un compte DR Choco.
     */
    public function testTokenIssuedByAnotherApplicationIsRejected(): void
    {
        $user = $this->createUser();
        $foreign = static::getContainer()->get('lexik_jwt_authentication.encoder')->encode([
            'username'  => $user->getPhone(),
            'roles'     => ['ROLE_USER'],
            'type'      => 'user',
            'id'        => 98765,
            'firstName' => 'Autre application',
        ]);

        $body = $this->getJson('/api/_probe/me', $foreign);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('UNAUTHORIZED', $body['code']);
    }

    public function testAdminAndSellerTokensOpenAdminRoutes(): void
    {
        $owner = $this->createAdmin(Admin::ROLE_OWNER, 'owner@drchoco.cm');
        $body = $this->getJson('/api/admin/_probe/me', $this->tokenFor($owner));
        self::assertResponseIsSuccessful();
        self::assertSame('admin', $body['type']);
        self::assertContains('ROLE_OWNER', $body['roles']);

        $seller = $this->createAdmin(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé');
        $body = $this->getJson('/api/admin/_probe/me', $this->tokenFor($seller));
        self::assertResponseIsSuccessful();
        self::assertContains('ROLE_SELLER', $body['roles']);
        self::assertContains('ROLE_ADMIN', $body['roles']);
    }

    public function testBlockedClientAndDisabledAdminAreCutImmediately(): void
    {
        $blocked = $this->createUser('+237690000002', User::STATUS_BLOCKED);
        $this->getJson('/api/_probe/me', $this->tokenFor($blocked));
        self::assertResponseStatusCodeSame(401);

        $disabled = $this->createAdmin(Admin::ROLE_SELLER, 'off@drchoco.cm', 'Douala', active: false);
        $this->getJson('/api/admin/_probe/me', $this->tokenFor($disabled));
        self::assertResponseStatusCodeSame(401);
    }

    /** Contrat §2.4.5 : sur une route publique, un jeton expiré ou invalide est ignoré. */
    public function testPublicRouteIgnoresExpiredOrInvalidToken(): void
    {
        $user = $this->createUser();

        $body = $this->getJson('/api/settings/public/_probe', $this->expiredTokenFor($user->getPhone()));
        self::assertResponseIsSuccessful();
        self::assertNull($body['identifier']);

        $body = $this->getJson('/api/settings/public/_probe', 'nimportequoi');
        self::assertResponseIsSuccessful();
        self::assertNull($body['identifier']);

        // Préfixe « bearer » en minuscules (accepté par lexik) : toujours ignoré s'il est expiré.
        $body = $this->getJson('/api/settings/public/_probe', null, ['HTTP_AUTHORIZATION' => 'bearer ' . $this->expiredTokenFor($user->getPhone())]);
        self::assertResponseIsSuccessful();
        self::assertNull($body['identifier']);

        // Un jeton valide reste pris en compte sur une route publique.
        $body = $this->getJson('/api/settings/public/_probe', $this->tokenFor($user));
        self::assertResponseIsSuccessful();
        self::assertSame('+237690000001', $body['identifier']);
    }

    public function testPublicRouteIgnoresTokenOfBlockedOrDeletedAccount(): void
    {
        $blocked = $this->createUser('+237690000003', User::STATUS_BLOCKED);
        $body = $this->getJson('/api/settings/public/_probe', $this->tokenFor($blocked));
        self::assertResponseIsSuccessful();
        self::assertNull($body['identifier']);

        $gone = $this->createUser('+237690000004');
        $token = $this->tokenFor($gone);
        $this->em()->remove($gone);
        $this->em()->flush();
        $body = $this->getJson('/api/settings/public/_probe', $token);
        self::assertResponseIsSuccessful();
        self::assertNull($body['identifier']);
    }

    private function expiredTokenFor(string $identifier): string
    {
        $encoder = static::getContainer()->get('lexik_jwt_authentication.encoder');

        return $encoder->encode([
            'username' => $identifier,
            'roles'    => ['ROLE_USER'],
            'aud'      => 'drchoco',
            'type'     => 'user',
            'iat'      => time() - 7200,
            'exp'      => time() - 3600,
        ]);
    }
}
