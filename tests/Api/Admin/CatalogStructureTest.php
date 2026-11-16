<?php

namespace App\Tests\Api\Admin;

use App\Entity\Admin;
use App\Tests\AdminTestCase;
use App\Tests\Fake\FakeCloudinaryService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Catégories, looks et envoi d'images de l'administration :
 * GET/POST /api/admin/categories, PATCH /api/admin/categories/{id}, GET/POST /api/admin/looks,
 * PATCH/DELETE /api/admin/looks/{id}, POST /api/admin/media.
 */
final class CatalogStructureTest extends AdminTestCase
{
    // Image PNG de 1 × 1 pixel.
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private function owner(): string
    {
        $this->account();

        return $this->adminToken('owner@drchoco.cm');
    }

    public function testCategoriesListWithCounts(): void
    {
        $data = $this->api('GET', '/api/admin/categories', $this->owner());

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['categories'], array_keys($data));
        self::assertSame(['vetements', 'chaussures', 'babouches'], array_column($data['categories'], 'slug'));
        self::assertSame(24, array_sum(array_column($data['categories'], 'productCount')));
        self::assertSame(['id', 'slug', 'name', 'coverImage', 'fallbackImage', 'productCount'], array_keys($data['categories'][0]['subCategories'][0]));
    }

    public function testCreateACategoryWithSubCategories(): void
    {
        $token = $this->owner();
        $data = $this->api('POST', '/api/admin/categories', $token, [
            'name'          => 'Accessoires Été',
            'slug'          => 'accessoires-ete',
            'description'   => 'Casquettes et sacs.',
            'subCategories' => [
                ['id' => 'sub-casquettes', 'slug' => 'casquettes', 'name' => 'Casquettes', 'coverImage' => '/images/categories/vetements.webp', 'fallbackImage' => '/images/fallback/mood-vetements.svg', 'productCount' => 0],
                ['id' => 'sub-sacs', 'slug' => 'sacs', 'name' => 'Sacs', 'coverImage' => '/images/categories/vetements.webp', 'fallbackImage' => '/images/fallback/mood-vetements.svg', 'productCount' => 9],
                ['id' => 'sub-sacs', 'slug' => 'sacs', 'name' => 'Sacs en double', 'coverImage' => '', 'fallbackImage' => '', 'productCount' => 0],
            ],
        ]);

        self::assertSame(201, $this->httpStatus());
        self::assertSame(['category', 'categories'], array_keys($data));
        self::assertSame(['cat-accessoires-ete', 'accessoires-ete', 'Accessoires Été', 0], [
            $data['category']['id'], $data['category']['slug'], $data['category']['name'], $data['category']['productCount'],
        ]);
        self::assertSame(['casquettes', 'sacs'], array_column($data['category']['subCategories'], 'slug'));
        self::assertSame([0, 0], array_column($data['category']['subCategories'], 'productCount'), 'nombre calculé, jamais repris du front');
        self::assertCount(4, $data['categories']);

        // Le site public la montre aussi ; un produit peut y être rangé.
        self::assertContains('accessoires-ete', array_column($this->getJson('/api/categories'), 'slug'));

        $data = $this->api('POST', '/api/admin/categories', $token, ['name' => 'Accessoires ete']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('DUPLICATE_CATEGORY', $data['code']);
        $data = $this->api('POST', '/api/admin/categories', $token, ['name' => ' ']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('Veuillez indiquer le nom de la catégorie.', $data['message']);
    }

    public function testUpdateACategoryAndItsSubCategories(): void
    {
        $token = $this->owner();
        $category = $this->api('GET', '/api/admin/categories', $token)['categories'][0]; // Vêtements
        $subs = $category['subCategories'];
        $hoodies = array_values(array_filter($subs, fn (array $s) => $s['slug'] === 'hoodies'))[0];

        // Comme le front : liste complète ; « hoodies » retirée, « vestes » ajoutée (identifiant inventé).
        $next = array_values(array_filter($subs, fn (array $s) => $s['slug'] !== 'hoodies'));
        $next[] = ['id' => 'sub-vestes', 'slug' => 'vestes', 'name' => 'Vestes', 'coverImage' => '/images/categories/vetements.webp', 'fallbackImage' => '/images/categories/vetements.webp', 'productCount' => 0];
        $data = $this->api('PATCH', '/api/admin/categories/' . $category['id'], $token, [
            'name' => 'Vêtements', 'description' => 'Nouvelle description.', 'coverImage' => 'https://res.cloudinary.com/compte/image/upload/v1/drchoco/categories/vetements.webp', 'subCategories' => $next,
        ]);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('Nouvelle description.', $data['category']['description']);
        self::assertStringStartsWith('https://res.cloudinary.com/', $data['category']['coverImage']);
        self::assertSame(array_merge(array_column($next, 'slug')), array_column($data['category']['subCategories'], 'slug'));

        // Les articles de la sous-catégorie retirée restent dans la catégorie.
        $product = $this->getJson('/api/products/hoodie-barbele-noir');
        self::assertSame('vetements', $product['categorySlug']);
        self::assertArrayNotHasKey('subCategorySlug', $product);
        self::assertGreaterThan(0, $hoodies['productCount']);

        // Slug déjà utilisé par une autre catégorie : refusé.
        $babouches = $this->api('GET', '/api/admin/categories', $token)['categories'][2];
        $data = $this->api('PATCH', '/api/admin/categories/' . $babouches['id'], $token, [
            'subCategories' => [...$babouches['subCategories'], ['id' => 'sub-x', 'slug' => 'vestes', 'name' => 'Vestes']],
        ]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('DUPLICATE_SUBCATEGORY', $data['code']);

        $this->api('PATCH', '/api/admin/categories/inconnue', $token, ['name' => 'X']);
        self::assertSame(404, $this->httpStatus());
    }

    public function testCategoryAndSubCategorySlugsNeverCollide(): void
    {
        // Le filtre du site (?category=) accepte l'une ou l'autre : un même slug serait ambigu.
        $token = $this->owner();
        $data = $this->api('POST', '/api/admin/categories', $token, ['name' => 'Hoodies']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame(['DUPLICATE_CATEGORY', '« hoodies » est déjà le nom d’une sous-catégorie.'], [$data['code'], $data['message']]);

        $vetements = $this->api('GET', '/api/admin/categories', $token)['categories'][0];
        $data = $this->api('PATCH', '/api/admin/categories/' . $vetements['id'], $token, [
            'subCategories' => [...$vetements['subCategories'], ['id' => 'sub-x', 'slug' => 'babouches', 'name' => 'Babouches']],
        ]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame(['DUPLICATE_SUBCATEGORY', 'Une catégorie s’appelle déjà « babouches ».'], [$data['code'], $data['message']]);
    }

    public function testLooksLifecycle(): void
    {
        $token = $this->owner();
        $before = \count($this->api('GET', '/api/admin/looks', $token)['looks']);

        $data = $this->api('POST', '/api/admin/looks', $token, [
            'title' => 'Total gris', 'subtitle' => '', 'image' => 'https://res.cloudinary.com/compte/image/upload/v1/drchoco/looks/l.webp',
            'productSlugs' => ['hoodie-barbele-noir', 'article-inconnu', 'hoodie-barbele-noir', 'cargo-tactique-bitume'],
        ]);
        self::assertSame(201, $this->httpStatus());
        self::assertSame(['look', 'looks'], array_keys($data));
        $look = $data['look'];
        self::assertSame(['hoodie-barbele-noir', 'cargo-tactique-bitume'], $look['productSlugs'], 'inconnus et doublons retirés');
        self::assertSame(['Silhouette signature DR Choco', '/images/fallback/mood-portrait.svg'], [$look['subtitle'], $look['fallback']]);
        self::assertCount($before + 1, $data['looks']);
        self::assertContains('Total gris', array_column($this->getJson('/api/looks'), 'title'));

        $data = $this->api('PATCH', '/api/admin/looks/' . $look['id'], $token, ['title' => 'Total gris béton', 'subtitle' => 'Hiver', 'image' => '', 'productSlugs' => ['cargo-tactique-bitume']]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['Total gris béton', 'Hiver', ['cargo-tactique-bitume']], [$data['look']['title'], $data['look']['subtitle'], $data['look']['productSlugs']]);
        self::assertStringStartsWith('https://res.cloudinary.com/', $data['look']['image'], 'image vide : l’image actuelle reste');

        foreach ([
            [['title' => ''], 'Le titre du look est obligatoire.'],
            [['productSlugs' => ['article-inconnu']], 'Sélectionnez au moins un produit du catalogue pour ce look.'],
            [['image' => 'blob:http://localhost:3000/abc'], 'La photo du look n’a pas été envoyée au serveur : importez-la de nouveau.'],
        ] as [$body, $message]) {
            $data = $this->api('PATCH', '/api/admin/looks/' . $look['id'], $token, $body);
            self::assertSame(422, $this->httpStatus(), $message);
            self::assertSame($message, $data['message']);
        }

        $this->api('DELETE', '/api/admin/looks/' . $look['id'], $token);
        self::assertSame(204, $this->httpStatus());
        self::assertCount($before, $this->api('GET', '/api/admin/looks', $token)['looks']);
        $data = $this->api('DELETE', '/api/admin/looks/' . $look['id'], $token);
        self::assertSame(404, $this->httpStatus());
        self::assertSame('LOOK_NOT_FOUND', $data['code']);
    }

    public function testSellersMayManageCategoriesAndLooksLikeTheFrontAllows(): void
    {
        $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Douala');
        $token = $this->adminToken('vendeur@drchoco.cm');

        $this->api('POST', '/api/admin/categories', $token, ['name' => 'Bijoux']);
        self::assertSame(201, $this->httpStatus());
        $this->api('POST', '/api/admin/looks', $token, ['title' => 'Look Douala', 'subtitle' => '', 'image' => '/images/looks/look-1.webp', 'productSlugs' => ['hoodie-zip-armure']]);
        self::assertSame(201, $this->httpStatus());
    }

    public function testImageUpload(): void
    {
        $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Douala');
        $token = $this->adminToken('vendeur@drchoco.cm');

        $data = $this->upload($token, $this->png(), 'look');
        self::assertSame(201, $this->httpStatus());
        self::assertSame(['url', 'publicId', 'width', 'height'], array_keys($data));
        self::assertStringStartsWith('https://res.cloudinary.com/', $data['url']);
        self::assertStringContainsString('drchoco/looks/', $data['publicId']);

        // Fichier qui n'est pas une image (type réel du contenu, pas l'extension).
        $text = tempnam(sys_get_temp_dir(), 'drc');
        file_put_contents($text, 'pas une image');
        $data = $this->upload($token, new UploadedFile($text, 'photo.jpg', 'image/jpeg', null, true));
        self::assertSame(422, $this->httpStatus());
        self::assertSame('INVALID_IMAGE', $data['code']);

        $data = $this->upload($token, null);
        self::assertSame(422, $this->httpStatus());
        $this->upload($token, $this->png(), 'video');
        self::assertSame(422, $this->httpStatus());

        // Panne de Cloudinary : 502 lisible.
        static::getContainer()->get(\App\Service\Media\CloudinaryService::class)->fail = true;
        $data = $this->upload($token, $this->png());
        self::assertSame(502, $this->httpStatus());
        self::assertSame('MEDIA_UPLOAD_FAILED', $data['code']);

        // Erreurs d'envoi signalées par PHP.
        static::getContainer()->get(\App\Service\Media\CloudinaryService::class)->fail = false;
        $data = $this->upload($token, $this->png(\UPLOAD_ERR_PARTIAL));
        self::assertSame([422, 'UPLOAD_INCOMPLETE'], [$this->httpStatus(), $data['code']]);
        $data = $this->upload($token, $this->png(\UPLOAD_ERR_NO_TMP_DIR));
        self::assertSame([503, 'MEDIA_UPLOAD_FAILED'], [$this->httpStatus(), $data['code']]);

        // Corps plus lourd que post_max_size : PHP n'a gardé ni le fichier ni le formulaire.
        $this->client->request('POST', '/api/admin/media', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT'        => 'application/json',
            'CONTENT_TYPE'       => 'multipart/form-data; boundary=x',
            'CONTENT_LENGTH'     => '9000000',
        ]);
        self::assertSame(413, $this->httpStatus());
        self::assertSame('FILE_TOO_LARGE', json_decode((string) $this->client->getResponse()->getContent(), true)['code']);

        // Sans connexion admin : refusé.
        $this->client->request('POST', '/api/admin/media', ['kind' => 'product'], ['file' => $this->png()]);
        self::assertSame(401, $this->httpStatus());
    }

    private function png(int $error = \UPLOAD_ERR_OK): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'drc');
        file_put_contents($path, base64_decode(self::PNG));

        return new UploadedFile($path, 'photo.png', 'image/png', $error, true);
    }

    /** @return array<string, mixed> */
    private function upload(string $token, ?UploadedFile $file, string $kind = 'product'): array
    {
        $this->client->request('POST', '/api/admin/media', ['kind' => $kind], $file !== null ? ['file' => $file] : [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT'        => 'application/json',
        ]);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
