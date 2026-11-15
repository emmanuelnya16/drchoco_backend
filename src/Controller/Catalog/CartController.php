<?php

namespace App\Controller\Catalog;

use App\Api\ApiDate;
use App\Api\ApiException;
use App\Api\ApiJson;
use App\Repository\ProductVariantRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/cart/validate — vérifie le stock et les prix du panier avant la réservation (contrat §9.1).
 *
 * Public (appelé avant la connexion). Une variante inconnue ou retirée ne provoque jamais d'erreur :
 * elle revient « unavailable » (« Réserver à nouveau » envoie parfois des variantes disparues).
 * Le stock sera de toute façon revérifié, sous verrou, à la création de la réservation (P5).
 */
final class CartController
{
    private const MAX_LINES = 100;

    #[Route('/cart/validate', name: 'api_cart_validate', methods: ['POST'])]
    public function __invoke(Request $request, ProductVariantRepository $variants, ClockInterface $clock): JsonResponse
    {
        $lines = $this->readLines($request);
        $found = $variants->findWithProducts(array_column($lines, 'variantId'));

        $items = [];
        foreach ($lines as $line) {
            $variant = $found[$line['variantId']] ?? null;
            $product = $variant?->getProduct();

            if ($variant === null || !$product->isVisible() || $variant->getStock() <= 0) {
                $items[] = [
                    'variantId'         => $line['variantId'],
                    'status'            => 'unavailable',
                    'availableQuantity' => 0,
                    'unitPrice'         => $product?->getPrice() ?? 0,
                ];
                continue;
            }

            $item = [
                'variantId'         => $line['variantId'],
                'status'            => $line['quantity'] > $variant->getStock() ? 'reduced' : 'ok',
                'availableQuantity' => $variant->getStock(),
                'unitPrice'         => $product->getPrice(),
            ];

            // Prix vu par le client (si l'app l'envoie un jour, contrat §17 question 8) : changement signalé.
            if ($item['status'] === 'ok' && $line['unitPrice'] !== null && $line['unitPrice'] !== $product->getPrice()) {
                $item['status'] = 'price_changed';
                $item['previousPrice'] = $line['unitPrice'];
            }

            $items[] = $item;
        }

        return ApiJson::noStore([
            'checkedAt' => ApiDate::format($clock->now()),
            'items'     => $items,
        ]);
    }

    /** @return list<array{variantId: string, quantity: int, unitPrice: ?int}> */
    private function readLines(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = null;
        }

        if (!\is_array($body) || !\is_array($body['items'] ?? null) || !array_is_list($body['items'])) {
            throw ApiException::validation(['items' => 'Le panier est invalide.']);
        }
        if (\count($body['items']) > self::MAX_LINES) {
            throw ApiException::validation(['items' => 'Le panier contient trop d’articles.']);
        }

        $lines = [];
        foreach ($body['items'] as $i => $entry) {
            $variantId = \is_array($entry) ? ($entry['variantId'] ?? null) : null;
            if (!\is_string($variantId) && !\is_int($variantId)) {
                throw ApiException::validation(["items[$i].variantId" => 'Article invalide.']);
            }
            $quantity = $entry['quantity'] ?? 1;
            $unitPrice = $entry['unitPrice'] ?? null;

            $lines[] = [
                'variantId' => mb_substr((string) $variantId, 0, 80),
                'quantity'  => \is_int($quantity) && $quantity >= 1 ? min($quantity, 999) : 1,
                'unitPrice' => \is_int($unitPrice) && $unitPrice >= 0 ? $unitPrice : null,
            ];
        }

        return $lines;
    }
}
