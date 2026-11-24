<?php

namespace App\Repository;

use App\Catalog\ProductFilters;
use App\Entity\Admin;
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

        // Forme de la requête différente à chaque combinaison de filtres : pas gardée dans le cache des requêtes (il
        // grossirait sans fin sur le disque).
        $total = (int) (clone $qb)->select('COUNT(p.id)')->getQuery()->useQueryCache(false)->getSingleScalarResult();
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
            ->useQueryCache(false)
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

    /**
     * Articles en vente par catégorie et sous-catégorie, pour l'administration (catégories masquées comprises).
     *
     * @return array{categories: array<string, int>, subCategories: array<string, int>}
     */
    public function countActiveByCategory(): array
    {
        $categories = [];
        foreach ($this->createQueryBuilder('p')->select('IDENTITY(p.category) AS cid, COUNT(p.id) AS n')
            ->where('p.isActive = true')->groupBy('cid')->getQuery()->getArrayResult() as $row) {
            $categories[(string) $row['cid']] = (int) $row['n'];
        }
        $subCategories = [];
        foreach ($this->createQueryBuilder('p')->select('IDENTITY(p.subCategory) AS sid, COUNT(p.id) AS n')
            ->where('p.isActive = true')->andWhere('p.subCategory IS NOT NULL')->groupBy('sid')->getQuery()->getArrayResult() as $row) {
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
     * Articles du plan du site : tous ceux que le client peut voir (les épuisés aussi), du plus récent au plus ancien.
     *
     * @return list<array{slug: string, lastmod: \DateTimeImmutable, gallery: list<array<string, string>>}>
     */
    public function sitemapProducts(int $limit): array
    {
        $rows = $this->visible()
            ->select('p.slug', 'p.createdAt', 'p.updatedAt', 'p.mediaGallery')
            ->addOrderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'slug'    => (string) $row['slug'],
            'lastmod' => $row['updatedAt'] ?? $row['createdAt'],
            'gallery' => \is_array($row['mediaGallery']) ? $row['mediaGallery'] : [],
        ], $rows);
    }

    /**
     * Catégories et sous-catégories du plan du site : celles qui ont au moins un article visible, avec la date du
     * dernier changement d'un de leurs articles.
     *
     * @return list<array{slug: string, lastmod: \DateTimeImmutable}>
     */
    public function sitemapCategories(): array
    {
        $entries = [];
        foreach (['c', 's'] as $alias) {
            $rows = $this->visible()
                ->select("$alias.slug AS slug", "$alias.position AS HIDDEN place", 'MAX(COALESCE(p.updatedAt, p.createdAt)) AS lastmod')
                ->andWhere("$alias.id IS NOT NULL")
                ->groupBy("$alias.id")
                ->addOrderBy('place', 'ASC')
                ->addOrderBy("$alias.id", 'ASC')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $entries[] = ['slug' => (string) $row['slug'], 'lastmod' => new \DateTimeImmutable((string) $row['lastmod'], new \DateTimeZone('UTC'))];
            }
        }

        return $entries;
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
     * Adresses (slugs) d'articles en vente parmi celles données, catégorie masquée ou non (looks de l'administration).
     *
     * @param list<string> $slugs
     *
     * @return array<string, true>
     */
    public function activeSlugSet(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('p')->select('p.slug')
            ->where('p.isActive = true')
            ->andWhere('p.slug IN (:slugs)')
            ->setParameter('slugs', array_values(array_unique($slugs)))
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map('strval', $rows), true);
    }

    /**
     * Identifiants d'articles visibles parmi ceux donnés (favoris : un article retiré est ignoré).
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function visibleIdsAmong(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_map('strval', $this->visible()->select('p.id')
            ->andWhere('p.id IN (:ids)')
            ->setParameter('ids', array_values(array_unique($ids)))
            ->getQuery()
            ->getSingleColumnResult());
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
    public function findWithDetails(array $ids, bool $withSeller = false): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c', 's', 'v')
            ->innerJoin('p.category', 'c')
            ->leftJoin('p.subCategory', 's')
            ->leftJoin('p.variants', 'v')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('v.position', 'ASC');
        if ($withSeller) {
            $qb->addSelect('a')->leftJoin('p.seller', 'a');
        }
        $rows = $qb->getQuery()->getResult();

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

    // ── Administration ─────────────────────────────────────────────────────

    /**
     * Nombre d'articles de chaque vendeur : les siens, plus ceux de sa ville qui n'ont pas encore de vendeur
     * (articles de départ), comme le calcul du front (mocks).
     *
     * @param list<Admin> $sellers
     *
     * @return array<int, int> nombre par identifiant de vendeur
     */
    public function countForSellers(array $sellers): array
    {
        $own = [];
        foreach ($this->createQueryBuilder('p')
            ->select('IDENTITY(p.seller) AS sid, COUNT(p.id) AS n')
            ->where('p.seller IS NOT NULL')
            ->andWhere('p.isActive = true')
            ->groupBy('sid')
            ->getQuery()->getArrayResult() as $row) {
            $own[(int) $row['sid']] = (int) $row['n'];
        }

        $unassignedByCity = [];
        foreach ($this->createQueryBuilder('p')
            ->select('p.city AS city, COUNT(p.id) AS n')
            ->where('p.seller IS NULL')
            ->andWhere('p.isActive = true')
            ->groupBy('p.city')
            ->getQuery()->getArrayResult() as $row) {
            $unassignedByCity[(string) $row['city']] = (int) $row['n'];
        }

        $counts = [];
        foreach ($sellers as $seller) {
            $counts[(int) $seller->getId()] = ($own[(int) $seller->getId()] ?? 0) + ($unassignedByCity[(string) $seller->getCity()] ?? 0);
        }

        return $counts;
    }

    /**
     * Articles en vente du périmètre d'un compte de l'équipe, avec leurs variantes : tous pour un super admin
     * ($seller null) ; pour un vendeur, les siens et ceux de sa ville sans vendeur.
     *
     * @return list<Product>
     */
    public function findActiveForAdmin(?Admin $seller, bool $withSeller = false): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.id')
            ->where('p.isActive = true')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC');
        if ($seller !== null) {
            $qb->andWhere('p.seller = :seller OR (p.seller IS NULL AND p.city = :city)')
                ->setParameter('seller', $seller)
                ->setParameter('city', (string) $seller->getCity());
        }

        return $this->findWithDetails(array_map('strval', $qb->getQuery()->getSingleColumnResult()), $withSeller);
    }

    /** Articles en vente rattachés à ce vendeur (les articles supprimés gardent leur historique). */
    public function countOwnedBy(Admin $seller): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.seller = :seller')
            ->andWhere('p.isActive = true')
            ->setParameter('seller', $seller)
            ->getQuery()->getSingleScalarResult();
    }

    /** Le vendeur change de ville : ses articles le suivent (comportement du front). */
    public function moveSellerProductsToCity(Admin $seller, string $city): int
    {
        return $this->createQueryBuilder('p')
            ->update()
            ->set('p.city', ':city')
            ->where('p.seller = :seller')
            ->setParameter('city', $city)
            ->setParameter('seller', $seller)
            ->getQuery()->execute();
    }

    /**
     * Recherche rapide de l'administration : nom, slug, catégorie ou sous-catégorie (sans accents ni casse).
     * Articles supprimés exclus (leur fiche n'existe plus). Un vendeur ne voit que ses articles et ceux de sa
     * ville sans vendeur.
     *
     * @return list<Product>
     */
    public function adminSearch(string $query, ?Admin $seller, int $limit): array
    {
        $like = '%' . addcslashes($query, '%_\\') . '%';
        $qb = $this->createQueryBuilder('p')
            ->select('p.id')
            ->innerJoin('p.category', 'c')
            ->leftJoin('p.subCategory', 's')
            ->where('p.name LIKE :q OR p.slug LIKE :q OR c.slug LIKE :q OR c.name LIKE :q OR s.slug LIKE :q OR s.name LIKE :q')
            ->andWhere('p.isActive = true')
            ->setParameter('q', $like)
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults($limit);

        if ($seller !== null) {
            $qb->andWhere('p.seller = :seller OR (p.seller IS NULL AND p.city = :city)')
                ->setParameter('seller', $seller)
                ->setParameter('city', (string) $seller->getCity());
        }

        return $this->findWithDetails(array_map('strval', $qb->getQuery()->getSingleColumnResult()));
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

    /**
     * Prix actuels, par identifiant d'article (article disparu : absent).
     *
     * @param list<string> $ids
     *
     * @return array<string, int>
     */
    public function prices(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_map('intval', $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT id, price FROM products WHERE id IN (?)',
            [array_values(array_unique($ids))],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        ));
    }
}
