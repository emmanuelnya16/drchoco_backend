<?php

namespace App\Tests\Service;

use App\Service\Media\CloudinaryService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * P0 — le contrôle du type de fichier avant l'envoi à Cloudinary fonctionne (composant symfony/mime).
 * Aucun appel réseau : le fichier est refusé avant tout envoi.
 */
final class CloudinaryServiceTest extends TestCase
{
    public function testNonImageFileIsRejectedBeforeAnyUpload(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'drc');
        file_put_contents($path, "Ceci n'est pas une image.");
        $file = new UploadedFile($path, 'faux.jpg', 'image/jpeg', null, true);

        $service = new CloudinaryService('cloudinary://cle:secret@compte-de-test');

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Type non autorisé (text/plain)');
            $service->uploadProductImage($file, 1);
        } finally {
            @unlink($path);
        }
    }
}
