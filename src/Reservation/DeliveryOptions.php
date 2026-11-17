<?php

namespace App\Reservation;

use App\Catalog\CatalogNormalizer;
use App\Catalog\ShopCity;
use App\Entity\Agency;
use App\Entity\DeliveryCity;
use App\Repository\AgencyRepository;
use App\Repository\DeliveryCityRepository;
use App\Settings\PublicSettingsProvider;

/**
 * Livraison selon la boutique de départ (décisions du 2026-11-16) :
 * - dans la ville de la boutique : livraison à domicile (quartier + repère), au prix fixé pour cette boutique ;
 * - ailleurs : envoi par une agence choisie par le client, au prix de cette agence ; seules les agences
 *   qui desservent cette boutique sont proposées.
 * Sans boutique indiquée : Yaoundé (boutique principale), comme avant la P5.
 */
final class DeliveryOptions
{
    public function __construct(
        private readonly DeliveryCityRepository $cities,
        private readonly AgencyRepository $agencies,
        private readonly PublicSettingsProvider $settings,
        private readonly CatalogNormalizer $normalizer,
    ) {}

    public static function origin(mixed $raw): string
    {
        return ShopCity::normalize(\is_string($raw) ? $raw : null) ?? ShopCity::YAOUNDE;
    }

    /** Ville de la boutique elle-même : livraison à domicile. */
    public static function isHomeDelivery(DeliveryCity $city, string $shopCity): bool
    {
        return ShopCity::normalize($city->getName()) === $shopCity;
    }

    public function homeDeliveryFee(string $shopCity): int
    {
        return $this->settings->shop($shopCity)['homeDeliveryFee'];
    }

    /**
     * GET /delivery/cities?from= : même forme qu'avant, calculée pour la boutique de départ.
     * `$reachableOnly` (boutique précisée : réservation, fiche article) : seulement les villes où le colis peut
     * vraiment arriver (livraison à domicile, ou au moins une agence qui part de cette boutique) ; sans boutique
     * (profil, adresses), toutes les villes.
     *
     * @return list<array<string, mixed>>
     */
    public function publicCities(string $from, bool $reachableOnly = false): array
    {
        $byCity = [];
        foreach ($this->agencies->findVisible() as $agency) {
            if ($agency->servesFrom($from)) {
                $byCity[$agency->getCity()->getId()][] = $agency->effectiveFee();
            }
        }

        $list = [];
        foreach ($this->cities->findVisible() as $city) {
            $row = $this->normalizer->deliveryCity($city);
            if (self::isHomeDelivery($city, $from)) {
                $row['fee'] = $this->homeDeliveryFee($from);
                $row['isLocal'] = true;
                $row['hasAgencies'] = false;
            } else {
                $fees = $byCity[$city->getId()] ?? [];
                if ($fees === [] && $reachableOnly) {
                    continue;
                }
                $row['fee'] = $fees !== [] ? min($fees) : $city->getFee(); // « à partir de »
                $row['isLocal'] = false;
                $row['hasAgencies'] = $fees !== [];
            }
            $list[] = $row;
        }

        return $list;
    }

    /**
     * Agences actives d'une ville (toutes sans ville), limitées à celles qui desservent la boutique `from`.
     *
     * @return list<Agency>
     */
    public function agencies(?DeliveryCity $city, ?string $from): array
    {
        $list = $this->agencies->findVisible($city);
        if ($from === null) {
            return $list;
        }

        return array_values(array_filter($list, fn (Agency $a) => $a->servesFrom($from)));
    }
}
