<?php

namespace App\Admin\Delivery;

use App\Account\TextInput;
use App\Admin\CatalogInput;
use App\Admin\Content\FrenchTypography;
use App\Api\ApiException;
use App\Catalog\ShopCity;
use App\Entity\Address;
use App\Entity\Agency;
use App\Entity\DeliveryCity;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Villes de livraison et agences d'expédition dans l'administration (P8).
 *
 * Yaoundé et Douala sont les villes des boutiques : ni renommées, ni masquées, ni supprimées. Une ville qui a
 * encore des agences n'est pas supprimée (409). Une agence garde ses réglages absents d'une modification
 * (prix, boutique de départ, état). Les réservations gardent leur copie de l'agence : la supprimer est sans
 * effet sur elles.
 */
final class DeliveryEditor
{
    public const MAX_FEE = 100000;

    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(private readonly EntityManagerInterface $em) {}

    // --- Villes ---

    /** @return list<DeliveryCity> toutes, masquées comprises */
    public function cities(): array
    {
        return $this->em->getRepository(DeliveryCity::class)->findBy([], ['position' => 'ASC', 'name' => 'ASC']);
    }

    /** @return array<string, int> nombre d'agences (actives ou non) par ville */
    public function agencyCounts(): array
    {
        $counts = [];
        foreach ($this->em->createQuery('SELECT IDENTITY(a.city) AS city, COUNT(a.id) AS n FROM ' . Agency::class . ' a GROUP BY a.city')->getScalarResult() as $row) {
            $counts[(string) $row['city']] = (int) $row['n'];
        }

        return $counts;
    }

    public function city(string $id): DeliveryCity
    {
        return $this->em->find(DeliveryCity::class, $id) ?? throw ApiException::notFound('CITY_NOT_FOUND', 'Cette ville de livraison est introuvable.');
    }

    /** @param array<string, mixed> $body */
    public function createCity(array $body): DeliveryCity
    {
        $this->errors = [];
        $name = $this->text($body['name'] ?? null, 'name', 2, 80, 'Le nom de la ville');
        if ($name !== null) {
            $this->assertCityNameFree($name, null);
        }
        $fee = $this->fee($body['fee'] ?? null, 'fee', 'Prix de livraison de la ville : de 0 à 100 000 FCFA.');
        $this->throwIfInvalid();

        $city = new DeliveryCity($this->newId('city', CatalogInput::slugify((string) $name), DeliveryCity::class), (string) $name);
        $city->setFee((int) $fee)->setPosition($this->nextCityPosition());
        $this->applyCityFlags($city, $body);
        $this->throwIfInvalid();
        $this->em->persist($city);
        $this->em->flush();

        return $city;
    }

    /** @param array<string, mixed> $body */
    public function updateCity(DeliveryCity $city, array $body): DeliveryCity
    {
        $this->errors = [];
        $isShop = self::isShopCity($city);
        $renamed = null;
        if (\array_key_exists('name', $body)) {
            $name = $this->text($body['name'], 'name', 2, 80, 'Le nom de la ville');
            if ($name !== null && $isShop) {
                // Ville d'une boutique : le nom reste exactement « Yaoundé » ou « Douala » (articles, réglages,
                // adresses s'y réfèrent) ; une autre écriture du même nom est ignorée.
                if (ShopCity::normalize($name) !== ShopCity::normalize($city->getName())) {
                    $this->errors['name'] = sprintf('%s est la ville d’une boutique : son nom ne peut pas changer.', $city->getName());
                }
            } elseif ($name !== null && $name !== $city->getName()) {
                $this->assertCityNameFree($name, $city);
                $previous = $city->getName();
                $city->setName($name);
                $renamed = [$previous, $name];
            }
        }
        if (\array_key_exists('fee', $body)) {
            $fee = $this->fee($body['fee'], 'fee', 'Prix de livraison de la ville : de 0 à 100 000 FCFA.');
            if ($fee !== null) {
                $city->setFee($fee);
            }
        }
        $this->applyCityFlags($city, $body);
        if ($isShop && !$city->isActive()) {
            $this->errors['isActive'] = sprintf('%s est la ville d’une boutique : elle reste proposée.', $city->getName());
        }
        $this->throwIfInvalid();
        $this->em->wrapInTransaction(function () use ($renamed): void {
            $this->em->flush();
            if ($renamed !== null) {
                $this->propagateRename(...$renamed);
            }
        });

        return $city;
    }

