<?php

namespace App\Tests\Api\Admin;

use App\Entity\Admin;
use App\Tests\AdminTestCase;

/**
 * Réglages de la boutique dans l'administration (P8) : lecture, modification partielle, boutiques par ville,
 * contrôles, rôle super admin.
 */
final class SettingsAdminTest extends AdminTestCase
{
    private function owner(): string
    {
        $this->account();

        return $this->adminToken('owner@drchoco.cm');
    }

    /** @return array<string, mixed> */
    private function publicSettings(): array
    {
        return $this->getJson('/api/settings/public');
    }

    public function testTheOwnerReadsAndChangesPaymentSettings(): void
    {
        $token = $this->owner();
        $data = $this->api('GET', '/api/admin/settings', $token);
        self::assertSame(200, $this->httpStatus());
        self::assertSame($this->publicSettings(), $data['settings'], 'même forme que les réglages publics');
        $orange = $data['settings']['payment']['operators'][0];

        $data = $this->api('PATCH', '/api/admin/settings', $token, [
            'payment' => [
                'operators' => [
                    ['id' => 'orange', 'label' => 'Orange Money', 'merchantName' => '  ETS DR CHOCO  ', 'merchantCode' => '123456', 'ussdTemplate' => ' #150*14*{code}*{amount}# ', 'qrImageUrl' => '', 'steps' => ['Composez le code', '   ', ' Validez '], 'enabled' => true],
                    ['id' => 'mtn', 'merchantName' => 'DR CHOCO', 'merchantCode' => '654321', 'ussdTemplate' => null, 'enabled' => false],
                ],
                'exactAmountRequired' => false,
                'validationDelayText' => '',
                'proofMaxSizeMb'      => 12.4,
            ],
            'reservationHoldHours' => 48,
            'deliveryFeePayment'   => 'on_delivery',
            'whatsappNumber'       => '6 78 79 47 17',
        ]);
        self::assertSame(200, $this->httpStatus(), json_encode($data));
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));

        $public = $this->publicSettings();
        self::assertSame($public, $data['settings']);
        [$newOrange, $mtn] = $public['payment']['operators'];
        self::assertSame(['ETS DR CHOCO', '123456', '#150*14*{code}*{amount}#', null, ['Composez le code', 'Validez'], true], [$newOrange['merchantName'], $newOrange['merchantCode'], $newOrange['ussdTemplate'], $newOrange['qrImageUrl'], $newOrange['steps'], $newOrange['enabled']]);
        self::assertSame(['MTN MoMo', '654321', null, false], [$mtn['label'], $mtn['merchantCode'], $mtn['ussdTemplate'], $mtn['enabled']], 'libellé gardé quand il n’est pas envoyé');
        self::assertNotSame($orange['merchantCode'], $newOrange['merchantCode']);
        self::assertSame([true, "en général en moins d’une heure, de 8\u{00A0}h à 20\u{00A0}h", 12], [$public['payment']['exactAmountRequired'], $public['payment']['validationDelayText'], $public['payment']['proofMaxSizeMb']]);
        self::assertSame([48, 48, 'on_delivery', '+237678794717'], [$public['reservationHoldHours'], $public['reservationExpiryHours'], $public['deliveryFeePayment'], $public['whatsappNumber']]);
        self::assertSame(['code' => 'orange', 'name' => 'Orange Money', 'merchantCode' => '123456', 'ussdTemplate' => '#150*14*{code}*{amount}#', 'accountName' => 'ETS DR CHOCO'], $public['operators'][0], 'ancien format recalculé');

        // Modification partielle : seul le numéro change.
        $before = $this->publicSettings();
        $this->api('PATCH', '/api/admin/settings', $token, ['whatsappNumber' => '+237 690 11 22 33']);
        $after = $this->publicSettings();
        self::assertSame('+237690112233', $after['whatsappNumber']);
        $after['whatsappNumber'] = $before['whatsappNumber'];
        self::assertSame($before, $after);
        $this->api('PATCH', '/api/admin/settings', $token, []);
        self::assertSame(200, $this->httpStatus());
    }

    public function testShopsArePerCityAndStayInSyncWithTheMainShop(): void
    {
        $token = $this->owner();
        $data = $this->api('PATCH', '/api/admin/settings', $token, ['shops' => [
            ['city' => 'Douala', 'name' => 'Boutique DR Choco — Douala', 'address' => 'Akwa, rue Joss', 'openingHours' => 'Lun – Sam · 9 h à 19 h', 'mapsUrl' => 'https://maps.google.com/?q=Akwa+Douala', 'homeDeliveryFee' => 2000],
        ]]);
        self::assertSame(200, $this->httpStatus(), json_encode($data));
        $douala = $this->publicSettings()['shops'][1];
        self::assertSame(['Douala', 'Akwa, rue Joss', 2000], [$douala['city'], $douala['address'], $douala['homeDeliveryFee']]);
        self::assertSame('Mokolo, Dubai Market, Yaoundé', $this->publicSettings()['shops'][0]['address'], 'l’autre boutique ne change pas');

        // Livraison à domicile à Douala : nouveau prix pour les clients.
        $cities = array_column($this->getJson('/api/delivery/cities?from=Douala'), null, 'name');
        self::assertSame([true, 2000], [$cities['Douala']['isLocal'], $cities['Douala']['fee']]);

        // Boutique principale (ancien format) et boutique de Yaoundé : toujours les mêmes informations.
        $this->api('PATCH', '/api/admin/settings', $token, ['shop' => ['name' => 'DR Choco Mokolo', 'city' => 'Yaoundé', 'address' => 'Dubai Market, Mokolo', 'openingHours' => 'Tous les jours', 'mapsUrl' => '']]);
        $settings = $this->publicSettings();
        self::assertSame(['DR Choco Mokolo', 'Dubai Market, Mokolo', ''], [$settings['shops'][0]['name'], $settings['shops'][0]['address'], $settings['shops'][0]['mapsUrl']]);
        $this->api('PATCH', '/api/admin/settings', $token, ['shops' => [['city' => 'Yaoundé', 'name' => 'DR Choco — Yaoundé', 'homeDeliveryFee' => 1000]]]);
        $settings = $this->publicSettings();
        self::assertSame(['DR Choco — Yaoundé', 'DR Choco — Yaoundé', 1000], [$settings['shop']['name'], $settings['shops'][0]['name'], $settings['shops'][0]['homeDeliveryFee']]);
        $this->api('PATCH', '/api/admin/settings', $token, ['pickupAddress' => ['quarter' => 'Mokolo', 'landmark' => 'Dubai Market, 1er étage', 'city' => 'Yaoundé']]);
        self::assertSame('Dubai Market, 1er étage', $this->publicSettings()['pickupAddress']['landmark']);

        // Boutique principale passée à Douala : elle reprend les informations de la boutique de Douala.
        $this->api('PATCH', '/api/admin/settings', $token, ['shop' => ['city' => 'Douala']]);
        self::assertSame(200, $this->httpStatus());
        $settings = $this->publicSettings();
        self::assertSame(['Douala', 'Boutique DR Choco — Douala', 'Akwa, rue Joss'], [$settings['shop']['city'], $settings['shop']['name'], $settings['shop']['address']]);

        // Espaces insécables du français, comme dans les pages.
        $this->api('PATCH', '/api/admin/settings', $token, ['shops' => [['city' => 'Douala', 'openingHours' => 'Lun – Sam : 9 h à 19 h']]]);
        self::assertSame("Lun – Sam\u{00A0}: 9\u{00A0}h à 19\u{00A0}h", $this->publicSettings()['shops'][1]['openingHours']);
    }

    public function testInvalidValuesAreRefusedAndNothingIsSaved(): void
    {
        $token = $this->owner();
        $before = $this->publicSettings();
        $operators = $before['payment']['operators'];

        foreach ([
            'aucun opérateur actif' => [['payment' => ['operators' => [['id' => 'orange', 'enabled' => false], ['id' => 'mtn', 'enabled' => false]]]], 'payment.operators'],
            'opérateur inconnu'     => [['payment' => ['operators' => [['id' => 'wave']]]], 'payment.operators.0'],
            'code marchand vide'    => [['payment' => ['operators' => [['id' => 'orange', 'merchantCode' => '  ']]]], 'payment.operators.0.merchantCode'],
            'code USSD sans montant' => [['payment' => ['operators' => [['id' => 'orange', 'ussdTemplate' => '#150*{code}#']]]], 'payment.operators.0.ussdTemplate'],
            'code USSD avec lettres' => [['payment' => ['operators' => [['id' => 'orange', 'ussdTemplate' => '#150*{code}*{amount}#abc']]]], 'payment.operators.0.ussdTemplate'],
            'code USSD sans # final' => [['payment' => ['operators' => [['id' => 'orange', 'ussdTemplate' => '#150*14*{code}*{amount}']]]], 'payment.operators.0.ussdTemplate'],
            'code USSD sans * ni # au début' => [['payment' => ['operators' => [['id' => 'orange', 'ussdTemplate' => '150*14*{code}*{amount}#']]]], 'payment.operators.0.ussdTemplate'],
            'code marchand en lettres' => [['payment' => ['operators' => [['id' => 'orange', 'merchantCode' => 'DRCHOCO']]]], 'payment.operators.0.merchantCode'],
            'étapes en texte'       => [['payment' => ['operators' => [['id' => 'orange', 'steps' => 'Composez le code']]]], 'payment.operators.0.steps'],
            'étapes imbriquées'     => [['payment' => ['operators' => [['id' => 'orange', 'steps' => [['Composez le code']]]]]], 'payment.operators.0.steps'],
            'QR code non sûr'       => [['payment' => ['operators' => [['id' => 'orange', 'qrImageUrl' => 'javascript:alert(1)']]]], 'payment.operators.0.qrImageUrl'],
            'taille de capture'     => [['payment' => ['proofMaxSizeMb' => 30]], 'payment.proofMaxSizeMb'],
            'délai de paiement'     => [['reservationHoldHours' => 0], 'reservationHoldHours'],
            'délai en texte'        => [['reservationHoldHours' => '24'], 'reservationHoldHours'],
            'frais'                 => [['deliveryFeePayment' => 'plus_tard'], 'deliveryFeePayment'],
            'WhatsApp'              => [['whatsappNumber' => '222 22 22 22'], 'whatsappNumber'],
            'boutique inconnue'     => [['shops' => [['city' => 'Bafoussam', 'name' => 'X']]], 'shops.0.city'],
            'lien Maps http'        => [['shops' => [['city' => 'Douala', 'mapsUrl' => 'http://maps.example.com']]], 'shops.0.mapsUrl'],
            'livraison négative'    => [['shops' => [['city' => 'Douala', 'homeDeliveryFee' => -5]]], 'shops.0.homeDeliveryFee'],
            'nom de boutique vide'  => [['shop' => ['name' => '', 'city' => 'Yaoundé']], 'shop.name'],
        ] as $case => [$body, $field]) {
            $data = $this->api('PATCH', '/api/admin/settings', $token, $body + ['whatsappNumber' => '690112233']);
            self::assertSame([422, 'VALIDATION_ERROR'], [$this->httpStatus(), $data['code'] ?? null], $case);
            self::assertArrayHasKey($field, $data['details'], $case . ' : ' . json_encode($data['details']));
        }
        self::assertSame($before, $this->publicSettings(), 'rien d’enregistré (pas même le numéro valide)');
        self::assertSame($operators, $this->publicSettings()['payment']['operators']);
    }

    public function testOnlyTheSuperAdminManagesSettingsDeliveryAndPages(): void
    {
        $this->account(Admin::ROLE_SELLER, 'vendeur@drchoco.cm', 'Douala');
        $seller = $this->adminToken('vendeur@drchoco.cm');
        foreach ([
            ['GET', '/api/admin/settings', null], ['PATCH', '/api/admin/settings', ['whatsappNumber' => '690112233']],
            ['GET', '/api/admin/delivery/cities', null], ['POST', '/api/admin/delivery/cities', ['name' => 'Foumban', 'fee' => 2000]],
            ['GET', '/api/admin/delivery/agencies', null], ['DELETE', '/api/admin/delivery/agencies/ag-dla-mta', null],
            ['GET', '/api/admin/pages', null], ['PATCH', '/api/admin/pages/mentions-legales', ['title' => 'X', 'body' => 'X']],
        ] as [$method, $uri, $body]) {
            $data = $this->api($method, $uri, $seller, $body);
            self::assertSame([403, 'FORBIDDEN'], [$this->httpStatus(), $data['code'] ?? null], "$method $uri");
        }

        $this->account(Admin::ROLE_MANAGER, 'gerant@drchoco.cm');
        $this->api('GET', '/api/admin/settings', $this->adminToken('gerant@drchoco.cm'));
        self::assertSame(200, $this->httpStatus(), 'le gérant est super admin');
    }

    public function testANewMerchantCodeNeverKeepsTheOldQrCode(): void
    {
        $token = $this->owner();
        $patch = fn (array $operator): array => array_column($this->api('PATCH', '/api/admin/settings', $token, ['payment' => ['operators' => [$operator]]])['settings']['payment']['operators'], null, 'id')[$operator['id']];
        $orange = $patch(['id' => 'orange', 'qrImageUrl' => '/paiement/qr-orange.png', 'steps' => ['Composez #150*14*909935*640073363*Montant#', 'Vérifiez le code 909935.', 'Le numéro 19099350 ne change pas.']]);
        self::assertSame('/paiement/qr-orange.png', $orange['qrImageUrl']);

        // Rien de changé d'important : le QR code reste.
        self::assertSame('/paiement/qr-orange.png', $patch(['id' => 'orange', 'merchantCode' => '909935', 'label' => 'Orange Money', 'qrImageUrl' => '/paiement/qr-orange.png'])['qrImageUrl']);

        // Nouveau code sans nouvelle image : QR code retiré, étapes mises à jour.
        $orange = $patch(['id' => 'orange', 'merchantCode' => '123456', 'qrImageUrl' => '/paiement/qr-orange.png']);
        self::assertSame(['123456', null], [$orange['merchantCode'], $orange['qrImageUrl']]);
        self::assertSame(['Composez #150*14*123456*640073363*Montant#', 'Vérifiez le code 123456.', 'Le numéro 19099350 ne change pas.'], $orange['steps']);
        self::assertSame($orange, array_column($this->publicSettings()['payment']['operators'], null, 'id')['orange']);

        // Nouveau code avec sa nouvelle image : gardée. Nouveau code USSD seul : retirée aussi.
        self::assertSame('/paiement/qr-2.png', $patch(['id' => 'orange', 'merchantCode' => '654321', 'qrImageUrl' => '/paiement/qr-2.png'])['qrImageUrl']);
        self::assertNull($patch(['id' => 'orange', 'ussdTemplate' => '#150*15*{code}*{amount}#'])['qrImageUrl']);

        // Étapes écrites dans la même demande : gardées telles quelles.
        self::assertSame(['Ancien code 654321'], $patch(['id' => 'orange', 'merchantCode' => '111222', 'steps' => ['Ancien code 654321']])['steps']);

        // Une étape modifiée en même temps que le code : les autres suivent quand même le nouveau code.
        $steps = ['Composez le code 111222.', 'Vérifiez le montant.'];
        $patch(['id' => 'orange', 'steps' => $steps]);
        self::assertSame(['Composez le code 333444.', 'Vérifiez bien le montant.'], $patch(['id' => 'orange', 'merchantCode' => '333444', 'steps' => ['Composez le code 111222.', 'Vérifiez bien le montant.']])['steps']);

        // Numéro de compte écrit dans le code USSD (Orange Money) : les étapes qui le citent suivent aussi.
        $patch(['id' => 'orange', 'ussdTemplate' => '#150*14*{code}*640073363*{amount}#', 'steps' => ['Code 333444, compte 640073363.']]);
        self::assertSame(['Code 333444, compte 655001122.'], $patch(['id' => 'orange', 'ussdTemplate' => '#150*14*{code}*655001122*{amount}#'])['steps']);

        $records = $this->auditRecords('settings.payment');
        self::assertSame(['before' => '909935', 'after' => '123456'], $records[1]['data']['operators']['orange']['merchantCode']);
        self::assertSame(['before' => '/paiement/qr-orange.png', 'after' => null], $records[1]['data']['operators']['orange']['qrImageUrl']);
        $this->api('PATCH', '/api/admin/settings', $token, ['whatsappNumber' => '690112233']);
        $updated = $this->auditRecords('settings.updated');
        self::assertSame(['whatsappNumber'], end($updated)['data']['keys'] ?? null);
    }

    public function testDeliveryAndPagesAreRecorded(): void
    {
        $token = $this->owner();
        $city = $this->api('POST', '/api/admin/delivery/cities', $token, ['name' => 'Foumban', 'fee' => 2000])['city'];
        $this->api('PATCH', '/api/admin/delivery/cities/' . $city['id'], $token, ['fee' => 2500]);
        $this->api('DELETE', '/api/admin/delivery/cities/' . $city['id'], $token);
        $this->api('PATCH', '/api/admin/delivery/agencies/ag-dla-mta', $token, ['fee' => 3000]);
        $this->api('PATCH', '/api/admin/pages/mentions-legales', $token, ['title' => 'Mentions légales', 'body' => 'Nouveau texte']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['delivery.city_created', 'delivery.city_updated', 'delivery.city_deleted', 'delivery.agency_updated', 'page.updated'], array_column(array_values(array_filter($this->auditRecords(), fn (array $r) => !str_starts_with($r['event'], 'admin.'))), 'event'));
        self::assertSame(['fee' => ['before' => 2000, 'after' => 2500]], $this->auditRecords('delivery.city_updated')[0]['data']['changes']);
    }
}
