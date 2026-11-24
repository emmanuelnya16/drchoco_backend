<?php

namespace App\Tests\Unit;

use App\Api\ApiException;
use App\Media\ImageLimits;
use App\Media\PrivateMediaStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Dossier privé : un enregistrement impossible ne laisse aucun fichier et répond clairement (507).
 */
final class PrivateMediaStorageTest extends TestCase
{
    public function testAnImpossibleWriteAnswers507AndLeavesNothing(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'drc'); // un fichier à la place du dossier : rien ne peut y être créé
        try {
            $storage = $this->storage($file);
            try {
                $storage->store('proofs/1', 'png', 'image');
                self::fail('507 attendu');
            } catch (ApiException $e) {
                self::assertSame([507, 'STORAGE_FULL'], [$e->getStatusCode(), $e->getErrorCode()]);
                self::assertStringContainsString('WhatsApp', $e->getMessage());
            }
            self::assertSame('', (string) file_get_contents($file));
        } finally {
            unlink($file);
        }
    }

    public function testStoreAndDelete(): void
    {
        $directory = sys_get_temp_dir() . '/drc-' . bin2hex(random_bytes(6));
        $storage = $this->storage($directory);
        $path = $storage->store('proofs/1', 'png', 'image');
        self::assertSame('image', file_get_contents($storage->absolute($path)));
        $storage->delete($path);
        self::assertFileDoesNotExist($storage->absolute($path));
        rmdir($directory . '/proofs/1');
        rmdir($directory . '/proofs');
        rmdir($directory);
    }

    public function testPixelLimits(): void
    {
        self::assertTrue(ImageLimits::accepts(1, 1));
        self::assertTrue(ImageLimits::accepts(12000, 3333));
        self::assertFalse(ImageLimits::accepts(12001, 1));
        self::assertFalse(ImageLimits::accepts(12000, 3334));
        self::assertFalse(ImageLimits::accepts(0, 10));
        self::assertFalse(ImageLimits::accepts(\PHP_INT_MAX, \PHP_INT_MAX));
    }

    private function storage(string $directory): PrivateMediaStorage
    {
        return new PrivateMediaStorage($directory, str_repeat('s', 32), self::createStub(UrlGeneratorInterface::class), new \Psr\Log\NullLogger());
    }
}
