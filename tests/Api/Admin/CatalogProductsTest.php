<?php

namespace App\Tests\Api\Admin;

use App\Entity\Admin;
use App\Entity\Product;
use App\Tests\AdminTestCase;

/**
 * Catalogue de l'administration : GET/POST /api/admin/products, GET/PATCH/DELETE /api/admin/products/{id},
 * PATCH /api/admin/products/{id}/stock. Corps des requêtes construits comme le fait AdminProductEditPage.
 */
final class CatalogProductsTest extends AdminTestCase
{
    private const PHOTO = 'https://res.cloudinary.com/compte-de-test/image/upload/v1/drchoco/products/test-1.webp';

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        $slug = $override['slug'] ?? 'hoodie-atelier-test';

        return $override + [
            'name'             => 'Hoodie Atelier Test',
            'slug'             => $slug,
            'categorySlug'     => 'vetements',
            'subCategorySlug'  => 'hoodies',
            'city'             => 'Douala',
            'price'            => 42000,
            'isNew'            => true,
            'description'      => 'Un hoodie de test, coupe droite.',
            'composition'      => 'Coton 100 %',
            'careInstructions' => ['Lavage à 30 °C sur l’envers.', 'Séchage à l’air libre.'],
            'colors'           => [
                ['code' => 'noir', 'name' => 'Noir Charbon', 'hex' => '#1A1A18'],
                ['code' => 'blanc', 'name' => 'Blanc Craie', 'hex' => '#f4f4f3'],
            ],
            'sizes'            => ['S', 'M'],
            // Identifiants inventés par le front : jamais repris tels quels.
            'variants'         => [
                ['id' => "var-$slug-noir-s", 'size' => 'S', 'colorCode' => 'noir', 'stock' => 3],
                ['id' => "var-$slug-noir-m", 'size' => 'M', 'colorCode' => 'noir', 'stock' => 0],
                ['id' => "var-$slug-blanc-s", 'size' => 'S', 'colorCode' => 'blanc', 'stock' => 2],
                ['id' => "var-$slug-blanc-m", 'size' => 'M', 'colorCode' => 'blanc', 'stock' => 5],
            ],
            'mediaGallery'     => [
                ['url' => self::PHOTO, 'fallbackUrl' => self::PHOTO, 'colorCode' => 'noir', 'alt' => 'Hoodie noir'],
                ['url' => '/images/products/hoodie-barbele-noir-1.webp', 'fallbackUrl' => '/images/products/hoodie-barbele-noir-1.webp'],
            ],
            'sizeGuide'        => ['type' => 'clothing', 'title' => 'Guide des tailles', 'tip' => 'Taille normalement.', 'headers' => ['Taille', 'Tour de poitrine (cm)'], 'rows' => [['S', '100'], ['M', '104']]],
        ];
    }

    private function owner(): string
    {
        $this->account();

        return $this->adminToken('owner@drchoco.cm');
    }

    public function testListLikeTheFrontExpects(): void
    {
        $data = $this->api('GET', '/api/admin/products', $this->owner());

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['items', 'page', 'pageSize', 'total', 'totalPages', 'summary'], array_keys($data));
        self::assertSame([1, 24, 24, 1], [$data['page'], $data['pageSize'], $data['total'], $data['totalPages']]);
        self::assertSame(24, $data['summary']['totalProducts']);
        self::assertSame(24, $data['summary']['inStockProducts'] + $data['summary']['lowStockProducts'] + $data['summary']['outOfStockProducts']);

        // Le plus récent d'abord ; même forme que la fiche publique.
        $dates = array_column($data['items'], 'createdAt');
        $sorted = $dates;
        rsort($sorted);
        self::assertSame($sorted, $dates);
        self::assertArrayHasKey('variants', $data['items'][0]);
        self::assertArrayHasKey('city', $data['items'][0]);
    }

    public function testFiltersSortsAndPages(): void
    {
        $token = $this->owner();

        $douala = $this->api('GET', '/api/admin/products?pageSize=50&city=' . rawurlencode('douala'), $token);
        self::assertSame(12, $douala['total']);
        self::assertSame(24, $douala['summary']['totalProducts'], 'le résumé ignore les filtres');

        $babouches = $this->api('GET', '/api/admin/products?category=babouches&pageSize=50', $token);
        self::assertSame(['babouches'], array_values(array_unique(array_column($babouches['items'], 'categorySlug'))));

        $out = $this->api('GET', '/api/admin/products?stockStatus=out_of_stock&pageSize=50', $token);
        foreach ($out['items'] as $item) {
            self::assertContains(0, array_column($item['variants'], 'stock'), $item['slug']);
        }

        $prices = array_column($this->api('GET', '/api/admin/products?sort=price_desc&pageSize=50', $token)['items'], 'price');
        $sorted = $prices;
        rsort($sorted);
        self::assertSame($sorted, $prices);

        self::assertSame(['hoodie-barbele-noir'], array_column($this->api('GET', '/api/admin/products?q=barbele', $token)['items'], 'slug'));

        // Page au-delà de la fin : la dernière page ; taille limitée à 50.
        $page = $this->api('GET', '/api/admin/products?page=99&pageSize=10', $token);
        self::assertSame([3, 3], [$page['page'], $page['totalPages']]);
        self::assertCount(4, $page['items']);
        self::assertSame(50, $this->api('GET', '/api/admin/products?pageSize=500', $token)['pageSize']);
    }

    public function testSellerSeesOwnProductsAndUnassignedOnesOfTheirCity(): void
    {
        $a = $this->account(Admin::ROLE_SELLER, 'a@drchoco.cm', 'Douala');
        $b = $this->account(Admin::ROLE_SELLER, 'b@drchoco.cm', 'Douala');
        $this->mutateProduct('hoodie-zip-armure', fn (Product $p) => $p->setSeller($this->em()->find(Admin::class, $b->getId()))); // Douala
        $token = $this->adminToken('a@drchoco.cm');

        // La ville demandée est ignorée : le périmètre vient du compte.
        $data = $this->api('GET', '/api/admin/products?pageSize=50&city=' . rawurlencode('Yaoundé'), $token);
        self::assertSame(11, $data['total']);
        self::assertSame(11, $data['summary']['totalProducts']);
        self::assertSame(['Douala'], array_values(array_unique(array_column($data['items'], 'city'))));
        self::assertNotContains('hoodie-zip-armure', array_column($data['items'], 'slug'), 'article d’un autre vendeur');

        $this->api('GET', '/api/admin/products/hoodie-zip-armure', $token);
        self::assertSame(404, $this->httpStatus());
        $this->api('GET', '/api/admin/products/hoodie-barbele-noir', $token); // Yaoundé
        self::assertSame(404, $this->httpStatus());
        $this->api('PATCH', '/api/admin/products/hoodie-zip-armure/stock', $token, ['variants' => []]);
        self::assertSame(404, $this->httpStatus());
        $this->api('DELETE', '/api/admin/products/hoodie-zip-armure', $token);
        self::assertSame(404, $this->httpStatus());
        self::assertSame(200, $this->getJson('/api/products/hoodie-zip-armure') ? $this->httpStatus() : 0);
    }

    public function testDetailByIdOrSlugWithSeller(): void
    {
        $seller = $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé');
        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setSeller($this->em()->find(Admin::class, $seller->getId())));
        $token = $this->owner();

        $byId = $this->api('GET', '/api/admin/products/prod-01', $token);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['product'], array_keys($byId));
        self::assertSame([(string) $seller->getId(), 'Seller Test'], [$byId['product']['sellerId'], $byId['product']['sellerName']]);
        self::assertSame($byId, $this->api('GET', '/api/admin/products/hoodie-barbele-noir', $token));

        $data = $this->api('GET', '/api/admin/products/inconnu', $token);
        self::assertSame(404, $this->httpStatus());
        self::assertSame('PRODUCT_NOT_FOUND', $data['code']);
    }

    public function testSuperAdminCreatesAProductVisibleOnTheShop(): void
    {
        $data = $this->api('POST', '/api/admin/products', $this->owner(), $this->payload());

        self::assertSame(201, $this->httpStatus());
        $product = $data['product'];
        self::assertMatchesRegularExpression('/^prod-[0-9a-f]{10}$/', $product['id']);
        self::assertSame(['hoodie-atelier-test', 'Douala', 42000, 'hoodies'], [$product['slug'], $product['city'], $product['price'], $product['subCategorySlug']]);
        self::assertArrayNotHasKey('sellerId', $product, 'créé par le super admin sans vendeur choisi');
        self::assertSame([self::PHOTO, '/images/products/hoodie-barbele-noir-1.webp'], $product['images']);
        self::assertSame('#F4F4F3', $product['colors'][1]['hex']);

        // Variantes : couleur × taille dans l'ordre, identifiants stables du serveur, stocks reçus.
        self::assertSame(
            [[$product['id'] . '-noir-s', 3], [$product['id'] . '-noir-m', 0], [$product['id'] . '-blanc-s', 2], [$product['id'] . '-blanc-m', 5]],
            array_map(fn (array $v) => [$v['id'], $v['stock']], $product['variants']),
        );
        self::assertSame(['new'], $product['badges']);

        // En vente sur le site, dans la bonne ville.
        self::assertSame('Douala', $this->getJson('/api/products/hoodie-atelier-test')['city']);
        self::assertContains('hoodie-atelier-test', array_column($this->getJson('/api/products?city=Douala&pageSize=50')['items'], 'slug'));
    }

    public function testCreateValidationMessagesAreShownAsIs(): void
    {
        $token = $this->owner();
        $cases = [
            [['price' => 0], 'Le prix en FCFA doit être supérieur à 0.'],
            [['colors' => []], 'Ajoutez au moins une couleur.'],
            [['sizes' => []], 'Ajoutez au moins une taille.'],
            [['mediaGallery' => []], 'Ajoutez au moins une photo à la galerie.'],
            [['categorySlug' => 'inconnue'], 'Choisissez une catégorie de la liste.'],
            [['name' => ' '], 'Veuillez saisir le nom du produit.'],
            [['mediaGallery' => [['url' => 'blob:http://localhost:3000/1f2e', 'fallbackUrl' => 'blob:http://localhost:3000/1f2e']]], 'La photo n°1 n’a pas été envoyée au serveur : importez-la de nouveau.'],
            [['colors' => [['code' => 'noir', 'name' => 'Noir', 'hex' => 'noir']]], 'Teinte de la couleur « Noir » invalide (#RRGGBB).'],
            [['colors' => [['code' => str_repeat('gris-', 9), 'name' => 'Gris très très long', 'hex' => '#777777']]], 'Nom de la couleur « Gris très très long » trop long (40 caractères au plus).'],
            [['colors' => [['code' => 'noir_mat', 'name' => 'Noir mat', 'hex' => '#111111']]], 'Code couleur « noir_mat » invalide.'],
            // Photo d'exemple du formulaire du front : le fichier n'existe pas sur le site.
            [['mediaGallery' => [['url' => '/mocks/products/hoodie-manifeste-1.svg', 'fallbackUrl' => '/mocks/products/hoodie-manifeste-1.svg']]], 'La photo n°1 est une photo d’exemple : remplacez-la par une vraie photo.'],
            [['sizes' => ['Bébé', 'bebe']], 'La taille « bebe » est en double.'],
        ];
        foreach ($cases as [$override, $message]) {
            $data = $this->api('POST', '/api/admin/products', $token, $this->payload($override));
            self::assertSame(422, $this->httpStatus(), $message);
            self::assertSame($message, $data['message']);
        }

        $this->api('POST', '/api/admin/products', $token, $this->payload());
        $data = $this->api('POST', '/api/admin/products', $token, $this->payload(['name' => 'Autre nom']));
        self::assertSame(409, $this->httpStatus());
        self::assertSame('DUPLICATE_SLUG', $data['code']);
        self::assertSame('Un article utilise déjà le slug « hoodie-atelier-test ».', $data['message']);
    }

    public function testSellerCreatesInTheirCityAndOwnsTheProduct(): void
    {
        $seller = $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé');
        $other = $this->account(Admin::ROLE_SELLER, 'autre@drchoco.cm', 'Douala');
        $token = $this->adminToken('vendeur@drchoco.cm');

        // Ville et vendeur envoyés : ignorés pour un vendeur.
        $data = $this->api('POST', '/api/admin/products', $token, $this->payload(['city' => 'Douala', 'sellerId' => (string) $other->getId()]));
        self::assertSame(201, $this->httpStatus());
        self::assertSame(['Yaoundé', (string) $seller->getId(), 'Seller Test'], [$data['product']['city'], $data['product']['sellerId'], $data['product']['sellerName']]);

        $mine = array_column($this->api('GET', '/api/admin/products?pageSize=50', $token)['items'], 'slug');
        self::assertContains('hoodie-atelier-test', $mine);
    }

    public function testUpdateKeepsVariantIdsAndFollowsColorsAndSizes(): void
    {
        $token = $this->owner();
        $created = $this->api('POST', '/api/admin/products', $token, $this->payload())['product'];
        $id = $created['id'];
        $look = $this->api('POST', '/api/admin/looks', $token, ['title' => 'Look test', 'subtitle' => '', 'image' => '/images/looks/look-1.webp', 'productSlugs' => ['hoodie-atelier-test']])['look'];

        // Comme le front : objet complet, variantes aux identifiants « var-… », nouvelle adresse, une couleur
        // retirée, une taille ajoutée.
        $payload = $this->payload([
            'slug'     => 'hoodie-atelier-renomme',
            'colors'   => [['code' => 'noir', 'name' => 'Noir Charbon', 'hex' => '#1A1A18']],
            'sizes'    => ['S', 'M', 'L'],
            'variants' => [
                ['id' => 'var-hoodie-atelier-renomme-noir-s', 'size' => 'S', 'colorCode' => 'noir', 'stock' => 7],
                ['id' => 'var-hoodie-atelier-renomme-noir-m', 'size' => 'M', 'colorCode' => 'noir', 'stock' => 1],
                ['id' => 'var-hoodie-atelier-renomme-noir-l', 'size' => 'L', 'colorCode' => 'noir', 'stock' => 4],
            ],
        ]);
        $data = $this->api('PATCH', '/api/admin/products/' . $id, $token, $payload);
        self::assertSame(200, $this->httpStatus());

        self::assertSame(
            [[$id . '-noir-s', 7], [$id . '-noir-m', 1], [$id . '-noir-l', 4]],
            array_map(fn (array $v) => [$v['id'], $v['stock']], $data['product']['variants']),
            'variantes existantes : même identifiant ; couleur retirée : variantes supprimées',
        );
        self::assertSame('hoodie-atelier-renomme', $data['product']['slug']);
        $looks = array_column($this->api('GET', '/api/admin/looks', $token)['looks'], 'productSlugs', 'id');
        self::assertSame(['hoodie-atelier-renomme'], $looks[$look['id']], 'le look suit la nouvelle adresse');

        // PATCH partiel : seul le prix change.
        $data = $this->api('PATCH', '/api/admin/products/' . $id, $token, ['price' => 39000]);
        self::assertSame([39000, 7], [$data['product']['price'], $data['product']['variants'][0]['stock']]);

        // Adresse déjà prise : 409.
        $data = $this->api('PATCH', '/api/admin/products/' . $id, $token, ['slug' => 'hoodie-barbele-noir']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('DUPLICATE_SLUG', $data['code']);
    }

    public function testSubCategoryOfAnotherCategoryIsDropped(): void
    {
        // Le front garde l'ancienne sous-catégorie quand la nouvelle catégorie n'en a pas.
        $data = $this->api('POST', '/api/admin/products', $this->owner(), $this->payload(['categorySlug' => 'babouches', 'subCategorySlug' => 'hoodies']));
        self::assertSame(201, $this->httpStatus());
        self::assertSame('babouches', $data['product']['categorySlug']);
        self::assertArrayNotHasKey('subCategorySlug', $data['product']);
    }

    public function testSuperAdminAssignsASellerAndSellerTakesOverAnUnassignedProduct(): void
    {
        $seller = $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé');
        $token = $this->owner();

        $data = $this->api('PATCH', '/api/admin/products/prod-01', $token, ['sellerId' => (string) $seller->getId()]);
        self::assertSame((string) $seller->getId(), $data['product']['sellerId']);

        // Un vendeur qui modifie un article de sa ville sans vendeur en devient le vendeur.
        $sellerToken = $this->adminToken('vendeur@drchoco.cm');
        $data = $this->api('PATCH', '/api/admin/products/cargo-tactique-bitume', $sellerToken, ['price' => 31000]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame([(string) $seller->getId(), 31000], [$data['product']['sellerId'], $data['product']['price']]);
    }

    public function testProductOfASellerStaysInTheSellerCity(): void
    {
        $seller = $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Yaoundé');
        $token = $this->owner();

        // Le formulaire envoie toujours une ville (« Douala » dans payload()) : celle du vendeur l'emporte.
        $data = $this->api('POST', '/api/admin/products', $token, $this->payload(['sellerId' => (string) $seller->getId()]));
        self::assertSame(201, $this->httpStatus());
        self::assertSame(['Yaoundé', (string) $seller->getId()], [$data['product']['city'], $data['product']['sellerId']]);
        $id = $data['product']['id'];

        foreach ([['city' => 'Douala'], ['city' => 'Douala', 'sellerId' => (string) $seller->getId()]] as $body) {
            $data = $this->api('PATCH', '/api/admin/products/' . $id, $token, $body);
            self::assertSame(200, $this->httpStatus());
            self::assertSame('Yaoundé', $data['product']['city'], json_encode($body));
        }

        // Sans vendeur : la ville choisie compte.
        $data = $this->api('POST', '/api/admin/products', $token, $this->payload(['slug' => 'hoodie-sans-vendeur']));
        self::assertSame(['Douala', null], [$data['product']['city'], $data['product']['sellerId'] ?? null]);
    }

    public function testSizesAreComparedWithoutAccentsCaseOrExtraSpaces(): void
    {
        $token = $this->owner();

        // Stock envoyé avec une autre écriture de la même taille : retrouvé.
        $data = $this->api('POST', '/api/admin/products', $token, $this->payload([
            'colors'   => [['code' => 'noir', 'name' => 'Noir', 'hex' => '#000000']],
            'sizes'    => ['Taille unique'],
            'variants' => [['id' => 'var-x', 'size' => ' TAILLE  unique ', 'colorCode' => 'noir', 'stock' => 6]],
        ]));
        self::assertSame(201, $this->httpStatus());
        self::assertSame([['Taille unique', 6]], array_map(fn (array $v) => [$v['size'], $v['stock']], $data['product']['variants']));
        $id = $data['product']['id'];
        $variantId = $data['product']['variants'][0]['id'];

        // Même taille réécrite : même variante (identifiant et stock gardés), libellé mis à jour.
        $data = $this->api('PATCH', '/api/admin/products/' . $id, $token, ['sizes' => ['TAILLE UNIQUE']]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame([[$variantId, 'TAILLE UNIQUE', 6]], array_map(fn (array $v) => [$v['id'], $v['size'], $v['stock']], $data['product']['variants']));

        // Deux tailles distinctes qui donnent la même adresse (« x-l ») : deux identifiants distincts.
        $data = $this->api('PATCH', '/api/admin/products/' . $id, $token, ['sizes' => ['X-L', 'X L']]);
        self::assertSame(200, $this->httpStatus());
        $ids = array_column($data['product']['variants'], 'id');
        self::assertCount(2, array_unique($ids));
    }

    public function testQuickStock(): void
    {
        $token = $this->owner();
        $product = $this->api('GET', '/api/admin/products/prod-01', $token)['product'];
        $variants = array_map(fn (array $v) => ['variantId' => $v['id'], 'stock' => 0], $product['variants']);
        $variants[] = ['variantId' => 'variante-inconnue', 'stock' => 9];

        $data = $this->api('PATCH', '/api/admin/products/prod-01/stock', $token, ['variants' => $variants]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame([0], array_values(array_unique(array_column($data['product']['variants'], 'stock'))));
        self::assertContains('sold-out', $data['product']['badges']);
        self::assertSame('sold-out', $this->getJson('/api/products/hoodie-barbele-noir')['badges'][0]);

        $this->api('PATCH', '/api/admin/products/prod-01/stock', $token, ['variants' => [['variantId' => $product['variants'][0]['id'], 'stock' => -2]]]);
        self::assertSame(422, $this->httpStatus());
        $this->api('PATCH', '/api/admin/products/prod-01/stock', $token, ['variants' => 'non']);
        self::assertSame(422, $this->httpStatus());
    }

    public function testDeleteRetiresTheProductEverywhere(): void
    {
        $token = $this->owner();
        $this->api('DELETE', '/api/admin/products/prod-01', $token);
        self::assertSame(204, $this->httpStatus());

        $this->api('GET', '/api/admin/products/prod-01', $token);
        self::assertSame(404, $this->httpStatus());
        $this->getJson('/api/products/hoodie-barbele-noir');
        self::assertSame(404, $this->httpStatus());
        self::assertSame(23, $this->api('GET', '/api/admin/products', $token)['total']);
        foreach ($this->api('GET', '/api/admin/looks', $token)['looks'] as $look) {
            self::assertNotContains('hoodie-barbele-noir', $look['productSlugs']);
        }
        self::assertSame([], $this->api('GET', '/api/admin/search?q=barbele', $token)['products']);

        // L'adresse est libérée.
        $this->api('POST', '/api/admin/products', $token, $this->payload(['slug' => 'hoodie-barbele-noir']));
        self::assertSame(201, $this->httpStatus());
    }
}
