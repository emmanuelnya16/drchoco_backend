<?php

namespace App\Settings;

use App\Catalog\ShopCity;
use App\Entity\AppSetting;
use App\Repository\AppSettingRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Paramètres publics de la boutique (GET /settings/public, contrat §7.1) : WhatsApp, boutique de
 * retrait (Yaoundé), paiement Mobile Money (codes USSD, codes marchands, étapes).
 *
 * Source : la table app_settings (modifiable dans l'administration en P8). Tant que rien n'y est
 * enregistré, les valeurs de départ de data/seed/settings.json sont utilisées : la route ne doit
 * jamais tomber (le front affiche sinon des valeurs fictives).
 */
final class PublicSettingsProvider
{
    public function __construct(
        private readonly AppSettingRepository $settings,
        #[Autowire('%kernel.project_dir%/data/seed/settings.json')]
        private readonly string $defaultsFile,
    ) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        $stored = $this->settings->find(AppSetting::PUBLIC)?->getValue();

        return $this->normalize($stored ?? $this->defaults());
    }

    /** @return array<string, mixed> valeurs de départ (fichier de données) */
    public function defaults(): array
    {
        $data = json_decode((string) file_get_contents($this->defaultsFile), true, 64, \JSON_THROW_ON_ERROR);
        unset($data['operators']); // ancien format : recalculé à partir de payment.operators

        return $data;
    }

    /**
     * Boutique d'une ville (retrait, livraison à domicile) : {city, name, address, openingHours, mapsUrl, homeDeliveryFee}.
     *
     * @return array{city: string, name: string, address: string, openingHours: string, mapsUrl: string, homeDeliveryFee: int}
     */
    public function shop(string $city): array
    {
        foreach ($this->get()['shops'] as $shop) {
            if ($shop['city'] === $city) {
                return $shop;
            }
        }

        return $this->get()['shops'][0];
    }

    /**
     * Les deux boutiques, dans l'ordre de ShopCity::ALL. Réglages enregistrés avant la P5 (sans `shops`) :
     * valeurs de départ ; Yaoundé reprend sinon la boutique principale (`shop`).
     *
     * @param array<string, mixed> $s
     * @param array<string, mixed> $main
     *
     * @return list<array{city: string, name: string, address: string, openingHours: string, mapsUrl: string, homeDeliveryFee: int}>
     */
    private function shops(array $s, array $main): array
    {
        $rows = \is_array($s['shops'] ?? null) ? $s['shops'] : ($this->defaults()['shops'] ?? []);
        $byCity = [];
        foreach ($rows as $row) {
            $city = \is_array($row) ? ShopCity::normalize(\is_string($row['city'] ?? null) ? $row['city'] : null) : null;
            if ($city !== null && !isset($byCity[$city])) {
                $byCity[$city] = $row;
            }
        }

        $shops = [];
        foreach (ShopCity::ALL as $city) {
            $row = $byCity[$city] ?? ($city === ShopCity::YAOUNDE ? $main : []);
            $shops[] = [
                'city'            => $city,
                'name'            => (string) ($row['name'] ?? sprintf('Boutique DR Choco L’Incompris — %s', $city)),
                'address'         => (string) ($row['address'] ?? ''),
                'openingHours'    => (string) ($row['openingHours'] ?? ''),
                'mapsUrl'         => (string) ($row['mapsUrl'] ?? ''),
                'homeDeliveryFee' => max(0, (int) ($row['homeDeliveryFee'] ?? 1500)),
            ];
        }

        return $shops;
    }

    /**
     * Forme attendue par le front, avec les valeurs par défaut du contrat.
     *
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function normalize(array $s): array
    {
        $operators = [];
        foreach ((array) ($s['payment']['operators'] ?? []) as $op) {
            $operators[] = [
                'id'           => (string) ($op['id'] ?? ''),
                'label'        => (string) ($op['label'] ?? ''),
                'merchantName' => (string) ($op['merchantName'] ?? ''),
                'merchantCode' => (string) ($op['merchantCode'] ?? ''),
                // null : opérateur payable seulement par son application ; pas de QR.
                'ussdTemplate' => isset($op['ussdTemplate']) && $op['ussdTemplate'] !== '' ? (string) $op['ussdTemplate'] : null,
                'qrImageUrl'   => isset($op['qrImageUrl']) && $op['qrImageUrl'] !== '' ? (string) $op['qrImageUrl'] : null,
                'steps'        => array_values(array_map('strval', (array) ($op['steps'] ?? []))),
                'enabled'      => (bool) ($op['enabled'] ?? true),
            ];
        }

        $holdHours = (int) ($s['reservationHoldHours'] ?? $s['reservationExpiryHours'] ?? 24);
        $shop = (array) ($s['shop'] ?? []);
        $pickup = (array) ($s['pickupAddress'] ?? []);

        return [
            'whatsappNumber'         => (string) ($s['whatsappNumber'] ?? ''),
            'deliveryFeePayment'     => ($s['deliveryFeePayment'] ?? 'upfront') === 'on_delivery' ? 'on_delivery' : 'upfront',
            'reservationHoldHours'   => $holdHours,
            'reservationExpiryHours' => $holdHours,
            'shop' => [
                'name'         => (string) ($shop['name'] ?? ''),
                'address'      => (string) ($shop['address'] ?? ''),
                'city'         => (string) ($shop['city'] ?? ''),
                'openingHours' => (string) ($shop['openingHours'] ?? ''),
                'mapsUrl'      => (string) ($shop['mapsUrl'] ?? ''),
            ],
            'payment' => [
                'operators'           => $operators,
                'exactAmountRequired' => true,
                'validationDelayText' => (string) ($s['payment']['validationDelayText'] ?? ''),
                'proofMaxSizeMb'      => (int) ($s['payment']['proofMaxSizeMb'] ?? 10),
            ],
            'pickupAddress' => [
                'name'         => (string) ($pickup['name'] ?? $shop['name'] ?? ''),
                'city'         => (string) ($pickup['city'] ?? $shop['city'] ?? ''),
                'quarter'      => (string) ($pickup['quarter'] ?? ''),
                'landmark'     => (string) ($pickup['landmark'] ?? ''),
                'openingHours' => (string) ($pickup['openingHours'] ?? $shop['openingHours'] ?? ''),
            ],
            // Une boutique par ville (P5) : retrait en boutique et livraison à domicile dans sa ville.
            'shops' => $this->shops($s, $shop),
            // Ancien format (M1/M2), plus lu par l'app mais encore décrit par son type.
            'operators' => array_map(fn (array $op) => array_filter([
                'code'          => $op['id'],
                'name'          => $op['label'],
                'merchantCode'  => $op['merchantCode'],
                'ussdTemplate'  => (string) $op['ussdTemplate'],
                'accountName'   => $op['merchantName'],
                'qrCodeUrl'     => $op['qrImageUrl'],
            ], fn ($v) => $v !== null), $operators),
        ];
    }
}
