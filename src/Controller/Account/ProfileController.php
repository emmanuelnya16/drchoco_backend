<?php

namespace App\Controller\Account;

use App\Account\AccountNormalizer;
use App\Account\TextInput;
use App\Api\ApiException;
use App\Api\ApiJson;
use App\Api\JsonBody;
use App\Catalog\TextFolder;
use App\Entity\User;
use App\Repository\DeliveryCityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Profil du client connecté (contrat §5.1, §5.2).
 */
final class ProfileController
{
    public const NAME_MAX_LENGTH = 40;

    public function __construct(
        private readonly AccountNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
        private readonly DeliveryCityRepository $cities,
    ) {}

    /** GET /api/me */
    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return ApiJson::privateNoCache($this->normalizer->user($user));
    }

    /**
     * PATCH /api/me — seuls les champs présents sont modifiés. `lastName` vide efface le nom ;
     * `city` doit être une ville desservie (nom sans accents ni casse, ou identifiant).
     */
    #[Route('/me', name: 'api_me_update', methods: ['PATCH'])]
    public function update(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = JsonBody::object($request);
        $errors = [];
        $changes = [];

        if (\array_key_exists('firstName', $body)) {
            $firstName = TextInput::clean($body['firstName']);
            if ($firstName === null || $firstName === '') {
                $errors['firstName'] = 'Indiquez votre prénom.';
            } elseif (TextInput::length($firstName) > self::NAME_MAX_LENGTH) {
                $errors['firstName'] = 'Votre prénom ne doit pas dépasser 40 caractères.';
            } else {
                $changes['firstName'] = $firstName;
            }
        }

        if (\array_key_exists('lastName', $body)) {
            $lastName = $body['lastName'] === null ? '' : TextInput::clean($body['lastName']);
            if ($lastName === null) {
                $errors['lastName'] = 'Nom invalide.';
            } elseif (TextInput::length($lastName) > self::NAME_MAX_LENGTH) {
                $errors['lastName'] = 'Votre nom ne doit pas dépasser 40 caractères.';
            } else {
                $changes['lastName'] = $lastName !== '' ? $lastName : null;
            }
        }

        if (\array_key_exists('city', $body)) {
            $raw = TextInput::clean($body['city']);
            // Ville inchangée (la page Profil renvoie toujours les trois champs) : pas de nouveau contrôle, pour
            // qu'une ville retirée depuis de la liste des livraisons n'empêche pas de corriger son prénom.
            $unchanged = $raw !== null && $raw !== '' && $user->getCity() !== null
                && TextFolder::fold($raw) === TextFolder::fold($user->getCity());
            $city = ($unchanged || $raw === null || $raw === '' || TextInput::length($raw) > 100) ? null : $this->cities->findVisibleByIdOrName($raw);
            if ($unchanged) {
                // rien à changer
            } elseif ($city === null) {
                $errors['city'] = ($raw === null || $raw === '') ? 'Choisissez votre ville.' : 'Choisissez une ville de la liste.';
            } else {
                $changes['city'] = $city->getName();
            }
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        if (\array_key_exists('firstName', $changes)) {
            $user->setFirstName($changes['firstName']);
        }
        if (\array_key_exists('lastName', $changes)) {
            $user->setLastName($changes['lastName']);
        }
        if (\array_key_exists('city', $changes)) {
            $user->setCity($changes['city']);
        }
        $this->em->flush();

        return ApiJson::noStore($this->normalizer->user($user));
    }
}
