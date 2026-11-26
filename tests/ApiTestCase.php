<?php

namespace App\Tests;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Monolog\Handler\TestHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base des tests d'API : base de test vidée avant chaque test, aides pour créer des comptes et des jetons.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $audit = static::getContainer()->get('test.audit_handler');
        $audit->clear();
        $audit->setSkipReset(true); // gardé d'une requête à l'autre (le noyau remet ses services à zéro entre deux)

        $connection = $this->em()->getConnection();
        // Les adresses, favoris et appareils abonnés partent avec les comptes (clé étrangère ON DELETE CASCADE) ;
        // les appareils sans compte (tests de la purge) et la file des notifications sont vidés aussi.
        foreach (['push_subscriptions', 'refresh_tokens', 'otp_codes', 'whatsapp_login_requests', 'users', 'admins'] as $table) {
            $connection->executeStatement('DELETE FROM ' . $table);
        }
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(string $phone = '+237690000001', string $status = User::STATUS_ACTIVE): User
    {
        $user = (new User())->setPhone($phone)->setFirstName('Junior')->setStatus($status);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    protected function createAdmin(string $role = Admin::ROLE_OWNER, string $email = 'admin@drchoco.cm', ?string $city = null, bool $active = true): Admin
    {
        $admin = (new Admin())
            ->setName('Admin Test')
            ->setEmail($email)
            ->setRole($role)
            ->setCity($city)
            ->setIsActive($active)
            ->setPassword('hash-non-utilise');
        $this->em()->persist($admin);
        $this->em()->flush();

        return $admin;
    }

    protected function tokenFor(User|Admin $account): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($account);
    }

    /**
     * Journal d'audit de la requête en cours et des précédentes (App\Audit\AuditLog).
     *
     * @return list<array{event: string, admin?: array<string, mixed>, ip: string, data?: array<string, mixed>}>
     */
    protected function auditRecords(?string $event = null): array
    {
        /** @var TestHandler $handler */
        $handler = static::getContainer()->get('test.audit_handler');
        $records = array_map(fn ($r) => ['event' => $r->message] + $r->context, $handler->getRecords());

        return $event === null ? $records : array_values(array_filter($records, fn (array $r) => $r['event'] === $event));
    }

    /** @return array<string, mixed> */
    protected function getJson(string $uri, ?string $token = null, array $server = []): array
    {
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request('GET', $uri, [], [], $server + ['HTTP_ACCEPT' => 'application/json']);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
