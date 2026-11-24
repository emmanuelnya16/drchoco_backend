<?php

namespace App\Tests\Api\Admin;

use App\Entity\Admin;
use App\Entity\Product;
use App\Tests\AdminTestCase;

/**
 * GET, POST, PATCH, DELETE /api/admin/sellers : comptes vendeurs gérés par les super admins.
 */
final class SellersTest extends AdminTestCase
{
    private const NEW_SELLER = [
        'name'     => 'Vendeur Bonapriso',
        'email'    => 'Bonapriso@DRChoco.cm',
        'phone'    => '+237 6 99 11 22 33',
        'password' => 'Bonapriso-Vente-26',
        'city'     => 'Douala',
    ];

    public function testOnlySuperAdminsManageSellers(): void
    {
        $this->account();
        $this->account(Admin::ROLE_MANAGER, 'manager@drchoco.cm');
        $seller = $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé');

        foreach (['owner@drchoco.cm', 'manager@drchoco.cm'] as $email) {
            $this->api('GET', '/api/admin/sellers', $this->adminToken($email));
            self::assertSame(200, $this->httpStatus(), $email);
        }

        $sellerToken = $this->adminToken('vendeur@drchoco.cm');
        foreach ([
            ['GET', '/api/admin/sellers', null],
            ['POST', '/api/admin/sellers', self::NEW_SELLER],
            ['PATCH', '/api/admin/sellers/' . $seller->getId(), ['city' => 'Douala']],
            ['DELETE', '/api/admin/sellers/' . $seller->getId(), null],
        ] as [$method, $uri, $body]) {
            $data = $this->api($method, $uri, $sellerToken, $body);
            self::assertSame(403, $this->httpStatus(), "$method $uri");
            self::assertSame('FORBIDDEN', $data['code']);
        }
        self::assertSame('Yaoundé', $this->reloadAdmin('vendeur@drchoco.cm')->getCity(), 'rien n’a changé');
    }

