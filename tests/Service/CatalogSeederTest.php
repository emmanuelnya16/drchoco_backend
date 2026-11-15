<?php

namespace App\Tests\Service;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Seed\CatalogSeeder;
use App\Tests\CatalogTestCase;

/**
 * php bin/console app:seed : sans danger à relancer, n'écrase rien sans --update.
 */
final class CatalogSeederTest extends CatalogTestCase
{
    public function testSeedingTwiceCreatesNothingAndKeepsChanges(): void
    {
        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setPrice(47000));

        $report = static::getContainer()->get(CatalogSeeder::class)->seed();

        foreach ($report as $section => $count) {
            self::assertSame(0, $count['created'], $section);
            self::assertSame(0, $count['updated'], $section);
        }
        self::assertSame(24, $report['products']['skipped']);
        self::assertSame(47000, $this->em()->getRepository(Product::class)->findOneBy(['slug' => 'hoodie-barbele-noir'])->getPrice());
        self::assertSame(
            24,
            (int) $this->em()->createQuery('SELECT COUNT(p.id) FROM ' . Product::class . ' p')->getSingleScalarResult(),
        );
    }

    public function testUpdateRestoresTheFileValues(): void
    {
        $this->mutateProduct('hoodie-barbele-noir', fn (Product $p) => $p->setPrice(47000));

        $report = static::getContainer()->get(CatalogSeeder::class)->seed(['products'], true);

        self::assertSame(24, $report['products']['updated']);
        self::assertSame(45000, $this->em()->getRepository(Product::class)->findOneBy(['slug' => 'hoodie-barbele-noir'])->getPrice());
        self::assertSame(2, $this->em()->find(ProductVariant::class, 'prod-01-noir-l')->getStock());
    }
}
