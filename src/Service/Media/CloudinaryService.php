<?php

namespace App\Service\Media;

use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class CloudinaryService
{
    private Cloudinary $cloudinary;

    private const PRODUCTS_FOLDER   = 'drchoco/products'; // dossier propre à DR Choco
    private const MAX_FILE_SIZE     = 5 * 1024 * 1024;
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    public function __construct(string $cloudinaryUrl)
    {
        // ✅ Initialise la configuration globale Cloudinary
        // Tous les appels UploadApi(), AdminApi() etc. utilisent cette config
        Configuration::instance($cloudinaryUrl);

        $this->cloudinary = new Cloudinary($cloudinaryUrl);
    }

    public function uploadProductImage(UploadedFile $file, int $productId): array
    {
        $this->validateFile($file);

        $folder = self::PRODUCTS_FOLDER . '/' . $productId;

        try {
            // ✅ On utilise $this->cloudinary->uploadApi() au lieu de new UploadApi()
            // Comme ça la configuration est bien transmise
            $result = $this->cloudinary->uploadApi()->upload(
                $file->getPathname(),
                [
                    'folder'          => $folder,
                    'format'          => 'webp',
                    'quality'         => 'auto',
                    'transformation'  => [
                        ['width' => 1200, 'height' => 1200, 'crop' => 'limit'],
                    ],
                    'tags'            => ['product', 'product_' . $productId],
                    'overwrite'       => false,
                    'unique_filename' => true,
                ]
            );

            return [
                'publicId' => $result['public_id'],
                'url'      => $result['secure_url'],
                'width'    => $result['width'],
                'height'   => $result['height'],
                'format'   => $result['format'],
                'size'     => $result['bytes'],
            ];

        } catch (\Exception $e) {
            throw new \RuntimeException(
                'Échec de l\'upload vers Cloudinary : ' . $e->getMessage()
            );
        }
    }

    /**
     * Ajout DR Choco (P4) : image du catalogue (article, catégorie, look) envoyée dans un dossier de
     * drchoco/, avec exactement les réglages de uploadProductImage() (WebP, qualité auto, 1 200 px au plus).
     * Les identifiants DR Choco sont du texte (« prod-01 ») et l'image est envoyée avant la création de
     * l'article : uploadProductImage(), qui attend un identifiant entier, est gardée telle quelle.
     *
     * @param string       $folder sous-dossier de drchoco/ (« products », « categories », « looks »)
     * @param list<string> $tags
     *
     * @return array{publicId: string, url: string, width: int, height: int, format: string, size: int}
     */
    public function uploadCatalogImage(UploadedFile $file, string $folder, array $tags = []): array
    {
        $this->validateFile($file);

        try {
            $result = $this->cloudinary->uploadApi()->upload(
                $file->getPathname(),
                [
                    'folder'          => 'drchoco/' . $folder,
                    'format'          => 'webp',
                    'quality'         => 'auto',
                    'transformation'  => [
                        ['width' => 1200, 'height' => 1200, 'crop' => 'limit'],
                    ],
                    'tags'            => $tags,
                    'overwrite'       => false,
                    'unique_filename' => true,
                ]
            );

            return [
                'publicId' => $result['public_id'],
                'url'      => $result['secure_url'],
                'width'    => $result['width'],
                'height'   => $result['height'],
                'format'   => $result['format'],
                'size'     => $result['bytes'],
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'Échec de l\'upload vers Cloudinary : ' . $e->getMessage()
            );
        }
    }

    public function deleteImage(string $publicId): bool
    {
        try {
            // ✅ Même correction — utilise $this->cloudinary->uploadApi()
            $result = $this->cloudinary->uploadApi()->destroy($publicId, [
                'resource_type' => 'image',
            ]);
            return $result['result'] === 'ok';
        } catch (\Exception) {
            return false;
        }
    }

    public function deleteProductFolder(int $productId): void
    {
        try {
            // Correction DR Choco (P4, accord du 16/11/2026) : le SDK Cloudinary 3 n'a pas deleteResourcesByPrefix().
            $this->cloudinary->adminApi()->deleteAssetsByPrefix(
                self::PRODUCTS_FOLDER . '/' . $productId . '/'
            );
        } catch (\Exception) {
            // Silencieux
        }
    }

    public function getTransformedUrl(
        string $publicId,
        int    $width  = 400,
        int    $height = 400,
        string $crop   = 'fill',
    ): string {
        return $this->cloudinary->image($publicId)
            ->resize(\Cloudinary\Transformation\Resize::$crop($width, $height))
            ->format(\Cloudinary\Transformation\Format::auto())
            ->quality(\Cloudinary\Transformation\Quality::auto())
            ->toUrl();
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException(
                'Fichier invalide : ' . $file->getErrorMessage()
            );
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Type non autorisé (%s). Acceptés : JPEG, PNG, WebP.',
                    $file->getMimeType()
                )
            );
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Fichier trop lourd (%s MB). Maximum : 5 MB.',
                    round($file->getSize() / 1024 / 1024, 2)
                )
            );
        }
    }
}