    /**
     * Adresses enregistrées et profils des clients gardent le nom de la ville (pas son identifiant) : ils suivent
     * le nouveau nom, sinon ces adresses ne seraient plus reconnues à la réservation.
     */
    private function propagateRename(string $previous, string $name): void
    {
        $this->em->createQuery('UPDATE ' . Address::class . ' a SET a.city = :name WHERE a.city = :previous')
            ->setParameter('name', $name)->setParameter('previous', $previous)->execute();
        // Adresse par agence : le quartier contient le nom de la ville.
        $this->em->createQuery('UPDATE ' . Address::class . ' a SET a.district = :name WHERE a.district = :previous AND a.agencyId IS NOT NULL')
            ->setParameter('name', $name)->setParameter('previous', $previous)->execute();
        $this->em->createQuery('UPDATE ' . User::class . ' u SET u.city = :name WHERE u.city = :previous')
            ->setParameter('name', $name)->setParameter('previous', $previous)->execute();
    }

    public function deleteCity(DeliveryCity $city): void
    {
        if (self::isShopCity($city)) {
            throw new ApiException(409, 'CITY_IS_SHOP', sprintf('%s est la ville d’une boutique : elle ne peut pas être supprimée.', $city->getName()));
        }
        $agencies = $this->agencyCounts()[$city->getId()] ?? 0;
        if ($agencies > 0) {
            throw new ApiException(409, 'CITY_HAS_AGENCIES', sprintf('Supprimez ou déplacez d’abord %s de %s.', $agencies > 1 ? "ses $agencies agences" : 'son agence', $city->getName()), ['agencies' => $agencies]);
        }
        $this->em->remove($city);
        $this->em->flush();
    }

    public static function isShopCity(DeliveryCity $city): bool
    {
        return ShopCity::normalize($city->getName()) !== null;
    }

    // --- Agences ---

