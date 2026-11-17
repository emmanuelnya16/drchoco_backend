<?php

namespace App\Controller\Catalog;

use App\Api\ApiJson;
use App\Catalog\CatalogNormalizer;
use App\Entity\Agency;
use App\Repository\DeliveryCityRepository;
use App\Reservation\DeliveryOptions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Livraison : villes desservies et agences (contrat §8), selon la boutique de départ (`from`, P5) :
 * domicile dans la ville de la boutique, agence (avec son prix) ailleurs. Sans `from` : Yaoundé.
 */
final class DeliveryController
{
    public function __construct(
        private readonly CatalogNormalizer $normalizer,
        private readonly DeliveryOptions $options,
    ) {}

    /** GET /api/delivery/cities?from=Douala */
    #[Route('/delivery/cities', name: 'api_delivery_cities', methods: ['GET'])]
    public function cities(Request $request): JsonResponse
    {
        $from = $request->query->get('from');

        return ApiJson::publicCached($request, $this->options->publicCities(DeliveryOptions::origin($from), trim((string) $from) !== ''));
    }

    /**
     * GET /api/delivery/agencies?city=Yaoundé&from=Douala — le front envoie le NOM de la ville ; l'identifiant
     * (city-yde) est accepté aussi. Ville inconnue : liste vide. Sans ville : toutes les agences.
     * Avec `from` : seulement les agences qui desservent cette boutique. Chaque agence a son prix (`fee`).
     */
    #[Route('/delivery/agencies', name: 'api_delivery_agencies', methods: ['GET'])]
    public function agencies(Request $request, DeliveryCityRepository $cities): JsonResponse
    {
        $cityParam = trim(mb_substr((string) $request->query->get('city', ''), 0, 80));
        $from = trim((string) $request->query->get('from', '')) !== '' ? DeliveryOptions::origin($request->query->get('from')) : null;

        if ($cityParam === '') {
            $list = $this->options->agencies(null, $from);
        } else {
            $city = $cities->findVisibleByIdOrName($cityParam);
            $list = $city !== null ? $this->options->agencies($city, $from) : [];
        }

        return ApiJson::publicCached($request, array_map(fn (Agency $a) => $this->normalizer->agency($a), $list));
    }
}
