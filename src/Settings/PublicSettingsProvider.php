<?php

namespace App\Settings;

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
