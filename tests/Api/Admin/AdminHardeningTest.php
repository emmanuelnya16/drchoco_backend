<?php

namespace App\Tests\Api\Admin;

use App\Entity\Admin;
use App\Entity\Product;
use App\Tests\AdminTestCase;

/**
 * Durcissements de l'administration (relecture P3) : compteur de connexion par compte, jeton lié à son
 * compte, URL encodées, portée exacte d'un vendeur.
 */
final class AdminHardeningTest extends AdminTestCase
{
    public function testEmailAndPhoneShareOneCounterAndAccentedVariantsNeverMatch(): void
    {
        $this->account(phone: '+237690000000');

        // Une variante accentuée de l'e-mail n'ouvre jamais le compte, même avec le bon mot de passe.
        foreach (['öwner@drchoco.cm', 'ówner@drchoco.cm', "owner@drchoco.cm\u{0301}"] as $variant) {
            self::assertSame('INVALID_CREDENTIALS', $this->adminLogin($variant)['code'], $variant);
        }

        // E-mail et téléphone : un seul compteur (3 + 2 échecs = blocage).
        foreach (['owner@drchoco.cm', 'OWNER@drchoco.cm', 'owner@drchoco.cm', '690000000', '+237 690 00 00 00'] as $identifier) {
            $this->adminLogin($identifier, 'faux-mot-de-passe');
            self::assertSame(401, $this->httpStatus());
        }
        $data = $this->adminLogin('owner@drchoco.cm');
        self::assertSame(429, $this->httpStatus());
        self::assertSame('TOO_MANY_ATTEMPTS', $data['code']);
        $retryAfter = (int) $this->header('Retry-After');
        self::assertGreaterThan(0, $retryAfter);
        self::assertLessThanOrEqual(15 * 60 + 1, $retryAfter); // arrondi à la seconde supérieure
    }

    public function testATokenNeverOpensAnotherAccountThatReusesTheEmail(): void
    {
        $this->account();
        $first = $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé');
        $ownerToken = $this->adminToken('owner@drchoco.cm');
        $oldToken = $this->adminToken('vendeur@drchoco.cm');

        // Le premier vendeur change d'e-mail ; un autre vendeur reprend l'ancien.
        $this->api('PATCH', '/api/admin/sellers/' . $first->getId(), $ownerToken, ['email' => 'ancien@drchoco.cm']);
        $this->api('POST', '/api/admin/sellers', $ownerToken, [
            'name' => 'Vendeur Douala', 'email' => 'vendeur@drchoco.cm', 'phone' => '', 'password' => 'Douala-Vente-2026', 'city' => 'Douala',
        ]);
        self::assertSame(201, $this->httpStatus());

        $data = $this->api('GET', '/api/admin/dashboard', $oldToken);
        self::assertSame(401, $this->httpStatus(), 'l’ancien jeton n’ouvre pas le compte du nouveau vendeur');
        self::assertSame('ADMIN_UNAUTHORIZED', $data['code']);
    }

    public function testEncodedUrlsGetTheSameChecks(): void
    {
        $this->account();

        // « %61 » = « a » : même route, mêmes contrôles d'origine et de format.
        $this->postJson('/api/%61dmin/auth/login', ['identifier' => 'owner@drchoco.cm', 'password' => self::PASSWORD], ['HTTP_ORIGIN' => 'https://site-piege.example']);
        self::assertSame(403, $this->httpStatus());
        $this->client->request('POST', '/api/%61dmin/auth/login', [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode(['identifier' => 'owner@drchoco.cm', 'password' => self::PASSWORD]));
        self::assertSame(415, $this->httpStatus());
        $this->postJson('/api/%61uth/otp/request', ['phone' => '690000001'], ['HTTP_ORIGIN' => 'https://site-piege.example']);
        self::assertSame(403, $this->httpStatus());

        // Les réponses de l'administration restent interdites de cache.
        $token = $this->adminToken('owner@drchoco.cm');
        $this->api('GET', '/api/%61dmin/sellers', $token);
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));
    }

    public function testSellerScopeIsOwnProductsPlusUnassignedOnesOfTheirCity(): void
    {
        $a = $this->account(Admin::ROLE_SELLER, 'a@drchoco.cm', 'Yaoundé');
        $b = $this->account(Admin::ROLE_SELLER, 'b@drchoco.cm', 'Yaoundé');
        // B possède un article de Yaoundé ; A possède un article de Douala.
        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setSeller($this->em()->find(Admin::class, $b->getId())));
        $this->mutateProduct('hoodie-zip-armure', fn (Product $p) => $p->setSeller($this->em()->find(Admin::class, $a->getId())));

        $slugs = array_column($this->api('GET', '/api/admin/search?q=hoodie', $this->adminToken('a@drchoco.cm'))['products'], 'slug');
        self::assertContains('hoodie-zip-armure', $slugs, 'son propre article, même dans une autre ville');
        self::assertNotContains('hoodie-barbele-noir', $slugs, 'jamais l’article d’un autre vendeur');

        $slugsB = array_column($this->api('GET', '/api/admin/search?q=hoodie', $this->adminToken('b@drchoco.cm'))['products'], 'slug');
        self::assertContains('hoodie-barbele-noir', $slugsB);
        self::assertNotContains('hoodie-zip-armure', $slugsB);
    }

    public function testCustomersAreOrderedByLastActivityAndNamelessOnesAreLabelled(): void
    {
        $this->account();
        $paul = $this->createUser('+237677889900');
        $paul->setFirstName('Paul')->setLastName('Essomba');
        $anonymous = $this->createUser('+237690000002');
        $anonymous->setFirstName(null);
        $this->em()->flush();
        $db = $this->em()->getConnection();
        // Paul, inscrit avant, a été actif plus récemment : il passe en tête malgré son identifiant plus petit.
        $db->executeStatement('UPDATE users SET updated_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() + 3600), $paul->getId()]);
        $db->executeStatement('UPDATE users SET updated_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 3600), $anonymous->getId()]);

        $items = $this->api('GET', '/api/admin/customers', $this->adminToken('owner@drchoco.cm'))['items'];
        self::assertSame(['Paul Essomba', 'Client sans nom'], array_column($items, 'fullName'));
        self::assertSame(['', ''], [$items[1]['firstName'], $items[1]['lastName']]);
        self::assertGreaterThan($items[1]['lastActivityAt'], $items[0]['lastActivityAt']);
    }
}
