<?php

namespace App\Account;

use App\Api\ApiException;
use App\Entity\Address;
use App\Entity\Agency;
use App\Entity\DeliveryCity;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Repository\AgencyRepository;
use App\Repository\DeliveryCityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Carnet d'adresses du client (contrat §5.3 à §5.6).
 *
 * Mêmes règles que le formulaire du front (addDeliveryAddressIssues) :
 *  - ville locale (livraison à domicile) : quartier (2 caractères au moins) et point de repère (5 au moins) ;
 *  - ville desservie par agence : agence de CETTE ville obligatoire ; district = ville, landmark = agence ;
 *  - téléphone du destinataire : mobile camerounais (celui du compte par défaut).
 * Une seule adresse par défaut ; la première l'est d'office ; supprimer l'adresse par défaut donne ce statut
 * à la première adresse restante. Un double appui sur « Enregistrer » ne crée pas de doublon.
 */
final class AddressBook
{
    public const MAX_ADDRESSES = 20;

    private const FIELDS = ['label', 'city', 'district', 'landmark', 'receptionMode', 'agencyId', 'recipientName', 'recipientPhone'];

    public function __construct(
        private readonly AddressRepository $addresses,
        private readonly DeliveryCityRepository $cities,
        private readonly AgencyRepository $agencies,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {}

    /** @return list<Address> */
    public function list(User $user): array
    {
        return $this->addresses->findForUser($user);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{address: Address, created: bool}
     */
    public function create(User $user, array $body): array
    {
        return $this->exclusive($user, function () use ($user, $body): array {
            $existing = $this->addresses->findForUser($user);

            $draft = new Address($user, $this->nextSequence($existing), $this->now());
            $this->apply($draft, $this->resolve($user, $body, null));

            foreach ($existing as $address) {
                if ($address->contentKey() === $draft->contentKey()) {
                    if (($body['isDefault'] ?? null) === true) {
                        $this->makeDefault($address, $existing);
                    }

                    return ['address' => $address, 'created' => false];
                }
            }

            if (\count($existing) >= self::MAX_ADDRESSES) {
                throw ApiException::validation(
                    ['addresses' => sprintf('Vous avez déjà %d adresses.', self::MAX_ADDRESSES)],
                    sprintf('Vous avez déjà %d adresses. Supprimez-en une pour en ajouter une nouvelle.', self::MAX_ADDRESSES),
                );
            }

            $this->em->persist($draft);
            if ($existing === [] || ($body['isDefault'] ?? null) === true) {
                $this->makeDefault($draft, $existing);
            }

            return ['address' => $draft, 'created' => true];
        });
    }

    /** @param array<string, mixed> $body */
    public function update(User $user, string $id, array $body): Address
    {
        return $this->exclusive($user, function () use ($user, $id, $body): Address {
            $address = $this->find($user, $id);

            // Seul le statut « par défaut » change : les autres champs ne sont pas revalidés
            // (une ville retirée depuis ne bloque pas « Définir par défaut »).
            if (array_intersect(self::FIELDS, array_keys($body)) !== []) {
                $this->apply($address, $this->resolve($user, $body, $address));
            }

            if (($body['isDefault'] ?? null) === true && !$address->isDefault()) {
                $this->makeDefault($address, $this->addresses->findForUser($user));
            }
            // isDefault: false sur l'adresse par défaut est ignoré : le carnet garde toujours une adresse par défaut.

            return $address;
        });
    }

    public function delete(User $user, string $id): void
    {
        $this->exclusive($user, function () use ($user, $id): void {
            $address = $this->find($user, $id);
            $remaining = array_values(array_filter($this->addresses->findForUser($user), fn (Address $a) => $a !== $address));

            $this->em->remove($address);

            // L'adresse par défaut supprimée : la première adresse restante (ordre d'ajout) prend sa place.
            if ($remaining !== [] && !array_filter($remaining, fn (Address $a) => $a->isDefault())) {
                usort($remaining, fn (Address $a, Address $b) => [$a->getSequence(), $a->getId()] <=> [$b->getSequence(), $b->getId()]);
                $remaining[0]->setIsDefault(true);
            }
        });
    }

    // ── Interne ───────────────────────────────────────────────────────────

    /**
     * Une écriture à la fois par carnet, dans une seule transaction : deux appareils qui changent l'adresse par
     * défaut en même temps ne laissent jamais deux adresses « par défaut » (ni aucune après une suppression).
     * Le verrou porte sur la ligne du client ; les lectures qui suivent voient la dernière écriture validée.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function exclusive(User $user, \Closure $work): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeQuery('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user->getId()]);
            $result = $work();
            $this->em->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    private function find(User $user, string $id): Address
    {
        $address = \strlen($id) <= 40 ? $this->addresses->findOneForUser($user, $id) : null;
        if ($address === null) {
            throw ApiException::notFound('ADDRESS_NOT_FOUND', 'Cette adresse est introuvable.');
        }

        return $address;
    }

    /** @param list<Address> $all */
    private function makeDefault(Address $address, array $all): void
    {
        foreach ($all as $other) {
            if ($other !== $address && $other->isDefault()) {
                $other->setIsDefault(false);
            }
        }
        $address->setIsDefault(true);
    }

    /** @param list<Address> $existing */
    private function nextSequence(array $existing): int
    {
        $max = 0;
        foreach ($existing as $address) {
            $max = max($max, $address->getSequence());
        }

        return $max + 1;
    }

    /**
     * Valeurs finales de l'adresse : corps de la requête par-dessus l'adresse actuelle (PATCH : un champ
     * absent ne change pas, `agencyId: null` efface l'agence), puis contrôle de l'ensemble.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, ?string>
     */
    private function resolve(User $user, array $body, ?Address $current): array
    {
        $value = fn (string $field, mixed $currentValue) => \array_key_exists($field, $body) ? $body[$field] : $currentValue;
        $errors = [];

        $label = $this->label($value('label', $current?->getLabel()));
        if ($label === null) {
            $errors['label'] = 'Choisissez Maison, Bureau ou Autre.';
        }

        $receptionMode = $value('receptionMode', $current?->getReceptionMode() ?? 'delivery') ?? 'delivery';
        if (!\in_array($receptionMode, Address::RECEPTION_MODES, true)) {
            $errors['receptionMode'] = 'Mode de réception invalide.';
        }

        $city = $this->city($value('city', $current?->getCity()));
        $district = TextInput::clean($value('district', $current?->getDistrict())) ?? '';
        $landmark = TextInput::clean($value('landmark', $current?->getLandmark())) ?? '';
        $agencyId = null;

        if ($city === null) {
            $errors['city'] = 'Choisissez votre ville de livraison.';
        } elseif (!$city->isLocal() && $city->hasAgencies()) {
            $agency = $this->agency($value('agencyId', $current?->getAgencyId()), $city);
            if ($agency === null) {
                $errors['agencyId'] = 'Choisissez l’agence de transport où récupérer votre colis.';
            } else {
                $agencyId = $agency->getId();
                // Envoi par agence : district = ville, landmark = agence (contrat §5.4), complétés s'ils manquent.
                if (!\array_key_exists('district', $body) || $district === '') {
                    $district = $city->getName();
                }
                if (!\array_key_exists('landmark', $body) || $landmark === '') {
                    $landmark = $agency->getName();
                }
            }
        } else {
            // Livraison à domicile : pas d'agence (le front envoie `agencyId: null`).
            if (TextInput::length($district) < 2) {
                $errors['district'] = 'Indiquez votre quartier (ex. : Bastos, Omnisports).';
            }
            if (TextInput::length($landmark) < 5) {
                $errors['landmark'] = 'Précisez un point de repère connu pour guider le livreur.';
            }
        }
        if (TextInput::length($district) > 120) {
            $errors['district'] = 'Le quartier ne doit pas dépasser 120 caractères.';
        }
        if (TextInput::length($landmark) > 255) {
            $errors['landmark'] = 'Le point de repère ne doit pas dépasser 255 caractères.';
        }

        $recipientName = $this->recipientName($value('recipientName', $current?->getRecipientName()), $user, $errors);
        $recipientPhone = $this->recipientPhone($value('recipientPhone', $current?->getRecipientPhone()), $user, $errors);

        if ($errors !== []) {
            throw ApiException::validation($errors, 'L’adresse n’a pas pu être enregistrée. Vérifiez les informations saisies.');
        }

        return [
            'label'          => $label,
            'city'           => $city->getName(),
            'district'       => $district,
            'landmark'       => $landmark,
            'receptionMode'  => $receptionMode,
            'agencyId'       => $agencyId,
            'recipientName'  => $recipientName,
            'recipientPhone' => $recipientPhone,
        ];
    }

    /** @param array<string, ?string> $values */
    private function apply(Address $address, array $values): void
    {
        $address->setLabel((string) $values['label'])
            ->setCity((string) $values['city'])
            ->setDistrict((string) $values['district'])
            ->setLandmark((string) $values['landmark'])
            ->setReceptionMode((string) $values['receptionMode'])
            ->setAgencyId($values['agencyId'])
            ->setRecipientName($values['recipientName'])
            ->setRecipientPhone($values['recipientPhone']);
    }

    private function label(mixed $raw): ?string
    {
        $raw = TextInput::clean($raw);
        if ($raw === null) {
            return null;
        }
        foreach (Address::LABELS as $label) {
            if (mb_strtolower($label) === mb_strtolower($raw)) {
                return $label;
            }
        }

        return null;
    }

    private function city(mixed $raw): ?DeliveryCity
    {
        $raw = TextInput::clean($raw);

        return ($raw === null || $raw === '' || TextInput::length($raw) > 100) ? null : $this->cities->findVisibleByIdOrName($raw);
    }

    private function agency(mixed $raw, DeliveryCity $city): ?Agency
    {
        if (!\is_string($raw) || $raw === '' || \strlen($raw) > 40) {
            return null;
        }
        $agency = $this->agencies->find($raw);

        return ($agency !== null && $agency->isActive() && $agency->getCity()->getId() === $city->getId()) ? $agency : null;
    }

    /** @param array<string, string> $errors */
    private function recipientName(mixed $raw, User $user, array &$errors): ?string
    {
        $name = TextInput::clean($raw);
        if ($name === null || $name === '') {
            // Le client lui-même par défaut.
            $fullName = $user->getFullName();

            return $fullName !== '' ? mb_substr($fullName, 0, 120) : null;
        }
        // Pas de longueur minimale : pour le client lui-même, le front envoie son nom de profil, qui peut tenir en
        // une lettre (le front exige déjà 2 caractères quand le colis est récupéré par une autre personne).
        if (TextInput::length($name) > 120) {
            $errors['recipientName'] = 'Le nom ne doit pas dépasser 120 caractères.';
        }

        return $name;
    }

    /** @param array<string, string> $errors */
    private function recipientPhone(mixed $raw, User $user, array &$errors): ?string
    {
        if ($raw === null || (\is_string($raw) && trim($raw) === '')) {
            return $user->getPhone(); // le téléphone du compte par défaut
        }
        $phone = PhoneNumber::normalizeCameroonMobile($raw);
        if ($phone === null) {
            $errors['recipientPhone'] = 'Entrez un numéro mobile camerounais valide (9 chiffres commençant par 6).';
        }

        return $phone;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp()); // UTC, à la seconde
    }
}
