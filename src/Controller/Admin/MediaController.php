<?php

namespace App\Controller\Admin;

use App\Api\ApiException;
use App\Api\ApiJson;
use App\Entity\Admin;
use App\Service\Media\CloudinaryService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * POST /api/admin/media — envoie une image du catalogue vers Cloudinary (service repris du backend de référence)
 * et renvoie son adresse publique, à mettre ensuite dans la galerie de l'article, la catégorie ou le look.
 *
 * Corps multipart : `file` (JPEG, PNG ou WebP, 5 Mo au plus) et `kind` (product, category ou look).
 * Toute l'équipe peut envoyer des images (le front laisse aussi les vendeurs gérer catégories et looks).
 * Limites par compte : 60 images par 10 min et 300 par jour (quota du compte Cloudinary).
 * Réponse 201 : `{ url, publicId, width, height }`.
 */
final class MediaController
{
    private const KINDS = ['product' => 'products', 'category' => 'categories', 'look' => 'looks'];
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly CloudinaryService $cloudinary,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.admin_media')]
        private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire(service: 'limiter.admin_media_day')]
        private readonly RateLimiterFactoryInterface $dailyLimiter,
    ) {}

    #[Route('/admin/media', name: 'api_admin_media_upload', methods: ['POST'])]
    public function upload(Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        foreach ([$this->limiter, $this->dailyLimiter] as $factory) {
            $limit = $factory->create('admin-' . $admin->getId())->consume();
            if (!$limit->isAccepted()) {
                throw ApiException::tooManyRequests('RATE_LIMITED', 'Trop d’images envoyées. Réessayez plus tard.', max(1, $limit->getRetryAfter()->getTimestamp() - time()));
            }
        }

        // Corps plus lourd que post_max_size : PHP vide le formulaire et le fichier.
        if ($request->files->count() === 0 && $request->request->count() === 0 && (int) $request->headers->get('Content-Length', '0') > 0) {
            throw new ApiException(413, 'FILE_TOO_LARGE', 'Image trop lourde : 5 Mo au maximum.');
        }

        $kind = (string) $request->request->get('kind', 'product');
        if (!isset(self::KINDS[$kind])) {
            throw ApiException::validation(['kind' => 'Type d’image inconnu.']);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw ApiException::validation(['file' => 'Choisissez une image.'], 'Choisissez une image à envoyer.');
        }
        if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) || ($file->isValid() && $file->getSize() > self::MAX_BYTES)) {
            throw new ApiException(413, 'FILE_TOO_LARGE', 'Image trop lourde : 5 Mo au maximum.');
        }
        if ($file->getError() === \UPLOAD_ERR_PARTIAL) {
            throw new ApiException(422, 'UPLOAD_INCOMPLETE', 'L’envoi de l’image a été interrompu. Réessayez.');
        }
        if (\in_array($file->getError(), [\UPLOAD_ERR_NO_TMP_DIR, \UPLOAD_ERR_CANT_WRITE, \UPLOAD_ERR_EXTENSION], true)) {
            $this->logger->error('Image reçue mais non enregistrée par PHP (réglage du serveur).', ['error' => $file->getError()]);

            throw new ApiException(503, 'MEDIA_UPLOAD_FAILED', 'L’envoi de l’image a échoué. Réessayez dans quelques instants.');
        }

        try {
            $result = $this->cloudinary->uploadCatalogImage($file, self::KINDS[$kind], ['drchoco', $kind]);
        } catch (\InvalidArgumentException $e) {
            // Type refusé ou fichier invalide : message du service (« Type non autorisé… Acceptés : JPEG, PNG, WebP. »).
            throw new ApiException(422, 'INVALID_IMAGE', $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->logger->error('Envoi d’une image vers Cloudinary impossible.', ['exception' => $e]);

            throw new ApiException(502, 'MEDIA_UPLOAD_FAILED', 'L’envoi de l’image a échoué. Réessayez dans quelques instants.');
        }

        return ApiJson::noStore([
            'url'      => $result['url'],
            'publicId' => $result['publicId'],
            'width'    => $result['width'],
            'height'   => $result['height'],
        ], 201);
    }
}
