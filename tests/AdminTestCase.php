<?php

namespace App\Tests;

use App\Entity\Admin;
use App\Repository\AdminRepository;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base des tests de l'administration (P3) : catalogue chargé, horloge figée à la seconde réelle,
 * comptes de l'équipe créés avec un vrai mot de passe haché.
 */
abstract class AdminTestCase extends CatalogTestCase
{
    use ClockSensitiveTrait;

    protected const PASSWORD = 'MotDePasse-2026';

    protected MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = self::mockTime(new \DateTimeImmutable('@' . time()));
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function account(
        string $role = Admin::ROLE_OWNER,
        string $email = 'owner@drchoco.cm',
        ?string $city = null,
        ?string $phone = null,
        bool $active = true,
        string $password = self::PASSWORD,
    ): Admin {
        $admin = (new Admin())
            ->setName(ucfirst($role) . ' Test')
            ->setEmail($email)
            ->setPhone($phone)
            ->setRole($role)
            ->setCity($city)
            ->setIsActive($active);
        $admin->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($admin, $password));
        $this->em()->persist($admin);
        $this->em()->flush();

        return $admin;
    }

    /** @return array<string, mixed> */
    protected function adminLogin(string $identifier, string $password = self::PASSWORD, array $server = []): array
    {
        return $this->postJson('/api/admin/auth/login', ['identifier' => $identifier, 'password' => $password], $server);
    }

    protected function adminToken(string $identifier, string $password = self::PASSWORD): string
    {
        $data = $this->adminLogin($identifier, $password);
        self::assertSame(200, $this->httpStatus(), 'connexion admin : ' . json_encode($data));

        return $data['accessToken'];
    }

    /** @return array<string, mixed>|list<mixed> */
    protected function api(string $method, string $uri, ?string $token, mixed $body = null): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $content = null;
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR);
        }
        $this->client->request($method, $uri, [], [], $server, $content);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    protected function adminRefreshCookie(): ?Cookie
    {
        return $this->client->getCookieJar()->get('drc_admin_refresh', '/api/admin/auth');
    }

    protected function setAdminRefreshCookie(?string $value): void
    {
        $jar = $this->client->getCookieJar();
        $jar->expire('drc_admin_refresh', '/api/admin/auth');
        if ($value !== null) {
            $jar->set(new Cookie('drc_admin_refresh', $value, null, '/api/admin/auth'));
        }
    }

    protected function adminRefresh(): array
    {
        $this->client->request('POST', '/api/admin/auth/refresh', [], [], ['HTTP_ACCEPT' => 'application/json']);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    protected function reloadAdmin(string $email): ?Admin
    {
        $this->em()->clear();

        return static::getContainer()->get(AdminRepository::class)->findByEmail($email);
    }
}
