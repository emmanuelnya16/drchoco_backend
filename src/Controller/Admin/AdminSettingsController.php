<?php

namespace App\Controller\Admin;

use App\Admin\Content\PageEditor;
use App\Admin\Delivery\DeliveryEditor;
use App\Admin\Settings\SettingsEditor;
use App\Api\ApiException;
use App\Api\ApiJson;
use App\Api\JsonBody;
use App\Audit\AuditLog;
use App\Entity\Admin;
use App\Entity\Agency;
use App\Entity\DeliveryCity;
use App\Settings\PublicSettingsProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Réglages, livraison et pages dans l'administration (contrat admin §5, P8) : super admin seulement
 * (owner, manager). Double contrôle : règle d'accès de config/packages/security.yaml et vérification ici.
 *
 *  - GET/PATCH /api/admin/settings ;
 *  - /api/admin/delivery/cities et /api/admin/delivery/agencies : liste, création, modification, suppression ;
 *  - GET /api/admin/pages, GET/PATCH /api/admin/pages/{slug}.
 *
 * Les modifications sont visibles aussitôt par les clients (GET /api/settings/public, /api/delivery/*,
 * /api/pages/{slug}).
 */
final class AdminSettingsController
{
    public function __construct(
        private readonly PublicSettingsProvider $settings,
        private readonly SettingsEditor $settingsEditor,
        private readonly DeliveryEditor $delivery,
        private readonly PageEditor $pages,
        private readonly AuditLog $audit,
    ) {}

    // --- Réglages ---

    #[Route('/admin/settings', name: 'api_admin_settings', methods: ['GET'])]
    public function settings(#[CurrentUser] Admin $admin): JsonResponse
    {
        $this->assertSuperAdmin($admin);

        return ApiJson::noStore(['settings' => $this->settings->get()]);
    }

    #[Route('/admin/settings', name: 'api_admin_settings_update', methods: ['PATCH'])]
    public function updateSettings(Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $this->assertSuperAdmin($admin);

        return ApiJson::noStore(['settings' => $this->settingsEditor->update($admin, JsonBody::object($request, maxBytes: JsonBody::MAX_BYTES_EDITOR))]);
    }

    // --- Villes ---

    #[Route('/admin/delivery/cities', name: 'api_admin_cities', methods: ['GET'])]
    public function cities(#[CurrentUser] Admin $admin): JsonResponse
    {
        $this->assertSuperAdmin($admin);

        return ApiJson::noStore(['cities' => $this->cityList()]);
    }

    #[Route('/admin/delivery/cities', name: 'api_admin_cities_create', methods: ['POST'])]
    public function createCity(Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $this->assertSuperAdmin($admin);
        $city = $this->delivery->createCity(JsonBody::object($request, maxBytes: JsonBody::MAX_BYTES_EDITOR));
        $this->audit->record('delivery.city_created', $admin, $this->city($city, 0));

        return ApiJson::noStore(['city' => $this->city($city, 0), 'cities' => $this->cityList()], 201);
    }

    #[Route('/admin/delivery/cities/{id}', name: 'api_admin_cities_update', methods: ['PATCH'])]
    public function updateCity(string $id, Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $this->assertSuperAdmin($admin);
        $city = $this->delivery->city($id);
        $before = $this->city($city, 0);
        $city = $this->delivery->updateCity($city, JsonBody::object($request, maxBytes: JsonBody::MAX_BYTES_EDITOR));
        $this->audit->record('delivery.city_updated', $admin, ['city' => $id, 'changes' => AuditLog::changes($before, $this->city($city, 0))]);

        return ApiJson::noStore(['city' => $this->city($city, $this->delivery->agencyCounts()[$city->getId()] ?? 0), 'cities' => $this->cityList()]);
    }

    #[Route('/admin/delivery/cities/{id}', name: 'api_admin_cities_delete', methods: ['DELETE'])]
    public function deleteCity(string $id, #[CurrentUser] Admin $admin): Response
    {
        $this->assertSuperAdmin($admin);
        $city = $this->delivery->city($id);
        $name = $city->getName();
        $this->delivery->deleteCity($city);
        $this->audit->record('delivery.city_deleted', $admin, ['city' => $id, 'name' => $name]);

        return $this->noContent();
    }

    // --- Agences ---

    #[Route('/admin/delivery/agencies', name: 'api_admin_agencies', methods: ['GET'])]
    public function agencies(#[CurrentUser] Admin $admin): JsonResponse
    {
        $this->assertSuperAdmin($admin);

        return ApiJson::noStore(['agencies' => $this->agencyList()]);
    }

    #[Route('/admin/delivery/agencies', name: 'api_admin_agencies_create', methods: ['POST'])]
    public function createAgency(Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $this->assertSuperAdmin($admin);
        $agency = $this->delivery->createAgency(JsonBody::object($request, maxBytes: JsonBody::MAX_BYTES_EDITOR));
        $this->audit->record('delivery.agency_created', $admin, self::agency($agency));

        return ApiJson::noStore(['agency' => self::agency($agency), 'agencies' => $this->agencyList()], 201);
    }

    #[Route('/admin/delivery/agencies/{id}', name: 'api_admin_agencies_update', methods: ['PATCH'])]
    public function updateAgency(string $id, Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $this->assertSuperAdmin($admin);
        $agency = $this->delivery->agency($id);
        $before = self::agency($agency);
        $agency = $this->delivery->updateAgency($agency, JsonBody::object($request, maxBytes: JsonBody::MAX_BYTES_EDITOR));
        $this->audit->record('delivery.agency_updated', $admin, ['agency' => $id, 'changes' => AuditLog::changes($before, self::agency($agency))]);

        return ApiJson::noStore(['agency' => self::agency($agency), 'agencies' => $this->agencyList()]);
    }

    #[Route('/admin/delivery/agencies/{id}', name: 'api_admin_agencies_delete', methods: ['DELETE'])]
    public function deleteAgency(string $id, #[CurrentUser] Admin $admin): Response
    {
        $this->assertSuperAdmin($admin);
        $agency = $this->delivery->agency($id);
        $deleted = self::agency($agency);
        $this->delivery->deleteAgency($agency);
        $this->audit->record('delivery.agency_deleted', $admin, ['agency' => $id, 'name' => $deleted['name'], 'city' => $deleted['city']]);

        return $this->noContent();
    }

    // --- Pages ---

    #[Route('/admin/pages', name: 'api_admin_pages', methods: ['GET'])]
    public function pages(#[CurrentUser] Admin $admin): JsonResponse
    {
        $this->assertSuperAdmin($admin);

        return ApiJson::noStore($this->pages->list());
    }

    #[Route('/admin/pages/{slug}', name: 'api_admin_pages_show', methods: ['GET'], requirements: ['slug' => '[^/]{1,200}'])]
    public function page(string $slug, #[CurrentUser] Admin $admin): JsonResponse
    {
        $this->assertSuperAdmin($admin);

        return ApiJson::noStore($this->pages->detail($this->pages->find($slug)));
    }

    #[Route('/admin/pages/{slug}', name: 'api_admin_pages_update', methods: ['PATCH'], requirements: ['slug' => '[^/]{1,200}'])]
    public function updatePage(string $slug, Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $this->assertSuperAdmin($admin);
        $page = $this->pages->find($slug);
        $before = ['title' => $page->getTitle(), 'length' => mb_strlen($page->getBody())];
        $page = $this->pages->update($page, JsonBody::object($request, maxBytes: JsonBody::MAX_BYTES_EDITOR));
        $this->audit->record('page.updated', $admin, ['page' => $slug, 'changes' => AuditLog::changes($before, ['title' => $page->getTitle(), 'length' => mb_strlen($page->getBody())])]);

        return ApiJson::noStore($this->pages->detail($page));
    }

    /** @return list<array<string, mixed>> */
    private function cityList(): array
    {
        $counts = $this->delivery->agencyCounts();

        return array_map(fn (DeliveryCity $c) => $this->city($c, $counts[$c->getId()] ?? 0), $this->delivery->cities());
    }

    /** @return array<string, mixed> */
    private function city(DeliveryCity $c, int $agencies): array
    {
        return [
            'id'             => $c->getId(),
            'name'           => $c->getName(),
            'fee'            => $c->getFee(),
            'isLocal'        => $c->isLocal(),
            'hasAgencies'    => $c->hasAgencies(),
            'estimatedDelay' => $c->getEstimatedDelay(),
            'isActive'       => $c->isActive(),
            'position'       => $c->getPosition(),
            'agenciesCount'  => $agencies,
            'isShopCity'     => DeliveryEditor::isShopCity($c),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function agencyList(): array
    {
        return array_map(self::agency(...), $this->delivery->agencies());
    }

    /** @return array<string, mixed> */
    private static function agency(Agency $a): array
    {
        return [
            'id'            => $a->getId(),
            'cityId'        => $a->getCity()->getId(),
            'city'          => $a->getCity()->getName(),
            'name'          => $a->getName(),
            'address'       => $a->getAddress(),
            'phone'         => $a->getPhone(),
            'openingHours'  => $a->getOpeningHours(),
            'fee'           => $a->getFee(),
            'effectiveFee'  => $a->effectiveFee(),
            'departureCity' => $a->getDepartureCity(),
            'isActive'      => $a->isActive(),
            'position'      => $a->getPosition(),
        ];
    }

    private function noContent(): Response
    {
        return new Response(null, 204, ['Cache-Control' => 'no-store, private']);
    }

    private function assertSuperAdmin(Admin $admin): void
    {
        if (!$admin->isSuperAdmin()) {
            throw ApiException::forbidden('Réservé au super admin (propriétaire ou gérant).');
        }
    }
}
