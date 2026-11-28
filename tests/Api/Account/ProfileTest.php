<?php

namespace App\Tests\Api\Account;

use App\Tests\AccountTestCase;

/**
 * GET /api/me et PATCH /api/me (contrat §5.1, §5.2), limite de débit des écritures (§2.14).
 */
final class ProfileTest extends AccountTestCase
{
    public function testMeRequiresAToken(): void
    {
        $data = $this->api('GET', '/api/me', null);
        self::assertSame(401, $this->httpStatus());
        self::assertSame('UNAUTHORIZED', $data['code']);

        $this->api('GET', '/api/me', 'jeton.invalide.xyz');
        self::assertSame(401, $this->httpStatus());
    }

    public function testMeReturnsTheProfileAsAPrivateRevalidatedResponse(): void
    {
        $token = $this->login()['token'];
        $data = $this->api('GET', '/api/me', $token);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['id', 'phone', 'createdAt', 'coins'], array_keys($data));
        $cacheControl = (string) $this->header('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-cache', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
        // Pas de « Vary: Authorization » : le service worker doit retrouver sa copie hors connexion.
        self::assertNotContains('Authorization', $this->client->getResponse()->getVary());
    }

    public function testPatchOnlyChangesTheFieldsSent(): void
    {
        $token = $this->login()['token'];

        $data = $this->api('PATCH', '/api/me', $token, ['firstName' => '  Junior  ']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('Junior', $data['firstName']);
        self::assertArrayNotHasKey('lastName', $data);
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));

        // Ville sans accent ni majuscule : enregistrée sous son nom officiel.
        $data = $this->api('PATCH', '/api/me', $token, ['lastName' => 'Mbarga', 'city' => 'yaounde']);
        self::assertSame(['Junior', 'Mbarga', 'Yaoundé'], [$data['firstName'], $data['lastName'], $data['city']]);

        // Nom vidé : effacé (omis dans la réponse).
        $data = $this->api('PATCH', '/api/me', $token, ['lastName' => '']);
        self::assertArrayNotHasKey('lastName', $data);
        self::assertSame('Junior', $data['firstName']);

        self::assertSame('Yaoundé', $this->api('GET', '/api/me', $token)['city']);
    }

    public function testPatchValidation(): void
    {
        $token = $this->login()['token'];
        $this->api('PATCH', '/api/me', $token, ['firstName' => 'Junior', 'city' => 'Douala']);

        $data = $this->api('PATCH', '/api/me', $token, ['firstName' => '   ', 'lastName' => str_repeat('a', 41), 'city' => 'Paris']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('VALIDATION_ERROR', $data['code']);
        self::assertSame(['firstName', 'lastName', 'city'], array_keys($data['details']));

        $this->api('PATCH', '/api/me', $token, ['firstName' => str_repeat('é', 41)]);
        self::assertSame(422, $this->httpStatus());
        $this->api('PATCH', '/api/me', $token, '[1,2]');
        self::assertSame(422, $this->httpStatus());

        // Rien n'a changé.
        $me = $this->api('GET', '/api/me', $token);
        self::assertSame(['Junior', 'Douala'], [$me['firstName'], $me['city']]);
    }

    public function testClientWritesAreLimitedToSixtyPerMinute(): void
    {
        $token = $this->login()['token'];
        for ($i = 0; $i < 60; ++$i) {
            $this->api('PATCH', '/api/me', $token, ['firstName' => 'Junior']);
            self::assertSame(200, $this->httpStatus(), "écriture n°$i");
        }

        $data = $this->api('PATCH', '/api/me', $token, ['firstName' => 'Junior']);
        self::assertSame(429, $this->httpStatus());
        self::assertSame('RATE_LIMITED', $data['code']);
        self::assertNotNull($this->header('Retry-After'));

        // Les lectures ne sont pas limitées.
        $this->api('GET', '/api/me', $token);
        self::assertSame(200, $this->httpStatus());
    }

    public function testUnchangedCityIsKeptEvenIfNoLongerDelivered(): void
    {
        $token = $this->login()['token'];
        $this->api('PATCH', '/api/me', $token, ['firstName' => 'Junior', 'city' => 'Bafoussam']);
        $this->em()->getConnection()->executeStatement("UPDATE delivery_cities SET is_active = 0 WHERE name = 'Bafoussam'");

        // La page Profil renvoie les trois champs : corriger le prénom reste possible.
        $data = $this->api('PATCH', '/api/me', $token, ['firstName' => 'Junior Paul', 'lastName' => '', 'city' => 'bafoussam']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['Junior Paul', 'Bafoussam'], [$data['firstName'], $data['city']]);

        // Mais une ville retirée ne peut pas être choisie à nouveau par un autre client.
        $other = $this->login('+237690000002')['token'];
        $this->api('PATCH', '/api/me', $other, ['city' => 'Bafoussam']);
        self::assertSame(422, $this->httpStatus());
    }
}
