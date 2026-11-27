<?php

namespace App\Auth\Whatsapp;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Abonnement de l'app Meta au compte WhatsApp Business (WABA) : sans lui, Meta ne transmet AUCUN message reçu à
 * l'adresse de rappel, même quand elle est vérifiée et que le champ « messages » est coché (WHATSAPP_OTP.md).
 * Lecture : GET /{WABA}/subscribed_apps ; abonnement : POST /{WABA}/subscribed_apps (app du jeton WHATSAPP_TOKEN).
 */
final class WhatsappSubscription
{
    /**
     * @return array{status: 'subscribed'|'not_subscribed'|'no_account'|'error', apps: list<string>, detail: string}
     */
    public static function read(HttpClientInterface $httpClient, WhatsappConfig $config): array
    {
        if ($config->businessAccountId === '') {
            return ['status' => 'no_account', 'apps' => [], 'detail' => 'WHATSAPP_BUSINESS_ACCOUNT_ID vide'];
        }
        if ($config->token === '') {
            return ['status' => 'error', 'apps' => [], 'detail' => 'WHATSAPP_TOKEN vide'];
        }

        try {
            $response = $httpClient->request('GET', $config->graphUrl(rawurlencode($config->businessAccountId) . '/subscribed_apps'), [
                'auth_bearer' => $config->token,
                'timeout'     => 6,
            ]);
            $data = $response->toArray(false);
            if ($response->getStatusCode() !== 200) {
                return ['status' => 'error', 'apps' => [], 'detail' => self::metaError($response->getStatusCode(), $data)];
            }
            $apps = [];
            foreach (\is_array($data['data'] ?? null) ? $data['data'] : [] as $item) {
                $app = \is_array($item) && \is_array($item['whatsapp_business_api_data'] ?? null) ? $item['whatsapp_business_api_data'] : [];
                $apps[] = sprintf('« %s »%s', mb_substr((string) ($app['name'] ?? '?'), 0, 80), isset($app['id']) ? ' n° ' . $app['id'] : '');
            }

            return ['status' => $apps === [] ? 'not_subscribed' : 'subscribed', 'apps' => $apps, 'detail' => ''];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'apps' => [], 'detail' => 'Meta injoignable : ' . mb_substr($e::class . ' : ' . $e->getMessage(), 0, 160)];
        }
    }

    /**
     * Adresse où Meta envoie les messages reçus par le numéro de la boutique (champ webhook_configuration du numéro).
     *
     * @return array{url: ?string, detail: string} url null : lecture impossible (raison dans detail)
     */
    public static function callbackUrl(HttpClientInterface $httpClient, WhatsappConfig $config): array
    {
        if ($config->token === '' || $config->phoneNumberId === '') {
            return ['url' => null, 'detail' => 'WHATSAPP_TOKEN ou WHATSAPP_PHONE_NUMBER_ID vide'];
        }

        try {
            $response = $httpClient->request('GET', $config->graphUrl(rawurlencode($config->phoneNumberId)), [
                'auth_bearer' => $config->token,
                'query'       => ['fields' => 'webhook_configuration'],
                'timeout'     => 6,
            ]);
            $data = $response->toArray(false);
            if ($response->getStatusCode() !== 200) {
                return ['url' => null, 'detail' => self::metaError($response->getStatusCode(), $data)];
            }
            $hook = \is_array($data['webhook_configuration'] ?? null) ? $data['webhook_configuration'] : [];
            // Adresse propre au numéro, puis au compte, puis à l'app (ordre de priorité de Meta)
            foreach (['phone_number', 'whatsapp_business_account', 'application'] as $level) {
                if (\is_string($hook[$level] ?? null) && $hook[$level] !== '') {
                    return ['url' => $hook[$level], 'detail' => ''];
                }
            }

            return ['url' => null, 'detail' => 'aucune adresse : aucune app ne reçoit les messages de ce numéro'];
        } catch (\Throwable $e) {
            return ['url' => null, 'detail' => 'Meta injoignable : ' . mb_substr($e::class . ' : ' . $e->getMessage(), 0, 160)];
        }
    }

    /**
     * Abonne l'app du jeton au compte WhatsApp Business.
     *
     * @return array{ok: bool, detail: string}
     */
    public static function subscribe(HttpClientInterface $httpClient, WhatsappConfig $config): array
    {
        if ($config->businessAccountId === '' || $config->token === '') {
            return ['ok' => false, 'detail' => 'WHATSAPP_BUSINESS_ACCOUNT_ID ou WHATSAPP_TOKEN vide'];
        }

        try {
            $response = $httpClient->request('POST', $config->graphUrl(rawurlencode($config->businessAccountId) . '/subscribed_apps'), [
                'auth_bearer' => $config->token,
                'timeout'     => 10,
            ]);
            $data = $response->toArray(false);
            if ($response->getStatusCode() === 200 && ($data['success'] ?? false) === true) {
                return ['ok' => true, 'detail' => 'abonnement enregistré par Meta'];
            }

            return ['ok' => false, 'detail' => self::metaError($response->getStatusCode(), $data)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'Meta injoignable : ' . mb_substr($e::class . ' : ' . $e->getMessage(), 0, 160)];
        }
    }

    /** @param array<mixed> $data */
    private static function metaError(int $status, array $data): string
    {
        $error = \is_array($data['error'] ?? null) ? $data['error'] : [];

        return sprintf('Meta répond %d, erreur %s « %s »', $status, (string) ($error['code'] ?? '?'), mb_substr((string) ($error['message'] ?? ''), 0, 200));
    }
}
