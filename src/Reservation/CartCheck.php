<?php

namespace App\Reservation;

use App\Entity\ProductVariant;

/**
 * État d'une ligne de panier (format de POST /cart/validate, contrat §9.1), partagé avec la création d'une
 * réservation : un conflit de stock y renvoie ces mêmes lignes (`details.items`), que le front applique au panier.
 */
final class CartCheck
{
    /**
     * @param array{variantId: string, quantity: int, unitPrice?: ?int} $line
     * @param int|null $stock stock lu sous verrou (sinon celui de la variante)
     *
     * @return array<string, mixed>
     */
    public static function line(array $line, ?ProductVariant $variant, ?int $stock = null): array
    {
        $product = $variant?->getProduct();
        $stock ??= $variant?->getStock() ?? 0;

        if ($variant === null || !$product->isVisible() || $stock <= 0) {
            return [
                'variantId'         => $line['variantId'],
                'status'            => 'unavailable',
                'availableQuantity' => 0,
                'unitPrice'         => $product?->getPrice() ?? 0,
                'city'              => $product?->getCity(),
            ];
        }

        $item = [
            'variantId'         => $line['variantId'],
            'status'            => $line['quantity'] > $stock ? 'reduced' : 'ok',
            'availableQuantity' => $stock,
            'unitPrice'         => $product->getPrice(),
            'city'              => $product->getCity(),
        ];

        // Prix vu par le client (si l'app l'envoie un jour, contrat §17 question 8) : changement signalé.
        $seen = $line['unitPrice'] ?? null;
        if ($item['status'] === 'ok' && $seen !== null && $seen !== $product->getPrice()) {
            $item['status'] = 'price_changed';
            $item['previousPrice'] = $seen;
        }

        return $item;
    }
}
