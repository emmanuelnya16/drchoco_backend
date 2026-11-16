<?php

namespace App\Controller\Account;

use App\Api\ApiJson;
use App\Api\JsonBody;
use App\Entity\User;
use App\Repository\FavoriteRepository;
use App\Repository\ProductRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Favoris du client (contrat §5.7, §16). Les favoris vivent sur le téléphone : l'app envoie les siens
 * juste après la connexion et reçoit la liste fusionnée du compte. Un article inconnu ou retiré de la vente
 * est ignoré, sans erreur.
 */
final class FavoriteController
{
    public const MAX_FAVORITES = 500;
    private const MAX_IDS_PER_REQUEST = 200;

    public function __construct(
        private readonly FavoriteRepository $favorites,
        private readonly ProductRepository $products,
        private readonly ClockInterface $clock,
    ) {}

    /** POST /api/favorites — `{ "productIds": [...] }` (ou `{ "productId": "…" }`) ; renvoie tous les favoris. */
    #[Route('/favorites', name: 'api_favorites_merge', methods: ['POST'])]
    public function merge(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = JsonBody::object($request);

        $ids = [];
        if (\is_array($body['productIds'] ?? null)) {
            foreach (\array_slice(array_values($body['productIds']), 0, self::MAX_IDS_PER_REQUEST) as $id) {
                $ids[] = $id;
            }
        }
        $ids[] = $body['productId'] ?? null;
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => \is_string($id) && $id !== '' && \strlen($id) <= 40)));

        $visible = $this->products->visibleIdsAmong($ids);
        if ($visible !== []) {
            $already = $this->favorites->existingProductIds($user, $visible);
            $new = array_values(array_diff($visible, $already));
            $room = max(0, self::MAX_FAVORITES - $this->favorites->countFor($user));
            // Ordre du téléphone conservé pour les nouveaux favoris.
            $new = \array_slice(array_values(array_filter($ids, fn ($id) => \in_array($id, $new, true))), 0, $room);
            $this->favorites->addMany($user, $new, \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC')));
        }

        return ApiJson::noStore($this->favorites->visibleProductIdsFor($user));
    }

    /** GET /api/favorites — prévu par le contrat (§16), pas encore appelé par l'app. */
    #[Route('/favorites', name: 'api_favorites_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return ApiJson::privateNoCache($this->favorites->visibleProductIdsFor($user));
    }

    /** DELETE /api/favorites/{productId} — prévu par le contrat (§16) ; 204 même si l'article n'était pas favori. */
    #[Route('/favorites/{productId}', name: 'api_favorites_remove', methods: ['DELETE'])]
    public function remove(string $productId, #[CurrentUser] User $user): Response
    {
        if (\strlen($productId) <= 40) {
            $this->favorites->remove($user, $productId);
        }

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
