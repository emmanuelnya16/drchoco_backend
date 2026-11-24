<?php

namespace App\Tests\Unit;

use App\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Garde-fous du démarrage (P9) : pas de production sans secret solide, pas de mode développement face au public.
 */
final class KernelTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;
    /** @var array<string, mixed> */
    private array $env;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->env = $_ENV;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_ENV = $this->env;
        restore_exception_handler();
    }

    #[DataProvider('weakSecrets')]
    public function testProductionRefusesToStartWithAWeakSecret(string $secret): void
    {
        $_ENV['APP_SECRET'] = $_SERVER['APP_SECRET'] = $secret;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/APP_SECRET est absent, trop court ou déjà publié/');
        (new Kernel('prod', false))->boot();
    }

    /** @return iterable<string, array{string}> */
    public static function weakSecrets(): iterable
    {
        yield 'vide' => [''];
        yield 'espaces' => [str_repeat(' ', 40)];
        yield 'trop court' => [str_repeat('a', 31)];
    }

    public function testStrongSecrets(): void
    {
        self::assertTrue(Kernel::isStrongSecret(bin2hex(random_bytes(32))));
        self::assertFalse(Kernel::isStrongSecret(''));
        // Le secret de développement de l'historique git est reconnu par son empreinte seulement (jamais écrit ici).
        $published = (new \ReflectionClassConstant(Kernel::class, 'PUBLISHED_SECRET'))->getValue();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $published);
        self::assertTrue(Kernel::isStrongSecret($published), 'l’empreinte n’est pas le secret');
    }

    #[DataProvider('publicRequests')]
    public function testDevelopmentModeNeverAnswersThePublic(string $host, string $ip, array $server = []): void
    {
        unset($_SERVER['APP_DEV_HOSTS'], $_ENV['APP_DEV_HOSTS']);
        $response = (new Kernel('dev', false))->handle($this->request($host, $ip, $server));
        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('DEV_MODE', $body['code']);
        self::assertStringContainsString('APP_ENV=prod', $body['message']);
    }

    /** @return iterable<string, array{0: string, 1: string, 2?: array<string, string>}> */
    public static function publicRequests(): iterable
    {
        yield 'nom de domaine du site' => ['drchoco.com', '127.0.0.1'];
        yield 'adresse publique du serveur' => ['41.202.1.2', '127.0.0.1'];
        yield 'visiteur public' => ['localhost', '41.202.1.2'];
        yield 'visiteur public en IPv6' => ['localhost', '2a01:cb00::1'];
        yield 'visiteur public en IPv4 notée en IPv6' => ['localhost', '::ffff:41.202.1.2'];
        yield 'tunnel 6to4' => ['localhost', '2002:29ca:102::1'];
        yield 'nom sous forme d’adresse publique' => ['[2a01:cb00::1]', '127.0.0.1'];
        yield 'domaine qui imite localhost' => ['localhost.attaquant.com', '127.0.0.1'];
        yield 'en-tête annoncé par le visiteur' => ['localhost', '41.202.1.2', ['HTTP_X_FORWARDED_FOR' => '127.0.0.1', 'HTTP_X_FORWARDED_HOST' => 'localhost']];
        yield 'hôte invalide' => ['local_host!', '127.0.0.1'];
    }

    public function testExtraHostsAreExplicit(): void
    {
        $isLocal = new \ReflectionMethod(Kernel::class, 'isLocal');
        $_SERVER['APP_DEV_HOSTS'] = ' essai.example.org , drchoco.com';
        self::assertTrue($isLocal->invoke(null, $this->request('drchoco.com', '127.0.0.1')));
        self::assertTrue($isLocal->invoke(null, $this->request('ESSAI.example.org:8443', '192.168.1.20')));
        self::assertTrue($isLocal->invoke(null, $this->request('localhost', '::ffff:127.0.0.1')));
        self::assertFalse($isLocal->invoke(null, $this->request('drchoco.com', '41.202.1.2')), 'le visiteur doit rester local');
        unset($_SERVER['APP_DEV_HOSTS']);
        foreach (['localhost', 'localhost:8000', '127.0.0.1:8000', '[::1]:8000', '192.168.1.20:3000', '10.0.2.2', 'drchoco.test', 'api.drchoco.localhost', 'pc-boutique.local'] as $host) {
            self::assertTrue($isLocal->invoke(null, $this->request($host, '192.168.1.30')), $host);
        }
    }

    /** @param array<string, string> $server */
    private function request(string $host, string $ip, array $server = []): Request
    {
        return Request::create('/api/health', 'GET', [], [], [], $server + ['HTTP_HOST' => $host, 'REMOTE_ADDR' => $ip]);
    }
}
