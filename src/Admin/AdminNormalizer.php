<?php

namespace App\Admin;

use App\Account\AccountNormalizer;
use App\Api\ApiDate;
use App\Entity\Address;
use App\Entity\Admin;
use App\Entity\Product;
use App\Entity\User;

/**
 * Formes JSON de l'administration (types du front : src/features/admin/types.ts).
 */
final class AdminNormalizer
{
    public const PERIODS = ['today', '7d', '30d', 'year'];

    public function __construct(private readonly AccountNormalizer $accounts) {}

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
     * `AdminSellerItem`. Ventes et chiffre d'affaires : calculés à partir des réservations (phases P5 à P7) ;
     * à zéro tant qu'aucune réservation n'existe.
     *
     * @return array<string, mixed>
     */
    public function seller(Admin $seller, int $productsCount): array
    {
        $kpis = [];
        foreach (self::PERIODS as $period) {
            $kpis[$period] = ['unitsSold' => 0, 'revenueConfirmed' => 0, 'reservationsConfirmed' => 0];
        }

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
            'unitsSoldTotal'        => 0,
            'revenueConfirmedTotal' => 0,
            'kpis'                  => $kpis,
        ];
    }

    /**
     * `AdminCustomerItem`. Réservations, compteur et total confirmé : à partir de la phase P5.
     *
     * @param list<Address> $addresses
     *
     * @return array<string, mixed>
     */
    public function customer(User $user, array $addresses): array
    {
        // Dernière modification du compte (connexion comprise) : même ordre que la liste (UserRepository::adminList).
        $lastActivity = $user->getUpdatedAt() ?? $user->getCreatedAt();

        return [
            'id'                 => (string) $user->getId(),
            'phone'              => $user->getPhone(),
            'firstName'          => $user->getFirstName() ?? '',
            'lastName'           => $user->getLastName() ?? '',
            // Client qui n'a pas encore donné son nom (étape « prénom » passée) : jamais de ligne vide à l'écran.
            'fullName'           => $user->getFullName() !== '' ? $user->getFullName() : 'Client sans nom',
            'city'               => $user->getCity() ?? '',
            'reservationsCount'  => 0,
            'confirmedTotalFcfa' => 0,
            'lastActivityAt'     => ApiDate::format($lastActivity),
            'addresses'          => array_map($this->accounts->address(...), $addresses),
            'reservations'       => [],
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
