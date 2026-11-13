<?php

namespace App\Tests\Service;

use App\Entity\Admin;
use App\Entity\RefreshToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Données sensibles en écriture seule : même si une entité était renvoyée telle quelle par l'API,
 * le mot de passe haché de l'admin et l'empreinte des jetons de session n'en sortiraient jamais.
 */
final class SensitiveDataSerializationTest extends KernelTestCase
{
    public function testAdminPasswordHashIsNeverSerialized(): void
    {
        $admin = (new Admin())
            ->setName('Owner')
            ->setEmail('owner@drchoco.cm')
            ->setRole(Admin::ROLE_OWNER)
            ->setPassword('$2y$13$hash-bcrypt-de-test');

        $json = $this->serializer()->serialize($admin, 'json');
        $data = json_decode($json, true);

        self::assertArrayNotHasKey('password', $data);
        self::assertStringNotContainsString('hash-bcrypt-de-test', $json);
        self::assertSame('owner@drchoco.cm', $data['email']); // le reste est bien sérialisé
    }

    public function testRefreshTokenHashIsNeverSerialized(): void
    {
        $now = new \DateTimeImmutable();
        $token = (new RefreshToken($now, $now->modify('+1 day')))
            ->setTokenHash('empreinte-secrete-de-test')
            ->setUserType(RefreshToken::TYPE_USER)
            ->setUserId(1)
            ->setFamilyId('famille');

        $json = $this->serializer()->serialize($token, 'json');

        self::assertArrayNotHasKey('tokenHash', json_decode($json, true));
        self::assertStringNotContainsString('empreinte-secrete-de-test', $json);
    }

    private function serializer(): SerializerInterface
    {
        self::bootKernel();

        return static::getContainer()->get(SerializerInterface::class);
    }
}
