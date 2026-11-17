<?php

namespace App\Controller\Reservation;

use App\Api\ApiDate;
use App\Api\ApiException;
use App\Api\ApiJson;
use App\Api\JsonBody;
use App\Account\TextInput;
use App\Entity\Reservation;
use App\Entity\User;
use App\Repository\ReservationRepository;
use App\Reservation\ProofUploader;
use App\Reservation\ReservationCreator;
use App\Reservation\ReservationLifecycle;
use App\Reservation\ReservationNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Réservations du client connecté (contrat §10). La réservation d'un autre client : 404, jamais 403.
 * Avant chaque réponse, les réservations échues du client passent à « expirée » (sans attendre la tâche
 * planifiée) : le front ne voit jamais une réservation payable après son échéance.
 */
final class ReservationController
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ReservationCreator $creator,
        private readonly ReservationLifecycle $lifecycle,
        private readonly ProofUploader $proofs,
        private readonly ReservationNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
    ) {}

    /** POST /api/reservations — 201 (aussi quand la même demande est renvoyée avec la même clé). */
    #[Route('/reservations', name: 'api_reservations_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $result = $this->creator->create($user, JsonBody::object($request), $request->headers->get('Idempotency-Key'));

        return ApiJson::noStore($this->detail($result['reservation']), 201);
    }

    /**
     * GET /api/reservations?status=reserved (fiches complètes : réservées et paiement rejeté),
     * ou GET /api/reservations?scope=active|done&page=N (historique, 10 par page).
     */
    #[Route('/reservations', name: 'api_reservations_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->lifecycle->expireDue($user);
        $now = $this->lifecycle->now();

        $status = (string) $request->query->get('status', '');
        if ($status !== '') {
            $statuses = $status === Reservation::STATUS_RESERVED ? Reservation::AWAITING_PAYMENT : [$status];

            return ApiJson::privateNoCache(array_map(
                fn (Reservation $r) => $this->normalizer->detail($r, $now),
                $this->reservations->findForUserByStatus($user, $statuses),
            ));
        }

        $done = $request->query->get('scope') === 'done';
        $page = max(1, min(10000, (int) $request->query->get('page', '1')));
        $result = $this->reservations->pageForUser($user, $done, $page);
        $totalPages = max(1, (int) ceil($result['total'] / ReservationRepository::PAGE_SIZE));

        return ApiJson::privateNoCache([
            'items'      => array_map(fn (Reservation $r) => $this->normalizer->listItem($r, $now), $result['items']),
            'page'       => $page,
            'pageSize'   => ReservationRepository::PAGE_SIZE,
            'total'      => $result['total'],
            'totalPages' => $totalPages,
            'counts'     => $this->reservations->countsForUser($user),
        ]);
    }

    /** GET /api/reservations/summary — pastille « à faire » de l'app (avant /{ref}). */
    #[Route('/reservations/summary', name: 'api_reservations_summary', methods: ['GET'], priority: 10)]
    public function summary(#[CurrentUser] User $user): JsonResponse
    {
        $this->lifecycle->expireDue($user);
        $open = $this->reservations->findForUserByStatus($user, array_values(array_diff([
            Reservation::STATUS_RESERVED, Reservation::STATUS_PENDING_VALIDATION, Reservation::STATUS_PAYMENT_REJECTED,
            Reservation::STATUS_CONFIRMED, Reservation::STATUS_PREPARING, Reservation::STATUS_HANDED_TO_AGENCY,
            Reservation::STATUS_READY_FOR_PICKUP, Reservation::STATUS_IN_DELIVERY,
        ], Reservation::FINAL_STATUSES)));
        usort($open, fn (Reservation $a, Reservation $b) => $b->getUpdatedAt() <=> $a->getUpdatedAt() ?: $b->getId() <=> $a->getId());

        $needsAction = 0;
        $last = null;
        $items = [];
        foreach ($open as $r) {
            if (\in_array($r->getStatus(), [...Reservation::AWAITING_PAYMENT, Reservation::STATUS_READY_FOR_PICKUP], true)) {
                ++$needsAction;
            }
            $items[] = ['ref' => $r->getRef(), 'status' => $r->getStatus(), 'updatedAt' => ApiDate::format($r->getUpdatedAt()), 'hasPackagePhotos' => $r->getPackageReadyAt() !== null];
        }
        $latest = $this->reservations->findOneBy(['user' => $user], ['updatedAt' => 'DESC', 'id' => 'DESC']);
        if ($latest !== null) {
            $last = ApiDate::format($latest->getUpdatedAt());
        }

        return ApiJson::privateNoCache(['needsAction' => $needsAction, 'lastUpdatedAt' => $last, 'items' => $items]);
    }

    /** GET /api/reservations/{ref} */
    #[Route('/reservations/{ref}', name: 'api_reservations_show', methods: ['GET'])]
    public function show(string $ref, #[CurrentUser] User $user): JsonResponse
    {
        $reservation = $this->owned($user, $ref);
        if ($reservation->isOverdue($this->lifecycle->now())) {
            $this->lifecycle->expire($reservation);
        }

        return ApiJson::privateNoCache($this->detail($reservation));
    }

    /** POST /api/reservations/{ref}/proof (multipart) — 201. */
    #[Route('/reservations/{ref}/proof', name: 'api_reservations_proof', methods: ['POST'])]
    public function proof(string $ref, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $reservation = $this->proofs->upload($user, $ref, $request);
        $detail = $this->detail($reservation);

        return ApiJson::noStore(['status' => $reservation->getStatus(), 'proof' => $detail['proof'], 'reservation' => $detail], 201);
    }

    /** POST /api/reservations/{ref}/cancel — corps `{reason?}`, `{}` ou vide. */
    #[Route('/reservations/{ref}/cancel', name: 'api_reservations_cancel', methods: ['POST'])]
    public function cancel(string $ref, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $reservation = $this->owned($user, $ref);
        $body = JsonBody::object($request, allowEmpty: true);
        $reason = TextInput::clean($body['reason'] ?? null);
        if ($reason !== null && TextInput::length($reason) > 200) {
            throw ApiException::validation(['reason' => 'Le motif ne doit pas dépasser 200 caractères.'], 'Le motif ne doit pas dépasser 200 caractères.');
        }

        $this->lifecycle->cancelByClient($reservation, $reason !== '' ? $reason : null);

        return ApiJson::noStore(['status' => $reservation->getStatus(), 'reservation' => $this->detail($reservation)]);
    }

    private function owned(User $user, string $ref): Reservation
    {
        return $this->reservations->findOwned($user, $ref)
            ?? throw ApiException::notFound('RESERVATION_NOT_FOUND', 'Cette réservation est introuvable.');
    }

    /** Fiche relue en base (historique et preuves à jour). @return array<string, mixed> */
    private function detail(Reservation $reservation): array
    {
        $this->em->refresh($reservation);

        return $this->normalizer->detail($reservation, $this->lifecycle->now());
    }
}
