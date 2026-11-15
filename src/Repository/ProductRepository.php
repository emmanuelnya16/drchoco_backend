<?php

namespace App\Repository;

use App\Catalog\ProductFilters;
use App\Entity\Product;
use App\Entity\ProductVariant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Requêtes du catalogue public : seuls les articles visibles (en vente, dans une catégorie active).
 *
 * La recherche ne tient compte ni des accents ni de la casse : la base est en utf8mb4_unicode_ci,
 * donc « barbele » trouve « Barbelé » et « yaounde » trouve « Yaoundé ».
 *
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Page de résultats de GET /products.
     *
     * @param list<string>|null $lookSlugs articles du look demandé (null : pas de filtre de look)
     *
     * @return array{items: list<Product>, total: int}
     */
    public function search(ProductFilters $f, ?array $lookSlugs = null): array
    {
        $qb = $this->visible();
        $this->applyScope($qb, $f);
        $this->applyFilters($qb, $f, $lookSlugs);

        $total = (int) (clone $qb)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
        if ($total === 0 || ($f->page - 1) * $f->pageSize >= $total) {
            return ['items' => [], 'total' => $total];
        }

        $qb->select('p.id');

        // Recherche triée par défaut : d'abord les articles dont le NOM contient les mots cherchés
        // (« hoodie noir » → le Hoodie Barbelé Noir avant un jogger qui cite un hoodie noir dans sa description).
        if ($f->words !== [] && $f->sort === ProductFilters::SORT_POPULAR) {
            $score = implode(' + ', array_map(fn (int $i) => "(CASE WHEN p.name LIKE :w$i THEN 1 ELSE 0 END)", array_keys($f->words)));
            $qb->addSelect("($score) AS HIDDEN relevance")->orderBy('relevance', 'DESC');
        }

        // Tri stable (départage par identifiant) : une page ne répète ni ne saute aucun article.
        match ($f->sort) {
            ProductFilters::SORT_NEWEST => $qb->addOrderBy('p.createdAt', 'DESC'),
            ProductFilters::SORT_PRICE_ASC => $qb->addOrderBy('p.price', 'ASC'),
            ProductFilters::SORT_PRICE_DESC => $qb->addOrderBy('p.price', 'DESC'),
            default => $qb->addOrderBy('p.reservationsCount', 'DESC')->addOrderBy('p.createdAt', 'DESC'),
        };
        $qb->addOrderBy('p.id', 'ASC');

        $ids = $qb
            ->setFirstResult(($f->page - 1) * $f->pageSize)
            ->setMaxResults($f->pageSize)
            ->getQuery()
            ->getSingleColumnResult();

        return ['items' => $this->findWithDetails($ids), 'total' => $total];
    }

    /**
     * Compteurs des filtres (facettes), calculés sur la ville et la catégorie demandées,
     * avant les autres filtres (comme les mocks du front).
     *
     * @return array{sizes: array<string, int>, prices: array<string, int>, subCategories: array<string, int>}
     */
    public function facetCounts(ProductFilters $f): array
    {
        // Tailles : nombre d'articles ayant du stock dans cette taille.
        $qb = $this->visible();
        $this->applyScope($qb, $f);
        $sizes = [];
        $rows = $qb->select('v.size AS size, COUNT(DISTINCT p.id) AS n')
            ->innerJoin('p.variants', 'v')
            ->andWhere('v.stock > 0')
            ->groupBy('v.size')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $sizes[mb_strtoupper((string) $row['size'])] = (int) $row['n'];
        }

        // Tranches de prix.
        $qb = $this->visible();
        $this->applyScope($qb, $f);
        $row = $qb->select(
            'SUM(CASE WHEN p.price < 15000 THEN 1 ELSE 0 END) AS r1',
            'SUM(CASE WHEN p.price >= 15000 AND p.price <= 30000 THEN 1 ELSE 0 END) AS r2',
            'SUM(CASE WHEN p.price > 30000 AND p.price <= 50000 THEN 1 ELSE 0 END) AS r3',
            'SUM(CASE WHEN p.price > 50000 THEN 1 ELSE 0 END) AS r4',
        )->getQuery()->getSingleResult();
        $prices = [
            '0-15000'     => (int) ($row['r1'] ?? 0),
            '15000-30000' => (int) ($row['r2'] ?? 0),
            '30000-50000' => (int) ($row['r3'] ?? 0),
            '50000+'      => (int) ($row['r4'] ?? 0),
        ];

        // Sous-catégories.
        $qb = $this->visible();
        $this->applyScope($qb, $f);
        $subCategories = [];
        $rows = $qb->select('s.id AS sid, COUNT(p.id) AS n')
            ->andWhere('s.id IS NOT NULL')
            ->groupBy('s.id')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $subCategories[(string) $row['sid']] = (int) $row['n'];
        }

        return ['sizes' => $sizes, 'prices' => $prices, 'subCategories' => $subCategories];
    }

    /**
     * Nombre d'articles visibles par catégorie et par sous-catégorie (GET /categories).
     *
     * @return array{categories: array<string, int>, subCategories: array<string, int>}
     */
    public function countVisibleByCategory(): array
    {
        $categories = [];
        foreach ($this->visible()->select('c.id AS cid, COUNT(p.id) AS n')->groupBy('c.id')->getQuery()->getArrayResult() as $row) {
            $categories[(string) $row['cid']] = (int) $row['n'];
        }

        $subCategories = [];
        $rows = $this->visible()->select('s.id AS sid, COUNT(p.id) AS n')
            ->andWhere('s.id IS NOT NULL')
            ->groupBy('s.id')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $subCategories[(string) $row['sid']] = (int) $row['n'];
        }

        return ['categories' => $categories, 'subCategories' => $subCategories];
    }

    public function findVisibleBySlug(string $slug): ?Product
    {
        $id = $this->visible()
            ->select('p.id')
            ->andWhere('p.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        return $id !== null ? ($this->findWithDetails([$id['id']])[0] ?? null) : null;
    }

    /**
     * Articles visibles parmi ces slugs, dans l'ordre des slugs (articles retirés ignorés).
     *
     * @param list<string> $slugs
     *
     * @return list<Product>
     */
    public function findVisibleBySlugs(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }
        $ids = $this->visible()->select('p.id, p.slug')
            ->andWhere('p.slug IN (:slugs)')
            ->setParameter('slugs', $slugs)
            ->getQuery()
            ->getArrayResult();
        $idBySlug = array_column($ids, 'id', 'slug');

        $products = [];
        foreach ($this->findWithDetails(array_values($idBySlug)) as $product) {
            $products[$product->getSlug()] = $product;
        }

        $ordered = [];
        foreach ($slugs as $slug) {
            if (isset($products[$slug])) {
                $ordered[] = $products[$slug];
            }
        }

        return $ordered;
    }

    /**
     * Slugs visibles parmi ceux donnés (sans charger les articles).
     *
     * @param list<string> $slugs
     *
     * @return array<string, true>
     */
    public function visibleSlugSet(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }
        $rows = $this->visible()->select('p.slug')
            ->andWhere('p.slug IN (:slugs)')
            ->setParameter('slugs', array_values(array_unique($slugs)))
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map('strval', $rows), true);
    }

    /**
     * Articles associés (contrat §2.6) : même sous-catégorie d'abord, puis même catégorie ;
     * à pertinence égale, ceux de la même ville puis les plus réservés.
     *
     * @return list<Product>
     */
    public function findRelated(Product $product, int $limit = 6): array
    {
        $ids = [];
        $sub = $product->getSubCategory();

        if ($sub !== null) {
            $ids = $this->relatedIds($product, $limit, fn (QueryBuilder $qb) => $qb
                ->andWhere('s.id = :sub')->setParameter('sub', $sub->getId()));
        }

        if (\count($ids) < $limit) {
            $more = $this->relatedIds($product, $limit - \count($ids), function (QueryBuilder $qb) use ($product, $sub) {
                $qb->andWhere('c.id = :category')->setParameter('category', $product->getCategory()->getId());
                if ($sub !== null) {
                    $qb->andWhere('s.id IS NULL OR s.id <> :sub')->setParameter('sub', $sub->getId());
                }
            });
            $ids = array_merge($ids, $more);
        }

        return $this->findWithDetails($ids);
    }

    /**
     * Charge les articles avec leurs variantes, catégorie et sous-catégorie, dans l'ordre des identifiants.
     *
     * @param list<string> $ids
     *
     * @return list<Product>
     */
    public function findWithDetails(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('p')
            ->addSelect('c', 's', 'v')
            ->innerJoin('p.category', 'c')
            ->leftJoin('p.subCategory', 's')
            ->leftJoin('p.variants', 'v')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('v.position', 'ASC')
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($rows as $product) {
            $byId[$product->getId()] = $product;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    // ── Construction des requêtes ──────────────────────────────────────────

    /** Articles visibles du client : en vente, dans une catégorie active. */
    private function visible(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.category', 'c')
            ->leftJoin('p.subCategory', 's')
            ->andWhere('p.isActive = true')
            ->andWhere('c.isActive = true');
    }

    /** Périmètre des facettes : ville et catégorie (racine, ou sous-catégorie comme le permettent les mocks). */
    private function applyScope(QueryBuilder $qb, ProductFilters $f): void
    {
        if ($f->unknownCity) {
            $qb->andWhere('p.id IS NULL'); // ville sans boutique : aucun article
        } elseif ($f->city !== null) {
            $qb->andWhere('p.city = :city')->setParameter('city', $f->city);
        }

        if ($f->category !== null) {
            $qb->andWhere('c.slug = :category OR s.slug = :category')->setParameter('category', $f->category);
        }
    }

    /** @param list<string>|null $lookSlugs */
    private function applyFilters(QueryBuilder $qb, ProductFilters $f, ?array $lookSlugs): void
    {
        if ($f->sub !== null) {
            $qb->andWhere('s.slug = :sub')->setParameter('sub', $f->sub);
        }

        if ($lookSlugs !== null) {
            if ($lookSlugs === []) {
                $qb->andWhere('p.id IS NULL');
            } else {
                $qb->andWhere('p.slug IN (:lookSlugs)')->setParameter('lookSlugs', $lookSlugs);
            }
        }

        // Chaque mot doit se trouver dans le nom, la description, la catégorie, la sous-catégorie ou une taille.
        foreach ($f->words as $i => $word) {
            $like = 'w' . $i;
            $exact = 'we' . $i;
            $qb->andWhere(
                "p.name LIKE :$like OR p.description LIKE :$like"
                . " OR c.slug LIKE :$like OR c.name LIKE :$like OR s.slug LIKE :$like OR s.name LIKE :$like"
                . ' OR EXISTS (SELECT vw' . $i . '.id FROM ' . ProductVariant::class . ' vw' . $i
                . ' WHERE vw' . $i . '.product = p AND vw' . $i . ".size = :$exact)",
            )
                ->setParameter($like, '%' . addcslashes($word, '%_\\') . '%')
                ->setParameter($exact, $word);
        }

        // Tailles : au moins une variante en stock dans l'une des tailles demandées.
        if ($f->sizes !== []) {
            $qb->andWhere('EXISTS (SELECT vs.id FROM ' . ProductVariant::class . ' vs WHERE vs.product = p AND vs.stock > 0 AND vs.size IN (:sizes))')
                ->setParameter('sizes', $f->sizes);
        }

        match ($f->price) {
            '0-15000' => $qb->andWhere('p.price < 15000'),
            '15000-30000' => $qb->andWhere('p.price >= 15000 AND p.price <= 30000'),
            '30000-50000' => $qb->andWhere('p.price > 30000 AND p.price <= 50000'),
            '50000+' => $qb->andWhere('p.price > 50000'),
            default => null,
        };

        if ($f->minPrice !== null) {
            $qb->andWhere('p.price >= :minPrice')->setParameter('minPrice', $f->minPrice);
        }
        if ($f->maxPrice !== null) {
            $qb->andWhere('p.price <= :maxPrice')->setParameter('maxPrice', $f->maxPrice);
        }

        if ($f->available) {
            $qb->andWhere('EXISTS (SELECT va.id FROM ' . ProductVariant::class . ' va WHERE va.product = p AND va.stock > 0)');
        }
    }

    /**
     * @param callable(QueryBuilder): mixed $restrict
     *
     * @return list<string>
     */
    private function relatedIds(Product $product, int $limit, callable $restrict): array
    {
        $qb = $this->visible()
            ->select('p.id')
            ->addSelect('CASE WHEN p.city = :city THEN 0 ELSE 1 END AS HIDDEN cityRank')
            ->andWhere('p.id <> :self')
            ->setParameter('self', $product->getId())
            ->setParameter('city', $product->getCity());
        $restrict($qb);

        return array_map('strval', $qb
            ->orderBy('cityRank', 'ASC')
            ->addOrderBy('p.reservationsCount', 'DESC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult());
    }
}
