<?php

namespace App\Admin\Settings;

use App\Account\PhoneNumber;
use App\Account\TextInput;
use App\Admin\CatalogInput;
use App\Admin\Content\FrenchTypography;
use App\Api\ApiException;
use App\Audit\AuditLog;
use App\Catalog\ShopCity;
use App\Entity\Admin;
use App\Entity\AppSetting;
use App\Repository\AppSettingRepository;
use App\Settings\PublicSettingsProvider;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * PATCH /api/admin/settings (P8) : mise à jour partielle des réglages publics (paiement Mobile Money, règles de
 * réservation, boutiques, WhatsApp). Seules les clés envoyées changent ; tout est vérifié avant d'enregistrer
 * (aucun enregistrement partiel). Ces réglages pilotent l'encaissement : chaque modification est journalisée, et un
 * nouveau code marchand retire l'ancien QR code.
 */
final class SettingsEditor
{
    public const OPERATORS = ['orange' => 'Orange Money', 'mtn' => 'MTN MoMo'];
    public const DEFAULT_VALIDATION_DELAY = 'en général en moins d’une heure, de 8 h à 20 h';

    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(
        private readonly PublicSettingsProvider $provider,
        private readonly AppSettingRepository $settings,
        private readonly EntityManagerInterface $em,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> réglages enregistrés (forme publique)
     */
    public function update(Admin $admin, array $body): array
    {
        $this->errors = [];
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            // Ligne relue sous verrou : deux enregistrements simultanés (onglets Paiement et Boutique) ne
            // s'écrasent pas ; le second part des valeurs du premier.
            $setting = $this->settings->find(AppSetting::PUBLIC, LockMode::PESSIMISTIC_WRITE);
            if ($setting !== null) {
                $this->em->refresh($setting); // valeur à jour, même si la ligne était déjà chargée
            } else {
                $setting = new AppSetting(AppSetting::PUBLIC);
            }
            $next = $this->apply($body);
            $current = $this->current;
            $setting->setValue($next);
            $this->em->persist($setting);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $this->audit($admin, $current, $next, array_keys($body));

        return $this->provider->get();
    }

    /** @var array<string, mixed> réglages lus avant la modification */
    private array $current = [];

    /**
     * Nouvelles valeurs (toutes vérifiées) ; 422 si un champ est invalide.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function apply(array $body): array
    {
        $current = $this->provider->get();
        $this->current = $current;
        $next = $current;
        unset($next['operators'], $next['reservationExpiryHours']); // recalculés à la lecture

        if (\array_key_exists('payment', $body)) {
            $this->payment($next, $body['payment']);
        }
        if (\array_key_exists('reservationHoldHours', $body)) {
            $hours = $this->integer($body['reservationHoldHours'], 'reservationHoldHours', 1, 72, 'Le délai de paiement doit être compris entre 1 et 72 heures.');
            if ($hours !== null) {
                $next['reservationHoldHours'] = $hours;
            }
        }
        if (\array_key_exists('deliveryFeePayment', $body)) {
            if (\in_array($body['deliveryFeePayment'], ['upfront', 'on_delivery'], true)) {
                $next['deliveryFeePayment'] = $body['deliveryFeePayment'];
            } else {
                $this->errors['deliveryFeePayment'] = 'Choisissez quand les frais de livraison sont payés.';
            }
        }
        if (\array_key_exists('whatsappNumber', $body)) {
            $phone = PhoneNumber::normalizeCameroonMobile($body['whatsappNumber']);
            if ($phone === null) {
                $this->errors['whatsappNumber'] = 'Numéro WhatsApp invalide : un mobile camerounais (6XX XX XX XX).';
            } else {
                $next['whatsappNumber'] = $phone;
            }
        }
        if (\array_key_exists('shop', $body)) {
            $this->legacyShop($next, $body['shop']);
        }
        if (\array_key_exists('pickupAddress', $body)) {
            $this->pickupAddress($next, $body['pickupAddress']);
        }
        if (\array_key_exists('shops', $body)) {
            $this->shops($next, $body['shops']);
        }

        if ($this->errors !== []) {
            throw ApiException::validation($this->errors, (string) reset($this->errors));
        }

        return $next;
    }

    /** @param array<string, mixed> $next */
    private function payment(array &$next, mixed $payment): void
    {
        if (!\is_array($payment)) {
            $this->errors['payment'] = 'Réglages de paiement invalides.';

            return;
        }

        if (\array_key_exists('operators', $payment)) {
            $byId = [];
            foreach ($next['payment']['operators'] as $op) {
                $byId[$op['id']] = $op;
            }
            $seen = [];
            foreach (\is_array($payment['operators']) && array_is_list($payment['operators']) ? $payment['operators'] : [null] as $i => $op) {
                $id = \is_array($op) ? ($op['id'] ?? null) : null;
                if (!\is_string($id) || !isset(self::OPERATORS[$id]) || isset($seen[$id])) {
                    $this->errors["payment.operators.$i"] = 'Opérateur inconnu : Orange Money ou MTN MoMo, une fois chacun.';
                    continue;
                }
                $seen[$id] = true;
                $byId[$id] = $this->operator($op, $byId[$id] ?? ['id' => $id], "payment.operators.$i");
            }
            $operators = [];
            foreach (array_keys(self::OPERATORS) as $id) {
                if (isset($byId[$id])) {
                    $operators[] = $byId[$id];
                }
            }
            if ($operators === [] || array_filter($operators, fn (array $op) => $op['enabled']) === []) {
                $this->errors['payment.operators'] = 'Activez au moins un opérateur Mobile Money.';
            }
            $next['payment']['operators'] = $operators;
        }

        if (\array_key_exists('validationDelayText', $payment)) {
            $text = $this->text($payment['validationDelayText'], 'payment.validationDelayText', 0, 200, 'Le délai de vérification');
            $next['payment']['validationDelayText'] = FrenchTypography::spacing($text !== null && $text !== '' ? $text : self::DEFAULT_VALIDATION_DELAY);
        }
        if (\array_key_exists('proofMaxSizeMb', $payment)) {
            $size = $this->integer($payment['proofMaxSizeMb'], 'payment.proofMaxSizeMb', 1, 25, 'La taille maximale d’une capture doit être comprise entre 1 et 25 Mo.');
            $serverMax = self::serverUploadLimitMb();
            if ($size !== null && $size > $serverMax) {
                $this->errors['payment.proofMaxSizeMb'] = sprintf('Le serveur accepte des envois de %d Mo au plus : choisissez une taille inférieure.', $serverMax);
            } elseif ($size !== null) {
                $next['payment']['proofMaxSizeMb'] = $size;
            }
        }
        $next['payment']['exactAmountRequired'] = true;
    }

    /**
     * @param array<string, mixed> $current
     *
     * @return array<string, mixed>
     */
    private function operator(array $op, array $current, string $field): array
    {
        $id = $op['id'];
        $result = $current + ['label' => self::OPERATORS[$id], 'merchantName' => '', 'merchantCode' => '', 'ussdTemplate' => null, 'qrImageUrl' => null, 'steps' => [], 'enabled' => true];
        $result['id'] = $id;

        if (\array_key_exists('label', $op)) {
            $result['label'] = $this->text($op['label'], "$field.label", 1, 60, 'Le nom de l’opérateur') ?? $result['label'];
        }
        if (\array_key_exists('merchantName', $op) || $result['merchantName'] === '') {
            $result['merchantName'] = $this->text($op['merchantName'] ?? '', "$field.merchantName", 1, 120, sprintf('Le nom du compte marchand %s', self::OPERATORS[$id])) ?? $result['merchantName'];
        }
        if (\array_key_exists('merchantCode', $op) || $result['merchantCode'] === '') {
            $code = $this->text($op['merchantCode'] ?? '', "$field.merchantCode", 1, 40, sprintf('Le code marchand %s', self::OPERATORS[$id]));
            if ($code !== null && preg_match('/^[0-9A-Za-z -]+$/', $code) !== 1) {
                $this->errors["$field.merchantCode"] = sprintf('Code marchand %s : chiffres et lettres seulement.', self::OPERATORS[$id]);
            } elseif ($code !== null) {
                $result['merchantCode'] = $code;
            }
        }
        if (\array_key_exists('ussdTemplate', $op)) {
            $template = \is_string($op['ussdTemplate']) ? preg_replace('/\s+/', '', $op['ussdTemplate']) : $op['ussdTemplate'];
            if ($template === null || $template === '') {
                $result['ussdTemplate'] = null;
            } elseif (!\is_string($template) || \strlen($template) > 120 || !str_contains($template, '{code}') || !str_contains($template, '{amount}')
                || preg_match('/^[*#][0-9*#]*#$/', str_replace(['{code}', '{amount}'], '0', $template)) !== 1) {
                $this->errors["$field.ussdTemplate"] = sprintf('Code USSD %s : commence par « * » ou « # », finit par « # », chiffres et {code} et {amount} (ex. #150*14*{code}*{amount}#).', self::OPERATORS[$id]);
            } else {
                $result['ussdTemplate'] = $template;
            }
        }
        // Code composé par le téléphone : le code marchand qui y est inséré ne contient que des chiffres.
        if ((\array_key_exists('merchantCode', $op) || \array_key_exists('ussdTemplate', $op))
            && $result['ussdTemplate'] !== null && !isset($this->errors["$field.merchantCode"], $this->errors["$field.ussdTemplate"]) && preg_match('/^\d+$/', (string) $result['merchantCode']) !== 1) {
            $this->errors["$field.merchantCode"] = sprintf('Code marchand %s : chiffres seulement (il est composé dans le code USSD).', self::OPERATORS[$id]);
        }
        if (\array_key_exists('qrImageUrl', $op)) {
            $url = \is_string($op['qrImageUrl']) ? trim($op['qrImageUrl']) : $op['qrImageUrl'];
            if ($url === null || $url === '') {
                $result['qrImageUrl'] = null;
            } elseif (!\is_string($url) || !CatalogInput::isImageUrl($url)) {
                $this->errors["$field.qrImageUrl"] = sprintf('Image du QR code %s : adresse https:// ou chemin du site (/…).', self::OPERATORS[$id]);
            } else {
                $result['qrImageUrl'] = $url;
            }
        }
        if (\array_key_exists('steps', $op)) {
            $steps = [];
            $valid = \is_array($op['steps']) && array_is_list($op['steps']);
            foreach ($valid ? $op['steps'] : [] as $step) {
                if (!\is_string($step)) {
                    $valid = false;
                    break;
                }
                $step = TextInput::clean($step) ?? '';
                if ($step !== '') {
                    $steps[] = FrenchTypography::spacing($step);
                }
            }
            if (!$valid) {
                $this->errors["$field.steps"] = sprintf('Étapes %s : une liste de textes.', self::OPERATORS[$id]);
            } elseif (\count($steps) > 10 || array_filter($steps, fn (string $s) => TextInput::length($s) > 300) !== []) {
                $this->errors["$field.steps"] = sprintf('Étapes %s : 10 au plus, de 300 caractères chacune au plus.', self::OPERATORS[$id]);
            } else {
                $result['steps'] = $steps;
            }
        }
        if ((string) ($current['merchantCode'] ?? '') !== '') {
            $this->followNewCode($op, $current, $result);
        }
        if (\array_key_exists('enabled', $op)) {
            if (\is_bool($op['enabled'])) {
                $result['enabled'] = $op['enabled'];
            } else {
                $this->errors["$field.enabled"] = 'Indiquez si l’opérateur est actif.';
            }
        }

        return $result;
    }

    /**
     * Compte marchand changé : l'ancien QR code enverrait encore l'argent à l'ancien compte. Sans nouvelle image dans la
     * même demande, il est retiré. Chaque étape non modifiée qui cite l'ancien code, ou l'ancien numéro de compte écrit dans
     * le code USSD (Orange Money), cite le nouveau.
     *
     * @param array<string, mixed> $op      champs envoyés
     * @param array<string, mixed> $current opérateur enregistré
     * @param array<string, mixed> $result  opérateur à enregistrer
     */
    private function followNewCode(array $op, array $current, array &$result): void
    {
        $compact = static fn (mixed $v): string => (string) preg_replace('/[\s\-]+/u', '', (string) $v);
        $codeChanged = $compact($result['merchantCode']) !== $compact($current['merchantCode']);
        $ussdChanged = $compact($result['ussdTemplate'] ?? '') !== $compact($current['ussdTemplate'] ?? '');
        if (!$codeChanged && !$ussdChanged) {
            return;
        }
        if ($result['qrImageUrl'] === ($current['qrImageUrl'] ?? null)) {
            $result['qrImageUrl'] = null;
        }

        $replacements = [];
        if ($codeChanged && \strlen((string) $current['merchantCode']) >= 4) {
            $replacements[(string) $current['merchantCode']] = (string) $result['merchantCode'];
        }
        $numbers = static function (mixed $template): array {
            preg_match_all('/\d{6,}/', (string) $template, $found);

            return array_values(array_unique($found[0]));
        };
        $removed = array_values(array_diff($numbers($current['ussdTemplate'] ?? ''), $numbers($result['ussdTemplate'] ?? '')));
        $added = array_values(array_diff($numbers($result['ussdTemplate'] ?? ''), $numbers($current['ussdTemplate'] ?? '')));
        if (\count($removed) === 1 && \count($added) === 1) {
            $replacements += [$removed[0] => $added[0]];
        }
        if ($replacements === []) {
            return;
        }
        $old = implode('|', array_map(static fn (int|string $k): string => preg_quote((string) $k, '/'), array_keys($replacements)));
        $kept = $current['steps'] ?? [];
        $result['steps'] = array_map(
            static fn (string $step): string => \in_array($step, $kept, true)
                ? (string) preg_replace_callback('/(?<![0-9A-Za-z])(?:' . $old . ')(?![0-9A-Za-z])/u', static fn (array $m): string => (string) $replacements[$m[0]], $step)
                : $step, // étape écrite dans cette demande : gardée telle quelle
            $result['steps'],
        );
    }

    /** Ancienne boutique principale (`shop`) : met aussi à jour la boutique de sa ville dans `shops`. @param array<string, mixed> $next */
    private function legacyShop(array &$next, mixed $shop): void
    {
        if (!\is_array($shop)) {
            $this->errors['shop'] = 'Boutique invalide.';

            return;
        }
        $city = \array_key_exists('city', $shop) ? ShopCity::normalize(\is_string($shop['city']) ? $shop['city'] : null) : ShopCity::normalize($next['shop']['city'] ?? null);
        if ($city === null) {
            $this->errors['shop.city'] = 'Ville de la boutique : Yaoundé ou Douala.';

            return;
        }
        $fields = $this->shopFields($shop, 'shop');
        $base = $next['shop'];
        if ($city !== ShopCity::normalize($next['shop']['city'] ?? null)) {
            // Autre ville : les champs non envoyés viennent de la boutique de cette ville.
            foreach ($next['shops'] as $entry) {
                if ($entry['city'] === $city) {
                    $base = array_intersect_key($entry, array_flip(['name', 'address', 'openingHours', 'mapsUrl']));
                }
            }
        }
        $next['shop'] = ['city' => $city] + $fields + $base;
        $next['shops'] = $this->mergeShop($next['shops'], $city, $fields);
    }

    /** Boutiques par ville : fusion par ville ; la boutique principale (`shop`) suit la sienne. @param array<string, mixed> $next */
    private function shops(array &$next, mixed $shops): void
    {
        if (!\is_array($shops) || !array_is_list($shops)) {
            $this->errors['shops'] = 'Boutiques invalides.';

            return;
        }
        $seen = [];
        foreach ($shops as $i => $shop) {
            $city = \is_array($shop) ? ShopCity::normalize(\is_string($shop['city'] ?? null) ? $shop['city'] : null) : null;
            if ($city === null || isset($seen[$city])) {
                $this->errors["shops.$i.city"] = 'Boutique inconnue : Yaoundé ou Douala, une fois chacune.';
                continue;
            }
            $seen[$city] = true;
            $fields = $this->shopFields($shop, "shops.$i");
            if (\array_key_exists('homeDeliveryFee', $shop)) {
                $fee = $this->integer($shop['homeDeliveryFee'], "shops.$i.homeDeliveryFee", 0, 100000, sprintf('Prix de la livraison à domicile à %s : de 0 à 100 000 FCFA.', $city));
                if ($fee !== null) {
                    $fields['homeDeliveryFee'] = $fee;
                }
            }
            $next['shops'] = $this->mergeShop($next['shops'], $city, $fields);
            if (ShopCity::normalize($next['shop']['city'] ?? null) === $city) {
                $next['shop'] = array_intersect_key($fields, array_flip(['name', 'address', 'openingHours', 'mapsUrl'])) + $next['shop'];
            }
        }
    }

    /**
     * @param array<string, mixed> $shop
     *
     * @return array<string, string>
     */
    private function shopFields(array $shop, string $field): array
    {
        $fields = [];
        if (\array_key_exists('name', $shop)) {
            $name = $this->text($shop['name'], "$field.name", 1, 120, 'Le nom de la boutique');
            if ($name !== null) {
                $fields['name'] = FrenchTypography::spacing($name);
            }
        }
        foreach (['address' => [255, 'L’adresse'], 'openingHours' => [120, 'Les horaires']] as $key => [$max, $label]) {
            if (\array_key_exists($key, $shop)) {
                $value = $this->text($shop[$key], "$field.$key", 0, $max, $label);
                if ($value !== null) {
                    $fields[$key] = FrenchTypography::spacing($value);
                }
            }
        }
        if (\array_key_exists('mapsUrl', $shop)) {
            $url = \is_string($shop['mapsUrl']) ? trim($shop['mapsUrl']) : null;
            if ($url === null || ($url !== '' && (!str_starts_with($url, 'https://') || \strlen($url) > 500 || filter_var($url, \FILTER_VALIDATE_URL) === false))) {
                $this->errors["$field.mapsUrl"] = 'Lien Google Maps : une adresse https://.';
            } else {
                $fields['mapsUrl'] = $url;
            }
        }

        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $shops
     * @param array<string, mixed>       $fields
     *
     * @return list<array<string, mixed>>
     */
    private function mergeShop(array $shops, string $city, array $fields): array
    {
        foreach ($shops as $i => $shop) {
            if ($shop['city'] === $city) {
                $shops[$i] = $fields + $shop;
            }
        }

        return $shops;
    }

    /** @param array<string, mixed> $next */
    private function pickupAddress(array &$next, mixed $pickup): void
    {
        if (!\is_array($pickup)) {
            $this->errors['pickupAddress'] = 'Adresse de retrait invalide.';

            return;
        }
        foreach (['name' => [0, 120, 'Le nom du point de retrait'], 'quarter' => [0, 120, 'Le quartier'], 'landmark' => [0, 255, 'Le point de repère'], 'openingHours' => [0, 120, 'Les horaires']] as $key => [$min, $max, $label]) {
            if (\array_key_exists($key, $pickup)) {
                $value = $this->text($pickup[$key], "pickupAddress.$key", $min, $max, $label);
                if ($value !== null) {
                    $next['pickupAddress'][$key] = FrenchTypography::spacing($value);
                }
            }
        }
        if (\array_key_exists('city', $pickup)) {
            $city = ShopCity::normalize(\is_string($pickup['city']) ? $pickup['city'] : null);
            if ($city === null) {
                $this->errors['pickupAddress.city'] = 'Ville du retrait : Yaoundé ou Douala.';
            } else {
                $next['pickupAddress']['city'] = $city;
            }
        }
    }

    /** Plus petite limite d'envoi de PHP (upload_max_filesize, post_max_size), en Mo, avec 1 Mo de marge. */
    public static function serverUploadLimitMb(): int
    {
        $bytes = fn (string $v): int => match (strtolower(substr(trim($v), -1))) {
            'g'     => (int) $v * 1024 ** 3,
            'm'     => (int) $v * 1024 ** 2,
            'k'     => (int) $v * 1024,
            default => (int) $v,
        };
        $limits = array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))], fn (int $b) => $b > 0);

        return $limits === [] ? 25 : max(1, intdiv(min($limits), 1024 ** 2) - 1);
    }

    /** Texte nettoyé ; null si refusé (erreur enregistrée). */
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

    /** Nombre entier (les décimales sont arrondies) dans l'intervalle ; null si refusé. */
    private function integer(mixed $value, string $field, int $min, int $max, string $message): ?int
    {
        if (!\is_int($value) && !\is_float($value)) {
            $this->errors[$field] = $message;

            return null;
        }
        $number = (int) round($value);
        if ($number < $min || $number > $max) {
            $this->errors[$field] = $message;

            return null;
        }

        return $number;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param list<string>         $keys
     */
    private function audit(Admin $admin, array $before, array $after, array $keys): void
    {
        // Tout ce qui dirige l'argent du client : compte, code, code USSD, QR code, étapes, opérateur proposé ou non.
        $fields = array_flip(['id', 'merchantName', 'accountName', 'merchantCode', 'ussdTemplate', 'qrImageUrl', 'qrCodeUrl', 'steps', 'enabled']);
        $operators = static function (array $settings) use ($fields): array {
            $list = [];
            foreach ($settings['payment']['operators'] ?? [] as $operator) {
                $list[(string) ($operator['id'] ?? '')] = array_intersect_key($operator, $fields);
            }

            return $list;
        };
        $was = $operators($before);
        $is = $operators($after);
        $changed = [];
        foreach ($is as $id => $operator) {
            foreach ($operator as $field => $value) {
                if (($was[$id][$field] ?? null) !== $value) {
                    $changed[$id][$field] = ['before' => $was[$id][$field] ?? null, 'after' => $value];
                }
            }
        }

        $this->audit->record($changed !== [] ? 'settings.payment' : 'settings.updated', $admin, ['keys' => $keys] + ($changed !== [] ? ['operators' => $changed] : []));
    }
}