    /** @return list<Agency> toutes, masquées comprises */
    public function agencies(): array
    {
        return $this->em->createQueryBuilder()
            ->select('a', 'c')
            ->from(Agency::class, 'a')
            ->innerJoin('a.city', 'c')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('a.position', 'ASC')
            ->addOrderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function agency(string $id): Agency
    {
        return $this->em->find(Agency::class, $id) ?? throw ApiException::notFound('AGENCY_NOT_FOUND', 'Cette agence est introuvable.');
    }

    /** @param array<string, mixed> $body */
    public function createAgency(array $body): Agency
    {
        $this->errors = [];
        $city = $this->cityOf($body['cityId'] ?? null);
        $name = $this->text($body['name'] ?? null, 'name', 2, 120, 'Le nom de l’agence');
        if ($city !== null && $name !== null) {
            $this->assertAgencyNameFree($name, $city, null);
        }
        $this->throwIfInvalid();

        $agency = new Agency($this->newId('ag', CatalogInput::slugify($city->getName() . ' ' . $name), Agency::class), $city, (string) $name);
        $agency->setPosition($this->nextAgencyPosition($city))->setIsActive(true)->setFee(null)->setDepartureCity(null);
        $this->applyAgencyFields($agency, $body);
        $this->throwIfInvalid();
        $this->em->persist($agency);
        $this->em->flush();

        return $agency;
    }

    /** @param array<string, mixed> $body */
    public function updateAgency(Agency $agency, array $body): Agency
    {
        $this->errors = [];
        if (\array_key_exists('cityId', $body)) {
            $city = $this->cityOf($body['cityId']);
            if ($city !== null && $city->getId() !== $agency->getCity()->getId()) {
                $agency->setCity($city)->setPosition($this->nextAgencyPosition($city));
            }
        }
        if (\array_key_exists('name', $body)) {
            $name = $this->text($body['name'], 'name', 2, 120, 'Le nom de l’agence');
            if ($name !== null) {
                $agency->setName($name);
            }
        }
        if (\array_key_exists('name', $body) || \array_key_exists('cityId', $body)) {
            $this->assertAgencyNameFree($agency->getName(), $agency->getCity(), $agency);
        }
        $this->applyAgencyFields($agency, $body);
        $this->throwIfInvalid();
        $this->em->flush();

        return $agency;
    }

    public function deleteAgency(Agency $agency): void
    {
        $this->em->remove($agency);
        $this->em->flush();
    }

    /** Numéro d'agence : mobile (6…) ou fixe (2…) camerounais, présenté « +237 XXX XX XX XX ». */
    public static function formatPhone(string $raw): ?string
    {
        $digits = preg_replace('/[\s\x{00A0}\x{202F}.\-()]/u', '', $raw) ?? '';
        foreach (['+237', '00237'] as $prefix) {
            if (str_starts_with($digits, $prefix)) {
                $digits = substr($digits, \strlen($prefix));
            }
        }
        if (\strlen($digits) === 12 && str_starts_with($digits, '237')) {
            $digits = substr($digits, 3);
        }
        if (preg_match('/^[26]\d{8}$/', $digits) !== 1) {
            return null;
        }

        return sprintf('+237 %s %s %s %s', substr($digits, 0, 3), substr($digits, 3, 2), substr($digits, 5, 2), substr($digits, 7, 2));
    }

    /** @param array<string, mixed> $body */
    private function applyAgencyFields(Agency $agency, array $body): void
    {
        if (\array_key_exists('address', $body)) {
            $address = $this->text($body['address'], 'address', 0, 255, 'L’adresse');
            if ($address !== null) {
                $agency->setAddress(FrenchTypography::spacing($address));
            }
        }
        if (\array_key_exists('openingHours', $body)) {
            $hours = $this->text($body['openingHours'], 'openingHours', 0, 120, 'Les horaires');
            if ($hours !== null) {
                $agency->setOpeningHours(FrenchTypography::spacing($hours));
            }
        }
        if (\array_key_exists('phone', $body)) {
            $raw = $this->text($body['phone'], 'phone', 0, 40, 'Le téléphone');
            if ($raw === '') {
                $agency->setPhone('');
            } elseif ($raw !== null) {
                $phone = self::formatPhone($raw);
                if ($phone === null) {
                    $this->errors['phone'] = 'Téléphone de l’agence : numéro camerounais (6XX XX XX XX ou 2XX XX XX XX).';
                } else {
                    $agency->setPhone($phone);
                }
            }
        }
        if (\array_key_exists('fee', $body)) {
            if ($body['fee'] === null || $body['fee'] === '') {
                $agency->setFee(null); // prix de sa ville
            } else {
                $fee = $this->fee($body['fee'], 'fee', 'Prix de l’agence : de 0 à 100 000 FCFA, ou vide pour le prix de la ville.');
                if ($fee !== null) {
                    $agency->setFee($fee);
                }
            }
        }
        if (\array_key_exists('departureCity', $body)) {
            $departure = $body['departureCity'];
            if ($departure === null || $departure === '') {
                $agency->setDepartureCity(null); // les deux boutiques
            } elseif (\is_string($departure) && ShopCity::normalize($departure) !== null) {
                $agency->setDepartureCity(ShopCity::normalize($departure));
            } else {
                $this->errors['departureCity'] = 'Boutique de départ : Yaoundé, Douala, ou les deux.';
            }
        }
        if (\array_key_exists('isActive', $body)) {
            if (\is_bool($body['isActive'])) {
                $agency->setIsActive($body['isActive']);
            } else {
                $this->errors['isActive'] = 'Indiquez si l’agence est proposée aux clients.';
            }
        }
    }

    /** @param array<string, mixed> $body */
    private function applyCityFlags(DeliveryCity $city, array $body): void
    {
        foreach (['isLocal' => 'setIsLocal', 'hasAgencies' => 'setHasAgencies', 'isActive' => 'setIsActive'] as $key => $setter) {
            if (\array_key_exists($key, $body)) {
                if (\is_bool($body[$key])) {
                    $city->$setter($body[$key]);
                } else {
                    $this->errors[$key] = 'Valeur invalide (oui ou non attendu).';
                }
            }
        }
        if (\array_key_exists('estimatedDelay', $body)) {
            $delay = $this->text($body['estimatedDelay'], 'estimatedDelay', 0, 80, 'Le délai');
            if ($delay !== null) {
                $city->setEstimatedDelay(FrenchTypography::spacing($delay));
            }
        }
    }

    private function cityOf(mixed $id): ?DeliveryCity
    {
        $city = \is_string($id) && $id !== '' ? $this->em->find(DeliveryCity::class, $id) : null;
        if ($city === null) {
            $this->errors['cityId'] = 'Choisissez la ville de l’agence dans la liste.';
        }

        return $city;
    }

    private function assertCityNameFree(string $name, ?DeliveryCity $self): void
    {
        // Comparaison sans accents ni casse (collation utf8mb4_unicode_ci).
        $other = $this->em->createQuery('SELECT c FROM ' . DeliveryCity::class . ' c WHERE c.name = :name')->setParameter('name', $name)->setMaxResults(1)->getOneOrNullResult();
        if ($other !== null && $other !== $self) {
            $this->errors['name'] = sprintf('La ville « %s » existe déjà.', $other->getName());
        }
    }

    private function assertAgencyNameFree(string $name, DeliveryCity $city, ?Agency $self): void
    {
        $other = $this->em->createQuery('SELECT a FROM ' . Agency::class . ' a WHERE a.city = :city AND a.name = :name')
            ->setParameter('city', $city)->setParameter('name', $name)->setMaxResults(1)->getOneOrNullResult();
        if ($other !== null && $other !== $self) {
            $this->errors['name'] = sprintf('L’agence « %s » existe déjà à %s.', $other->getName(), $city->getName());
        }
    }

    /** Identifiant lisible et libre (« city-kribi », « ag-douala-travel-lines »), 40 caractères au plus. */
    private function newId(string $prefix, string $slug, string $class): string
    {
        $base = substr($prefix . '-' . ($slug !== '' ? $slug : bin2hex(random_bytes(3))), 0, 36);
        $id = rtrim($base, '-');
        for ($i = 2; $this->em->find($class, $id) !== null; ++$i) {
            $id = rtrim($base, '-') . '-' . $i;
        }

        return $id;
    }

    private function nextCityPosition(): int
    {
        $max = $this->em->createQuery('SELECT MAX(c.position) FROM ' . DeliveryCity::class . ' c')->getSingleScalarResult();

        return $max === null ? 0 : (int) $max + 1;
    }

    private function nextAgencyPosition(DeliveryCity $city): int
    {
        $max = $this->em->createQuery('SELECT MAX(a.position) FROM ' . Agency::class . ' a WHERE a.city = :city')->setParameter('city', $city)->getSingleScalarResult();

        return $max === null ? 0 : (int) $max + 1;
    }

    private function fee(mixed $value, string $field, string $message): ?int
    {
        if ((!\is_int($value) && !\is_float($value)) || $value < 0 || $value > self::MAX_FEE) {
            $this->errors[$field] = $message;

            return null;
        }

        return (int) round($value);
    }

    private function text(mixed $value, string $field, int $min, int $max, string $label): ?string
    {
        if ($value !== null && !\is_string($value)) {
            $this->errors[$field] = $label . ' doit être un texte.';

            return null;
        }
        $text = TextInput::clean($value) ?? '';
        $length = TextInput::length($text);
        if ($length < $min) {
            $this->errors[$field] = $min <= 1 ? $label . ' est obligatoire.' : sprintf('%s doit faire au moins %d caractères.', $label, $min);

            return null;
        }
        if ($length > $max) {
            $this->errors[$field] = sprintf('%s ne doit pas dépasser %d caractères.', $label, $max);

            return null;
        }

        return $text;
    }

    private function throwIfInvalid(): void
    {
        if ($this->errors !== []) {
            throw ApiException::validation($this->errors, (string) reset($this->errors));
        }
    }
}
