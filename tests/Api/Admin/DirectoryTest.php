<?php

namespace App\Tests\Api\Admin;

use App\Entity\Address;
use App\Entity\Admin;
use App\Entity\Product;
use App\Tests\AdminTestCase;

/**
 * GET /api/admin/search et GET /api/admin/customers : chacun ne voit que ce qui le concerne.
 */
final class DirectoryTest extends AdminTestCase
{
    public function testSuperAdminSearchFindsEveryProductOfTheShop(): void
    {
        $this->account();
        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setIsActive(false));

        $data = $this->api('GET', '/api/admin/search?q=' . rawurlencode('HOODIE'), $this->adminToken('owner@drchoco.cm'));
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['reservations', 'products'], array_keys($data));
        self::assertSame([], $data['reservations']);
        self::assertNotContains('hoodie-barbele-noir', array_column($data['products'], 'slug'), 'article supprimé : sa fiche n’existe plus');
        self::assertContains('hoodie-zip-armure', array_column($data['products'], 'slug'));
        self::assertLessThanOrEqual(6, \count($data['products']));
        self::assertSame(['id', 'slug', 'name', 'image', 'price', 'totalStock', 'outOfStockCount'], array_keys($data['products'][0]));

        // Sans accents, par catégorie.
        self::assertNotEmpty($this->api('GET', '/api/admin/search?q=vetements', $this->adminToken('owner@drchoco.cm'))['products']);
    }

    public function testSellerOnlySearchesTheirCity(): void
    {
        $this->account(Admin::ROLE_SELLER, 'vendeur.douala@drchoco.cm', 'Douala');
        $token = $this->adminToken('vendeur.douala@drchoco.cm');

        $slugs = [];
        foreach (['hoodie', 'cargo', 'babouche', 'tshirt'] as $q) {
            $slugs = [...$slugs, ...array_column($this->api('GET', '/api/admin/search?q=' . $q, $token)['products'], 'slug')];
        }
        self::assertNotEmpty($slugs);
        foreach (array_unique($slugs) as $slug) {
            self::assertSame('Douala', $this->getJson('/api/products/' . $slug)['city'], $slug);
        }
    }

    public function testShortSearchReturnsNothing(): void
    {
        $this->account();
        $data = $this->api('GET', '/api/admin/search?q=h', $this->adminToken('owner@drchoco.cm'));
        self::assertSame(['reservations' => [], 'products' => []], $data);
        $this->api('GET', '/api/admin/search?q=%25%25', $this->adminToken('owner@drchoco.cm'));
        self::assertSame(200, $this->httpStatus());
    }

    public function testCustomersDirectoryForSuperAdmins(): void
    {
        $this->account();
        $junior = $this->createUser('+237690000001');
        $junior->setLastName('Mbarga')->setCity('Yaoundé');
        $address = new Address($junior, 1, new \DateTimeImmutable());
        $address->setLabel('Maison')->setCity('Yaoundé')->setDistrict('Bastos')->setLandmark('Portail noir')->setIsDefault(true);
        $this->em()->persist($address);
        $paul = $this->createUser('+237677889900');
        $paul->setFirstName('Paul')->setLastName('Essomba')->setLastLoginAt(new \DateTimeImmutable('+1 minute'));
        $this->em()->flush();
        $token = $this->adminToken('owner@drchoco.cm');

        $data = $this->api('GET', '/api/admin/customers', $token);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(2, $data['total']);
        self::assertSame(['Paul Essomba', 'Junior Mbarga'], array_column($data['items'], 'fullName'), 'les plus récemment actifs d’abord');
        $item = $data['items'][1];
        self::assertSame([
            'id', 'phone', 'firstName', 'lastName', 'fullName', 'city', 'status', 'reservationsCount',
            'confirmedTotalFcfa', 'lastActivityAt', 'addresses', 'reservations',
        ], array_keys($item));
        self::assertSame('Bastos', $item['addresses'][0]['district']);
        self::assertSame([], $item['reservations']);

        self::assertSame(['Paul Essomba'], array_column($this->api('GET', '/api/admin/customers?q=essomba', $token)['items'], 'fullName'));
        self::assertSame(['Paul Essomba'], array_column($this->api('GET', '/api/admin/customers?q=' . rawurlencode('77 88 99'), $token)['items'], 'fullName'));
        self::assertSame(['Junior Mbarga'], array_column($this->api('GET', '/api/admin/customers?q=yaounde', $token)['items'], 'fullName'));
    }

    public function testSellersDoNotSeeTheWholeCustomerBase(): void
    {
        $this->createUser('+237690000001');
        $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé');

        $data = $this->api('GET', '/api/admin/customers', $this->adminToken('vendeur@drchoco.cm'));
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['items' => [], 'total' => 0], $data);
    }
}