    public function testListShowsSellersWithTheirProducts(): void
    {
        $this->account();
        $yaounde = $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé', '+237690000020');
        $this->account(Admin::ROLE_SELLER, 'vendeur.douala@drchoco.cm', 'Douala');
        $this->mutateProduct('cargo-tactique-bitume', fn (Product $p) => $p->setSeller($this->em()->find(Admin::class, $yaounde->getId())));

        $data = $this->api('GET', '/api/admin/sellers', $this->adminToken('owner@drchoco.cm'));
        self::assertSame(['sellers'], array_keys($data));
        self::assertSame(['vendeur@drchoco.cm', 'vendeur.douala@drchoco.cm'], array_column($data['sellers'], 'email'), 'le propriétaire n’est pas un vendeur');

        $first = $data['sellers'][0];
        self::assertSame([
            'id', 'name', 'email', 'phone', 'role', 'city', 'active', 'createdAt',
            'productsCount', 'unitsSoldTotal', 'revenueConfirmedTotal', 'kpis',
        ], array_keys($first));
        self::assertSame(['seller', 'Yaoundé', true, '+237690000020'], [$first['role'], $first['city'], $first['active'], $first['phone']]);
        // Articles de départ de sa ville (sans vendeur) + le sien, comme le calcul du front.
        self::assertSame(12, $first['productsCount']);
        // Un second vendeur de Yaoundé qui possède un article : il n'est plus compté chez le premier.
        $second = $this->account(Admin::ROLE_SELLER, 'vendeur2@drchoco.cm', 'Yaoundé');
        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setSeller($this->em()->find(Admin::class, $second->getId())));
        $counts = array_column($this->api('GET', '/api/admin/sellers', $this->adminToken('owner@drchoco.cm'))['sellers'], 'productsCount', 'email');
        self::assertSame(['vendeur@drchoco.cm' => 11, 'vendeur.douala@drchoco.cm' => 12, 'vendeur2@drchoco.cm' => 11], $counts);
        self::assertSame(['today', '7d', '30d', 'year'], array_keys($first['kpis']));
        self::assertSame(['unitsSold' => 0, 'revenueConfirmed' => 0, 'reservationsConfirmed' => 0], $first['kpis']['30d']);
        self::assertSame(12, $data['sellers'][1]['productsCount']);
    }

    public function testCreateASellerWhoCanThenLogIn(): void
    {
        $this->account();
        $token = $this->adminToken('owner@drchoco.cm');

        $data = $this->api('POST', '/api/admin/sellers', $token, self::NEW_SELLER);
        self::assertSame(201, $this->httpStatus());
        self::assertSame(['seller'], array_keys($data));
        self::assertSame(['bonapriso@drchoco.cm', '+237699112233', 'seller', 'Douala', true], [
            $data['seller']['email'], $data['seller']['phone'], $data['seller']['role'], $data['seller']['city'], $data['seller']['active'],
        ]);
        self::assertArrayNotHasKey('password', $data['seller']);

        $login = $this->adminLogin('699112233', 'Bonapriso-Vente-26');
        self::assertSame(200, $this->httpStatus());
        self::assertSame('Douala', $login['admin']['city']);

        // Préfixe laissé seul dans le formulaire (« +237 6 ») : pas de téléphone.
        $data = $this->api('POST', '/api/admin/sellers', $token, ['email' => 'sans.tel@drchoco.cm', 'phone' => '+237 6'] + self::NEW_SELLER);
        self::assertSame(201, $this->httpStatus());
        self::assertSame('', $data['seller']['phone']);
    }

    public function testCreateValidation(): void
    {
        $this->account();
        $token = $this->adminToken('owner@drchoco.cm');

        $data = $this->api('POST', '/api/admin/sellers', $token, ['password' => 'court'] + self::NEW_SELLER);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('Le mot de passe doit contenir au moins 10 caractères.', $data['message'], 'message affiché tel quel par le front');

        // Mot de passe pré-rempli par le formulaire du front, mots de passe connus, e-mail ou téléphone : refusés.
        foreach ([
            'choco2026', 'CHOCO2026', 'motdepasse1', 'aaaaaaaaaaaa', 'bonapriso@drchoco.cm', 'Tel699112233', ' Espace-autour ',
            '12345678901', 'azertyuiop1', 'password1234', 'P@ssw0rd2026', 'Choco2026!', 'drchoco2026!', 'DrChoco.2026', 'Ch0c0-2026', 'chocolat2027', 'abcdefghij', 'aaaaaaaaab', 'azerty1234', 'Yaounde2026', 'Douala2026!', 'Orange-2026',
        ] as $weak) {
            $data = $this->api('POST', '/api/admin/sellers', $token, ['password' => $weak] + self::NEW_SELLER);
            self::assertSame(422, $this->httpStatus(), $weak);
            self::assertSame(['password'], array_keys($data['details']), $weak);
        }

        $data = $this->api('POST', '/api/admin/sellers', $token, ['name' => '', 'email' => 'pas-un-email', 'phone' => '612', 'password' => '', 'city' => 'Bafoussam']);
        self::assertSame(['name', 'email', 'phone', 'password', 'city'], array_keys($data['details']));

        // E-mail ou téléphone déjà pris (y compris par le propriétaire).
        $this->api('POST', '/api/admin/sellers', $token, self::NEW_SELLER);
        foreach ([['email' => 'OWNER@drchoco.cm', 'phone' => ''], ['email' => 'autre@drchoco.cm']] as $override) {
            $data = $this->api('POST', '/api/admin/sellers', $token, $override + self::NEW_SELLER);
            self::assertSame(409, $this->httpStatus());
            self::assertSame('DUPLICATE_SELLER', $data['code']);
        }
    }

    public function testChangingCityMovesTheSellersProducts(): void
    {
        $this->account();
        $seller = $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé');
        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setSeller($this->em()->find(Admin::class, $seller->getId())));

        $data = $this->api('PATCH', '/api/admin/sellers/' . $seller->getId(), $this->adminToken('owner@drchoco.cm'), ['city' => 'douala']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('Douala', $data['seller']['city']);

        self::assertSame('Douala', $this->getJson('/api/products/hoodie-barbele-noir')['city']);
        self::assertSame('Yaoundé', $this->getJson('/api/products/cargo-tactique-bitume')['city'], 'les articles des autres ne bougent pas');
    }

    public function testDeactivationCutsAccessImmediately(): void
    {
        $this->account();
        $seller = $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé');
        $ownerToken = $this->adminToken('owner@drchoco.cm');
        $sellerToken = $this->adminToken('vendeur@drchoco.cm');
        $sellerCookie = $this->adminRefreshCookie()->getValue();

        $data = $this->api('PATCH', '/api/admin/sellers/' . $seller->getId(), $ownerToken, ['active' => false]);
        self::assertFalse($data['seller']['active']);

        // Jeton encore valide dans le temps : refusé quand même ; session longue révoquée ; connexion refusée.
        $this->api('GET', '/api/admin/search?q=hoodie', $sellerToken);
        self::assertSame(401, $this->httpStatus());
        $this->setAdminRefreshCookie($sellerCookie);
        $this->adminRefresh();
        self::assertSame(401, $this->httpStatus());
        self::assertSame('ACCOUNT_DISABLED', $this->adminLogin('vendeur@drchoco.cm')['code']);

        // Réactivé : il peut se reconnecter.
        $this->api('PATCH', '/api/admin/sellers/' . $seller->getId(), $ownerToken, ['active' => true]);
        $this->adminLogin('vendeur@drchoco.cm');
        self::assertSame(200, $this->httpStatus());
    }

    public function testSessionsCutAtDeactivationStayDeadAfterReactivation(): void
    {
        $this->account();
        $seller = $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé');
        $ownerToken = $this->adminToken('owner@drchoco.cm');
        $this->adminLogin('vendeur@drchoco.cm');
        $sellerCookie = $this->adminRefreshCookie()->getValue();

        // Désactivé puis réactivé sans que l'ancienne session ait servi entre-temps.
        $this->api('PATCH', '/api/admin/sellers/' . $seller->getId(), $ownerToken, ['active' => false]);
        $this->api('PATCH', '/api/admin/sellers/' . $seller->getId(), $ownerToken, ['active' => true]);

        $this->setAdminRefreshCookie($sellerCookie);
        $this->adminRefresh();
        self::assertSame(401, $this->httpStatus(), 'l’ancienne session ne revit pas');
    }

    public function testNewPasswordAndEmail(): void
    {
        $this->account();
        $seller = $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé');
        $ownerToken = $this->adminToken('owner@drchoco.cm');
        $sellerToken = $this->adminLogin('vendeur@drchoco.cm')['accessToken'];
        $sellerCookie = $this->adminRefreshCookie()->getValue();

        $this->api('PATCH', '/api/admin/sellers/' . $seller->getId(), $ownerToken, ['password' => 'NouveauMotDePasse', 'email' => 'nouveau@drchoco.cm', 'name' => 'Vendeur Mvan']);
        self::assertSame(200, $this->httpStatus());

        $this->api('GET', '/api/admin/dashboard', $sellerToken);
        self::assertSame(401, $this->httpStatus(), 'jeton d’accès en cours refusé aussitôt');

        $this->setAdminRefreshCookie($sellerCookie);
        $this->adminRefresh();
        self::assertSame(401, $this->httpStatus(), 'mot de passe changé : sessions coupées');
        self::assertSame('INVALID_CREDENTIALS', $this->adminLogin('nouveau@drchoco.cm')['code'], 'ancien mot de passe refusé');
        $data = $this->adminLogin('nouveau@drchoco.cm', 'NouveauMotDePasse');
        self::assertSame(200, $this->httpStatus());
        self::assertSame('Vendeur Mvan', $data['admin']['name']);

        // Mot de passe vide : inchangé ; trop court : refusé.
        $this->api('PATCH', '/api/admin/sellers/' . $seller->getId(), $ownerToken, ['password' => '']);
        self::assertSame(200, $this->httpStatus());
        $this->api('PATCH', '/api/admin/sellers/' . $seller->getId(), $ownerToken, ['password' => 'abc']);
        self::assertSame(422, $this->httpStatus());
    }

    public function testSuperAdminsAreNotSellers(): void
    {
        $owner = $this->account();
        $token = $this->adminToken('owner@drchoco.cm');

        foreach ([['PATCH', ['active' => false]], ['DELETE', null]] as [$method, $body]) {
            foreach ([(string) $owner->getId(), '999999', 'abc'] as $id) {
                $data = $this->api($method, '/api/admin/sellers/' . $id, $token, $body);
                self::assertSame(404, $this->httpStatus(), "$method $id");
                self::assertSame('SELLER_NOT_FOUND', $data['code']);
            }
        }
        self::assertTrue($this->reloadAdmin('owner@drchoco.cm')->isActive());
    }

    public function testDeleteOnlyWithoutProducts(): void
    {
        $this->account();
        $seller = $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé');
        $token = $this->adminToken('owner@drchoco.cm');
        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setSeller($this->em()->find(Admin::class, $seller->getId())));

        $data = $this->api('DELETE', '/api/admin/sellers/' . $seller->getId(), $token);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('SELLER_HAS_PRODUCTS', $data['code']);

        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setSeller(null));
        $this->api('DELETE', '/api/admin/sellers/' . $seller->getId(), $token);
        self::assertSame(204, $this->httpStatus());
        self::assertNull($this->reloadAdmin('vendeur@drchoco.cm'));
        self::assertSame('INVALID_CREDENTIALS', $this->adminLogin('vendeur@drchoco.cm')['code']);
    }
}
