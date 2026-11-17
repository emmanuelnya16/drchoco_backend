<?php

namespace App\Controller\Admin;

use App\Account\TextInput;
use App\Admin\Reservation\AdminReservationActions;
use App\Admin\Reservation\AdminReservationList;
use App\Admin\Reservation\AdminReservationNormalizer;
use App\Api\ApiException;
use App\Api\ApiJson;
use App\Api\JsonBody;
use App\Entity\Admin;
use App\Entity\Reservation;
use App\Reservation\ReservationLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Réservations dans l'administration (contrat admin §3, P6).
 *
 * Tout membre de l'équipe voit et fait avancer les réservations de son périmètre (un vendeur : celles qui
 * contiennent ses articles ; hors périmètre : 404). Valider ou rejeter un paiement et annuler : super admin
 * seulement (403 pour un vendeur, aussi refusé par security.yaml).
 */
final class AdminReservationController
{
    public function __construct(
        private readonly AdminReservationList $list,
        private readonly AdminReservationActions $actions,
        private readonly AdminReservationNormalizer $normalizer,
        private readonly ReservationLifecycle $lifecycle,
        private readonly EntityManagerInterface $em,
    ) {}

    /** GET /api/admin/reservations — onglets, filtres, recherche, pagination (20 par page, 50 au plus). */
    #[Route('/admin/reservations', name: 'api_admin_reservations', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        return ApiJson::noStore($this->list->page($admin, $request));
    }

    /** GET /api/admin/reservations/{ref} */
    #[Route('/admin/reservations/{ref}', name: 'api_admin_reservations_show', methods: ['GET'])]
    public function show(string $ref, #[CurrentUser] Admin $admin): JsonResponse
    {
        $reservation = $this->actions->find($admin, $ref);
        $this->actions->expireIfDue($reservation);

        return ApiJson::noStore($this->detailResponse($reservation, $admin));
    }

    /** POST /api/admin/reservations/{ref}/validate-payment — `{note?}` (super admin). */
    #[Route('/admin/reservations/{ref}/validate-payment', name: 'api_admin_reservations_validate', methods: ['POST'])]
    public function validatePayment(string $ref, Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $reservation = $this->actions->find($admin, $ref);
        $body = JsonBody::object($request, allowEmpty: true);
        $this->actions->validatePayment($admin, $reservation, $this->text($body, 'note', 0, 200, 'La note'));

        return $this->reservation($reservation);
    }

    /** POST /api/admin/reservations/{ref}/reject-payment — `{reason}` 3 à 200 caractères (super admin). */
    #[Route('/admin/reservations/{ref}/reject-payment', name: 'api_admin_reservations_reject', methods: ['POST'])]
    public function rejectPayment(string $ref, Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $reservation = $this->actions->find($admin, $ref);
        $body = JsonBody::object($request);
        $this->actions->rejectPayment($admin, $reservation, (string) $this->text($body, 'reason', 3, 200, 'Le motif du rejet'));

        return $this->reservation($reservation);
    }

    /** POST /api/admin/reservations/{ref}/status — `{status, note?}` : preparing, in_delivery, delivered, picked_up. */
    #[Route('/admin/reservations/{ref}/status', name: 'api_admin_reservations_status', methods: ['POST'])]
    public function status(string $ref, Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $reservation = $this->actions->find($admin, $ref);
        $body = JsonBody::object($request);
        $status = $body['status'] ?? null;
        if (!\is_string($status) || $status === '') {
            throw ApiException::validation(['status' => 'Indiquez le nouveau statut.'], 'Indiquez le nouveau statut.');
        }
        $this->actions->advance($admin, $reservation, $status, $this->text($body, 'note', 0, 200, 'La note'));

        return $this->reservation($reservation);
    }

    /** POST /api/admin/reservations/{ref}/package-ready — multipart `photos[]` (1 à 3), `note?`, `agencyId?`. */
    #[Route('/admin/reservations/{ref}/package-ready', name: 'api_admin_reservations_package_ready', methods: ['POST'])]
    public function packageReady(string $ref, Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $reservation = $this->actions->find($admin, $ref);
        $fields = ['note' => $request->request->get('note'), 'agencyId' => $request->request->get('agencyId')];
        $note = $this->text($fields, 'note', 0, 200, 'La note');
        $agencyId = TextInput::clean($fields['agencyId']);
        $this->actions->packageReady($admin, $reservation, $request, $note, $agencyId !== null && $agencyId !== '' ? mb_substr($agencyId, 0, 40) : null);

        return $this->reservation($reservation);
    }

    /** POST /api/admin/reservations/{ref}/cancel — `{reason}` 3 à 200 caractères (super admin). */
    #[Route('/admin/reservations/{ref}/cancel', name: 'api_admin_reservations_cancel', methods: ['POST'])]
    public function cancel(string $ref, Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $reservation = $this->actions->find($admin, $ref);
        $body = JsonBody::object($request);
        $this->actions->cancel($admin, $reservation, (string) $this->text($body, 'reason', 3, 200, 'Le motif de l’annulation'));

        return $this->reservation($reservation);
    }

    /** POST /api/admin/reservations/{ref}/notes — `{text}` 2 à 1 000 caractères ; 201 `{note, internalNotes}`. */
    #[Route('/admin/reservations/{ref}/notes', name: 'api_admin_reservations_notes', methods: ['POST'])]
    public function addNote(string $ref, Request $request, #[CurrentUser] Admin $admin): JsonResponse
    {
        $reservation = $this->actions->find($admin, $ref);
        $body = JsonBody::object($request);
        $note = $this->actions->addNote($admin, $reservation, (string) $this->text($body, 'text', 2, 1000, 'La note'));

        return ApiJson::noStore(['note' => $this->normalizer->note($note), 'internalNotes' => $this->normalizer->notes($reservation)], 201);
    }

    /** `{reservation}` relue en base après l'action (historique, preuves et photos à jour). */
    private function reservation(Reservation $reservation): JsonResponse
    {
        $this->em->refresh($reservation);

        return ApiJson::noStore(['reservation' => $this->normalizer->detail($reservation, $this->lifecycle->now())]);
    }

    /** @return array<string, mixed> */
    private function detailResponse(Reservation $reservation, Admin $admin): array
    {
        $this->em->refresh($reservation);

        return $this->normalizer->detailResponse($reservation, $this->actions->availableAgencies($reservation), $admin->isSuperAdmin(), $this->lifecycle->now());
    }

    /**
     * Texte nettoyé (espaces, caractères invisibles) ; `$min` 0 : facultatif (null si vide).
     *
     * @param array<string, mixed> $body
     */
    private function text(array $body, string $field, int $min, int $max, string $label): ?string
    {
        $value = $body[$field] ?? null;
        if ($value !== null && !\is_string($value)) {
            throw ApiException::validation([$field => $label . ' doit être un texte.'], $label . ' doit être un texte.');
        }
        $text = TextInput::clean($value) ?? '';
        $length = TextInput::length($text);
        if ($min > 0 && $length < $min) {
            $message = sprintf('%s doit faire au moins %d caractères.', $label, $min);
            throw ApiException::validation([$field => $message], $message);
        }
        if ($length > $max) {
            $message = sprintf('%s ne doit pas dépasser %d caractères.', $label, $max);
            throw ApiException::validation([$field => $message], $message);
        }

        return $text !== '' ? $text : null;
    }
}
