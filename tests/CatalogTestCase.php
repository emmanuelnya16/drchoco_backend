<?php

namespace App\Tests;

use App\Entity\Product;
use App\Seed\CatalogSeeder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base des tests du catalogue public : la base de test est remplie une fois par classe avec les
 * données de départ (data/seed, identiques aux mocks du front), et chaque test tourne dans une
 * transaction annulée à la fin : un test qui retire un article ne gêne pas les suivants.
 */
abstract class CatalogTestCase extends ApiTestCase
{
    private const CATALOG_TABLES = [
        'product_variants', 'products', 'looks', 'sub_categories', 'categories',
        'agencies', 'delivery_cities', 'content_pages', 'app_settings',
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::bootKernel();
        self::resetCatalog(static::getContainer());
        static::ensureKernelShutdown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Même noyau (et même connexion) pour toutes les requêtes du test : la transaction les couvre.
        $this->client->disableReboot();
        $this->em()->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em()->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    public static function resetCatalog(ContainerInterface $container): void
    {
        $connection = $container->get('doctrine.dbal.default_connection');
        foreach (self::CATALOG_TABLES as $table) {
            $connection->executeStatement('DELETE FROM ' . $table);
        }
        $container->get(CatalogSeeder::class)->seed();
    }

    /** Applique une modification en base, puis vide la mémoire de Doctrine avant la requête suivante. */
    protected function mutateProduct(string $slug, callable $change): void
    {
        $product = $this->em()->getRepository(Product::class)->findOneBy(['slug' => $slug]);
        self::assertNotNull($product, "Article $slug absent des données de départ");
        $change($product);
        $this->em()->flush();
        $this->em()->clear();
    }

    /** @return array<string, mixed> */
    protected function postJson(string $uri, mixed $body, array $server = []): array
    {
        $this->client->request('POST', $uri, [], [], $server + [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ], \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    protected function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected function header(string $name): ?string
    {
        return $this->client->getResponse()->headers->get($name);
    }
}
