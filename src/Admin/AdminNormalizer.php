<?php

namespace App\Admin;

use App\Account\AccountNormalizer;
use App\Catalog\CatalogNormalizer;
use App\Api\ApiDate;
use App\Entity\Address;
use App\Entity\Admin;
use App\Entity\Category;
use App\Entity\Look;
use App\Entity\Product;
use App\Entity\User;

/**
 * Formes JSON de l'administration (types du front : src/features/admin/types.ts).
 */
final class AdminNormalizer
{
    public const PERIODS = ['today', '7d', '30d', 'year'];

    public function __construct(
        private readonly AccountNormalizer $accounts,
        private readonly CatalogNormalizer $catalog,
    ) {}

    /**
     * Article vu par l'administration : la fiche publique complète, plus le vendeur (`sellerId`, `sellerName`),
     * que le site public ne montre jamais.
     *
     * @return array<string, mixed>
     */
    public function adminProduct(Product $product): array
    {
        $data = $this->catalog->product($product);
        $seller = $product->getSeller();
        if ($seller !== null) {
            $data['sellerId'] = (string) $seller->getId();
            $data['sellerName'] = $seller->getName();
        }

        return $data;
    }

    /**
     * @param array{categories: array<string, int>, subCategories: array<string, int>} $counts articles en vente
     *
     * @return array<string, mixed>
     */
    public function adminCategory(Category $category, array $counts): array
    {
        return $this->catalog->category($category, $counts['categories'], $counts['subCategories']);
    }

    /** Look tel qu'enregistré (toutes ses adresses d'articles, même retirés de la vente). @return array<string, mixed> */
    public function adminLook(Look $look): array
    {
        return [
            'id'           => $look->getId(),
            'title'        => $look->getTitle(),
            'subtitle'     => $look->getSubtitle(),
            'image'        => $look->getImage(),
            'fallback'     => $look->getFallback(),
            'productSlugs' => array_values($look->getProductSlugs()),
        ];
    }

    /**
     * `AdminUser` : jamais le mot de passe. `phone` vaut "" s'il n'est pas renseigné (champ obligatoire du type).
     *
     * @return array<string, mixed>
     */
    public function admin(Admin $admin): array
    {
        $data = [
            'id'    => (string) $admin->getId(),
            'email' => $admin->getEmail(),
            'phone' => $admin->getPhone() ?? '',
            'name'  => $admin->getName(),
            'role'  => $admin->getRole(),
        ];
        if ($admin->getCity() !== null) {
            $data['city'] = $admin->getCity();
        }
        $data['active'] = $admin->isActive();
        $data['createdAt'] = ApiDate::format($admin->getCreatedAt());

        return $data;
    }

    /**
     * `AdminSellerItem`. Ventes et chiffre d'affaires de ses articles (App\Admin\SalesFigures::sellers, P7) ; à zéro
     * pour un vendeur qui vient d'être créé ou modifié (réponse de l'écran, la liste est relue ensuite).
     *
     * @param array{unitsSoldTotal: int, revenueConfirmedTotal: int, kpis: array<string, array<string, int>>}|null $figures
     *
     * @return array<string, mixed>
     */
    public function seller(Admin $seller, int $productsCount, ?array $figures = null): array
    {
        $figures ??= SalesFigures::emptySeller();

        return [
            'id'                    => (string) $seller->getId(),
            'name'                  => $seller->getName(),
            'email'                 => $seller->getEmail(),
            'phone'                 => $seller->getPhone() ?? '',
            'role'                  => 'seller',
            'city'                  => $seller->getCity() ?? '',
            'active'                => $seller->isActive(),
            'createdAt'             => ApiDate::format($seller->getCreatedAt()),
            'productsCount'         => $productsCount,
            'unitsSoldTotal'        => $figures['unitsSoldTotal'],
            'revenueConfirmedTotal' => $figures['revenueConfirmedTotal'],
            'kpis'                  => $figures['kpis'],
        ];
    }

    /**
     * `AdminCustomerItem` : compte du client, ses réservations (lignes `AdminReservationRow`), leur nombre et le
     * total payé (réservations dont le paiement a été validé), P6.
     *
     * @param list<Address>                                                $addresses
     * @param array{count: int, paid: int, last: ?\DateTimeImmutable}|null $stats
     * @param list<array<string, mixed>>                                   $reservations
     *
     * @return array<string, mixed>
     */
    public function customer(User $user, array $addresses, ?array $stats = null, array $reservations = [], bool $withAccountActivity = true): array
    {
        // Dernière activité : compte (connexion comprise) ou dernière réservation, la plus récente des deux. Pour un
        // vendeur : sa dernière réservation dans son périmètre seulement (même ordre que sa liste).
        $lastActivity = $withAccountActivity || ($stats['last'] ?? null) === null ? $user->getUpdatedAt() ?? $user->getCreatedAt() : $stats['last'];
        if (($stats['last'] ?? null) !== null && $stats['last'] > $lastActivity) {
            $lastActivity = $stats['last'];
        }

        return [
            'id'                 => (string) $user->getId(),
            'phone'              => $user->getPhone(),
            'firstName'          => $user->getFirstName() ?? '',
            'lastName'           => $user->getLastName() ?? '',
            // Client qui n'a pas encore donné son nom (étape « prénom » passée) : jamais de ligne vide à l'écran.
            'fullName'           => $user->getFullName() !== '' ? $user->getFullName() : 'Client sans nom',
            'city'               => $user->getCity() ?? '',
            'reservationsCount'  => $stats['count'] ?? 0,
            'confirmedTotalFcfa' => $stats['paid'] ?? 0,
            'lastActivityAt'     => ApiDate::format($lastActivity),
            'addresses'          => array_map($this->accounts->address(...), $addresses),
            'reservations'       => $reservations,
        ];
    }

    /** `AdminSearchProductItem`. @return array<string, mixed> */
    public function searchProduct(Product $product): array
    {
        $gallery = $product->getMediaGallery();
        $first = $gallery[0] ?? [];
        $stocks = array_map(fn ($variant) => $variant->getStock(), $product->getVariants()->toArray());

        return [
            'id'              => $product->getId(),
            'slug'            => $product->getSlug(),
            'name'            => $product->getName(),
            'image'           => (string) ($first['url'] ?? $first['fallbackUrl'] ?? ''),
            'price'           => $product->getPrice(),
            'totalStock'      => array_sum($stocks),
            'outOfStockCount' => \count(array_filter($stocks, fn (int $stock) => $stock === 0)),
        ];
    }
}
