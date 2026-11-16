<?php

namespace App\Tests\Unit;

use App\Media\ImageMetadataStripper;
use PHPUnit\Framework\TestCase;

/**
 * Métadonnées (GPS, appareil, commentaires) retirées des captures de paiement, image intacte.
 */
final class ImageMetadataStripperTest extends TestCase
{
    // JPEG de 1 × 1 pixel.
    private const JPEG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    public function testJpegLosesExifAndComments(): void
    {
        $jpeg = base64_decode(self::JPEG);
        $exif = "Exif\0\0GPS 3.8480,11.5021 Samsung";
        $app1 = "\xFF\xE1" . pack('n', \strlen($exif) + 2) . $exif;
        $comment = "\xFF\xFE" . pack('n', 9) . 'secret!';
        $dirty = substr($jpeg, 0, 2) . $app1 . $comment . substr($jpeg, 2);

        $clean = ImageMetadataStripper::strip($dirty, 'image/jpeg');

        self::assertNotNull($clean);
        self::assertStringNotContainsString('GPS', $clean);
        self::assertStringNotContainsString('secret', $clean);
        self::assertSame($jpeg, $clean, 'le reste du fichier est recopié tel quel');
        self::assertSame([1, 1], \array_slice((array) getimagesizefromstring($clean), 0, 2));
    }

    public function testPngLosesTextChunks(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $data = "Comment\0GPS";
        $chunk = pack('N', \strlen($data)) . 'tEXt' . $data . pack('N', crc32('tEXt' . $data));

        self::assertSame($png, ImageMetadataStripper::strip(substr($png, 0, 33) . $chunk . substr($png, 33), 'image/png'));
    }

    public function testWebpLosesExifAndXmpChunksAndFlags(): void
    {
        $chunk = fn (string $fourcc, string $data) => $fourcc . pack('V', \strlen($data)) . $data . (\strlen($data) % 2 ? "\0" : '');
        $vp8x = $chunk('VP8X', "\x0C\0\0\0" . "\0\0\0" . "\0\0\0");
        $image = $chunk('VP8L', "\x2F\0\0\0\0");
        $body = $vp8x . $chunk('EXIF', 'GPS 3.84') . $image . $chunk('XMP ', '<x:xmpmeta/>');
        $webp = 'RIFF' . pack('V', 4 + \strlen($body)) . 'WEBP' . $body;

        $clean = ImageMetadataStripper::strip($webp, 'image/webp');

        self::assertNotNull($clean);
        self::assertStringNotContainsString('GPS', $clean);
        self::assertStringNotContainsString('xmpmeta', $clean);
        self::assertSame(\strlen($clean) - 8, unpack('V', substr($clean, 4, 4))[1], 'taille RIFF recalculée');
        self::assertSame(0, \ord($clean[20]) & 0x0C, 'indicateurs EXIF et XMP retirés');
        self::assertStringContainsString('VP8L', $clean);
    }

    public function testBrokenFilesAreRefused(): void
    {
        self::assertNull(ImageMetadataStripper::strip('pas une image', 'image/jpeg'));
        self::assertNull(ImageMetadataStripper::strip("\x89PNG\r\n\x1A\n\0\0", 'image/png'));
        self::assertNull(ImageMetadataStripper::strip('RIFF0000WEBP', 'image/webp'));
        self::assertNull(ImageMetadataStripper::strip('GIF89a', 'image/gif'));
    }
}
