<?php

namespace App\Controller\Account;

use App\Account\AccountNormalizer;
use App\Account\AddressBook;
use App\Api\ApiJson;
use App\Api\JsonBody;
use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Carnet d'adresses du client connecté (contrat §5.3 à §5.6). Règles dans App\Account\AddressBook.
 */
final class AddressController
{
    public function __construct(
        private readonly AddressBook $book,
        private readonly AccountNormalizer $normalizer,
    ) {}

    /** GET /api/me/addresses — adresse par défaut en tête. */
    #[Route('/me/addresses', name: 'api_me_addresses', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return ApiJson::privateNoCache(array_map($this->normalizer->address(...), $this->book->list($user)));
    }

    /** POST /api/me/addresses — 201 ; 200 si la même adresse existe déjà (double appui). */
    #[Route('/me/addresses', name: 'api_me_addresses_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $result = $this->book->create($user, JsonBody::object($request));

        return ApiJson::noStore($this->normalizer->address($result['address']), $result['created'] ? 201 : 200);
    }

    /** PATCH /api/me/addresses/{id} — modification partielle ; `isDefault: true` ; `agencyId: null` efface l'agence. */
    #[Route('/me/addresses/{id}', name: 'api_me_addresses_update', methods: ['PATCH'])]
    public function update(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        return ApiJson::noStore($this->normalizer->address($this->book->update($user, $id, JsonBody::object($request))));
    }

    /** DELETE /api/me/addresses/{id} — 204 ; la première adresse restante devient l'adresse par défaut. */
    #[Route('/me/addresses/{id}', name: 'api_me_addresses_delete', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] User $user): Response
    {
        $this->book->delete($user, $id);

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
