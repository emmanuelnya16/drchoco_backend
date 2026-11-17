<?php

namespace App\Controller\Reservation;

use App\Api\ApiException;
use App\Entity\ReservationPackagePhoto;
use App\Entity\ReservationProof;
use App\Media\PrivateMediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Images privées, par lien signé et temporaire (balise <img> : pas d'en-tête Authorization possible) :
 *  - GET /api/media/private/proofs/{id}?expires=…&signature=…         capture de paiement (P5) ;
 *  - GET /api/media/private/package-photos/{id}?expires=…&signature=… photo du colis prêt (P6).
 * Lien faux ou expiré : 403. Le chemin contient « /private/ » : le service worker du front ne les met jamais en cache.
 */
final class PrivateMediaController
{
    public function __construct(
        private readonly PrivateMediaStorage $storage,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/media/private/proofs/{id}', name: 'api_private_proof', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function proof(int $id, Request $request): BinaryFileResponse
    {
        $expires = $this->assertSigned(['proof'], $id, $request);
        $proof = $this->em->find(ReservationProof::class, $id);

        return $this->file($proof?->getStoragePath(), $proof?->getMimeType(), 'preuve-' . $proof?->getReservation()->getRef(), $expires);
    }

    #[Route('/media/private/package-photos/{id}', name: 'api_private_package_photo', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function packagePhoto(int $id, Request $request): BinaryFileResponse
    {
        // Lien de l'écran (1 h) ou d'une notification push (25 h).
        $expires = $this->assertSigned(['package', PrivateMediaStorage::KIND_PACKAGE_PUSH], $id, $request);
        $photo = $this->em->find(ReservationPackagePhoto::class, $id);

        return $this->file($photo?->getStoragePath(), $photo?->getMimeType(), sprintf('colis-%s-%d', $photo?->getReservation()->getRef(), (int) $photo?->getPosition() + 1), $expires);
    }

    /**
     * @param list<string> $kinds signatures acceptées
     *
     * @return int échéance du lien (secondes)
     */
    private function assertSigned(array $kinds, int $id, Request $request): int
    {
        $expires = (int) $request->query->get('expires', '0');
        $signature = (string) $request->query->get('signature', '');
        foreach ($kinds as $kind) {
            if ($this->storage->isValid($kind, $id, $expires, $signature, $this->now())) {
                return $expires;
            }
        }

        throw new ApiException(403, 'LINK_EXPIRED', 'Ce lien a expiré : rechargez la page.');
    }

    private function file(?string $storagePath, ?string $mimeType, string $name, int $expires): BinaryFileResponse
    {
        $path = $storagePath !== null ? $this->storage->absolute($storagePath) : null;
        if ($path === null || $mimeType === null || !is_file($path)) {
            throw ApiException::notFound('NOT_FOUND', 'Image introuvable.');
        }

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type'            => $mimeType,
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ], false);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $name . '.' . pathinfo($path, \PATHINFO_EXTENSION));
        $response->headers->set('Cache-Control', sprintf('private, max-age=%d', max(0, min(3600, $expires - $this->now()->getTimestamp()))));

        return $response;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
    }
}
