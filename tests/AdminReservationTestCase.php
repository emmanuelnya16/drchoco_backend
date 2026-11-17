<?php

namespace App\Tests;

use App\Entity\Admin;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Base des tests de la gestion des réservations par l'équipe (P6) : un client réserve (ReservationTestCase),
 * l'équipe agit avec un jeton admin (super admin ou vendeur d'une ville).
 */
abstract class AdminReservationTestCase extends ReservationTestCase
{
    private int $admins = 0;

    protected function ownerToken(): string
    {
        return $this->tokenFor($this->createAdmin(Admin::ROLE_OWNER, 'owner' . (++$this->admins) . '@drchoco.cm'));
    }

    protected function sellerToken(string $city, ?Admin &$seller = null): string
    {
        $seller = $this->createAdmin(Admin::ROLE_SELLER, 'vendeur' . (++$this->admins) . '@drchoco.cm', $city);

        return $this->tokenFor($seller);
    }

    /** @return array<string, mixed> */
    protected function adminApi(string $method, string $uri, string $token, ?array $body = null): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        $content = null;
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($body, \JSON_THROW_ON_ERROR);
        }
        $this->client->request($method, $uri, [], [], $server, $content);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, mixed> réponse de GET /api/admin/reservations/{ref} */
    protected function adminDetail(string $token, string $ref): array
    {
        $data = $this->adminApi('GET', '/api/admin/reservations/' . $ref, $token);
        self::assertSame(200, $this->httpStatus(), json_encode($data));

        return $data;
    }

    /** POST /api/admin/reservations/{ref}/package-ready en multipart. @return array<string, mixed> */
    protected function packageReady(string $token, string $ref, array $files, array $fields = []): array
    {
        $this->client->request('POST', '/api/admin/reservations/' . $ref . '/package-ready', $fields, $files === [] ? [] : ['photos' => $files], [
            'HTTP_ACCEPT'        => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** Photo du colis (PNG d'un pixel, contenu propre à `$label`, avec une métadonnée à retirer). */
    protected function photo(string $label = 'colis'): UploadedFile
    {
        return $this->file(self::pngWithText('Comment', 'GPS 3.8480,11.5021 ' . $label), $label . '.png');
    }

    /**
     * Réservation créée par le client, avec sa preuve de paiement envoyée (à vérifier).
     *
     * @return array{token: string, ref: string}
     */
    protected function reservationWithProof(array $body = [], string $phone = self::PHONE): array
    {
        $token = $this->loginNamed($phone);
        $data = $this->reserve($token, $body !== [] ? $body : $this->pickup());
        self::assertSame(201, $this->httpStatus(), json_encode($data));
        $this->uploadProof($token, $data['ref'], $this->file(self::pngWithText('Comment', 'preuve ' . $data['ref'] . ' ' . $phone)));
        self::assertSame(201, $this->httpStatus(), 'preuve');

        return ['token' => $token, 'ref' => $data['ref']];
    }

    /** @return array<string, mixed> fiche vue par le client */
    protected function clientDetail(string $token, string $ref): array
    {
        $data = $this->getJson('/api/reservations/' . $ref, $token);
        self::assertSame(200, $this->httpStatus(), json_encode($data));

        return $data;
    }
}
