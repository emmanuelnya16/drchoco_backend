<?php

namespace App\Controller\Catalog;

use App\Api\ApiJson;
use App\Catalog\CatalogNormalizer;
use App\Entity\Agency;
use App\Entity\DeliveryCity;
use App\Repository\AgencyRepository;
use App\Repository\DeliveryCityRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Livraison : villes desservies et agences (contrat §8).
 */
final class DeliveryController
{
    public function __construct(private readonly CatalogNormalizer $normalizer) {}

    #[Route('/delivery/cities', name: 'api_delivery_cities', methods: ['GET'])]
    public function cities(Request $request, DeliveryCityRepository $cities): JsonResponse
    {
        return ApiJson::publicCached($request, array_map(
            fn (DeliveryCity $c) => $this->normalizer->deliveryCity($c),
            $cities->findVisible(),
        ));
    }

    /**
     * GET /api/delivery/agencies?city=Yaoundé — le front envoie le NOM de la ville ; l'identifiant
     * (city-yde) est accepté aussi. Ville inconnue : liste vide. Sans ville : toutes les agences.
     */
    #[Route('/delivery/agencies', name: 'api_delivery_agencies', methods: ['GET'])]
    public function agencies(Request $request, DeliveryCityRepository $cities, AgencyRepository $agencies): JsonResponse
    {
        $cityParam = trim(mb_substr((string) $request->query->get('city', ''), 0, 80));

        if ($cityParam === '') {
            $list = $agencies->findVisible();
        } else {
            $city = $cities->findVisibleByIdOrName($cityParam);
            $list = $city !== null ? $agencies->findVisible($city) : [];
        }

        return ApiJson::publicCached($request, array_map(fn (Agency $a) => $this->normalizer->agency($a), $list));
    }
}
