<?php

namespace App\Tests\Unit;

use App\Api\ApiException;
use App\Api\LimiterStorage;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\AbstractLogger;

/**
 * Compteurs d'essais impossibles à écrire (disque plein) : la connexion de l'équipe est refusée, jamais sans limite.
 */
final class LimiterStorageTest extends TestCase
{
    public function testRefusesWhenCountersCannotBeWritten(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
            }
        };
        try {
            (new LimiterStorage($this->pool(false), $logger))->assertWritable();
            self::fail('503 attendu');
        } catch (ApiException $e) {
            self::assertSame([503, 'SERVICE_UNAVAILABLE'], [$e->getStatusCode(), $e->getErrorCode()]);
        }
        self::assertSame(['critical'], $logger->levels);

        (new LimiterStorage($this->pool(true), $logger))->assertWritable();
        self::assertCount(1, $logger->levels);
    }

    private function pool(bool $writable): CacheItemPoolInterface
    {
        $pool = self::createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn(self::createStub(CacheItemInterface::class));
        $pool->method('save')->willReturn($writable);

        return $pool;
    }
}
