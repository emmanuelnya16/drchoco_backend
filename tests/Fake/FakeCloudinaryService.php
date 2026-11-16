<?php

namespace App\Tests\Fake;

use App\Service\Media\CloudinaryService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Faux Cloudinary pour les tests : les contrôles du vrai service s'appliquent (type, taille), mais rien n'est
 * envoyé ; l'adresse renvoyée a la forme d'une vraie adresse Cloudinary.
 */
final class FakeCloudinaryService extends CloudinaryService
{
    /** @var list<array{folder: string, name: string}> */
    public array $uploads = [];

    public bool $fail = false;

    public function uploadCatalogImage(UploadedFile $file, string $folder, array $tags = []): array
    {
        // Mêmes contrôles que le vrai envoi (type réel du fichier, taille), via la classe parente.
        $check = new \ReflectionMethod(CloudinaryService::class, 'validateFile');
        $check->invoke($this, $file);

        if ($this->fail) {
            throw new \RuntimeException('Échec simulé de Cloudinary');
        }

        $this->uploads[] = ['folder' => $folder, 'name' => $file->getClientOriginalName()];
        $id = 'drchoco/' . $folder . '/test-' . \count($this->uploads);

        return [
            'publicId' => $id,
            'url'      => 'https://res.cloudinary.com/compte-de-test/image/upload/v1/' . $id . '.webp',
            'width'    => 800,
            'height'   => 1000,
            'format'   => 'webp',
            'size'     => (int) $file->getSize(),
        ];
    }
}
