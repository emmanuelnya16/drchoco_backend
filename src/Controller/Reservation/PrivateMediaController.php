<?php

namespace App\Controller\Reservation;

use App\Api\ApiException;
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
 * GET /api/media/private/proofs/{id}?expires=…&signature=… — capture de paiement, par lien signé et
 * temporaire (balise <img> : pas d'en-tête Authorization possible). Lien faux ou expiré : 403.
 * Le chemin contient « /private/ » : le service worker du front ne la met jamais en cache.
 */
final class PrivateMediaController
{
    #[Route('/media/private/proofs/{id}', name: 'api_private_proof', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function proof(int $id, Request $request, PrivateMediaStorage $storage, EntityManagerInterface $em, ClockInterface $clock): BinaryFileResponse
    {
        $now = new \DateTimeImmutable('@' . $clock->now()->getTimestamp());
        $expires = (int) $request->query->get('expires', '0');
        $signature = (string) $request->query->get('signature', '');
        if (!$storage->isValid('proof', $id, $expires, $signature, $now)) {
            throw new ApiException(403, 'LINK_EXPIRED', 'Ce lien a expiré : rechargez la page.');
        }

        $proof = $em->find(ReservationProof::class, $id);
        $path = $proof !== null ? $storage->absolute($proof->getStoragePath()) : null;
        if ($path === null || !is_file($path)) {
            throw ApiException::notFound('NOT_FOUND', 'Image introuvable.');
        }

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type'            => $proof->getMimeType(),
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ], false);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            sprintf('preuve-%s.%s', $proof->getReservation()->getRef(), pathinfo($path, \PATHINFO_EXTENSION)),
        );
        $response->headers->set('Cache-Control', sprintf('private, max-age=%d', max(0, min(3600, $expires - $now->getTimestamp()))));

        return $response;
    }
}
