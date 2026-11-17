<?php

namespace App\Admin\Reservation;

use App\Api\ApiException;
use App\Media\ImageMetadataStripper;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Photos du colis envoyées par l'équipe (POST /api/admin/reservations/{ref}/package-ready, multipart `photos[]`) :
 * 1 à 3 images JPEG, PNG ou WebP de 10 Mo au plus, lues dans le fichier lui-même (pas dans ce que le navigateur
 * annonce), sans leurs métadonnées (position GPS de l'appareil, etc.).
 */
final class PackagePhotoInput
{
    public const MAX_PHOTOS = 3;
    public const MAX_MB = 10;
    private const MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * @return list<array{bytes: string, mime: string, extension: string, width: int, height: int}>
     */
    public function read(Request $request): array
    {
        $raw = $request->files->get('photos');
        $files = $raw instanceof UploadedFile ? [$raw] : (\is_array($raw) ? $raw : []);
        $files = array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile));

        if ($files === []) {
            // Corps plus lourd que post_max_size : PHP vide le formulaire et les fichiers.
            if ($request->request->count() === 0 && $request->files->count() === 0 && (int) $request->headers->get('Content-Length', '0') > 0
                && !str_contains((string) $request->headers->get('Content-Type'), 'json')) {
                throw $this->tooLarge();
            }
            throw new ApiException(422, 'PHOTOS_REQUIRED', 'Ajoutez 1 à 3 photos du colis.');
        }
        if (\count($files) > self::MAX_PHOTOS) {
            throw new ApiException(422, 'PHOTOS_REQUIRED', sprintf('%d photos du colis au maximum.', self::MAX_PHOTOS));
        }

        return array_map($this->photo(...), $files);
    }

    /** @return array{bytes: string, mime: string, extension: string, width: int, height: int} */
    private function photo(UploadedFile $file): array
    {
        if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
            throw $this->tooLarge();
        }
        if ($file->getError() === \UPLOAD_ERR_PARTIAL) {
            throw new ApiException(400, 'UPLOAD_INCOMPLETE', 'L’envoi des photos a été interrompu. Réessayez.');
        }
        if (!$file->isValid()) {
            $this->logger->error('Photo du colis reçue mais non enregistrée par PHP (réglage du serveur).', ['error' => $file->getError()]);
            throw new ApiException(503, 'UPLOAD_FAILED', 'L’envoi des photos a échoué. Réessayez dans quelques instants.');
        }
        if ($file->getSize() > self::MAX_MB * 1024 * 1024) {
            throw $this->tooLarge();
        }

        $original = (string) file_get_contents($file->getPathname());
        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($original) ?: '';
        $info = isset(self::MIME_EXTENSIONS[$mime]) ? @getimagesizefromstring($original) : false;
        if (!isset(self::MIME_EXTENSIONS[$mime]) || $info === false || $info[0] < 1 || $info[1] < 1) {
            throw new ApiException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Format non accepté : envoyez des photos JPEG, PNG ou WebP.');
        }
        $clean = ImageMetadataStripper::strip($original, $mime);
        if ($clean === null) {
            throw new ApiException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Une des photos est illisible : reprenez-la puis réessayez.');
        }

        return [
            'bytes'     => $clean,
            'mime'      => $mime,
            'extension' => self::MIME_EXTENSIONS[$mime],
            'width'     => (int) $info[0],
            'height'    => (int) $info[1],
        ];
    }

    private function tooLarge(): ApiException
    {
        return new ApiException(413, 'FILE_TOO_LARGE', sprintf('Photo trop lourde : %d Mo au maximum par photo.', self::MAX_MB));
    }
}
