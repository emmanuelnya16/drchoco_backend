<?php

namespace App\Reservation;

use App\Account\PhoneNumber;
use App\Account\TextInput;
use App\Api\ApiException;
use App\Entity\Reservation;
use App\Entity\ReservationProof;
use App\Entity\User;
use App\Media\ImageMetadataStripper;
use App\Media\PrivateMediaStorage;
use App\Repository\ReservationRepository;
use App\Settings\PublicSettingsProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /api/reservations/{ref}/proof (contrat §10.7) : capture du paiement Mobile Money.
 *
 * Ordre des contrôles (le front réagit au code) : 404 → 410 RESERVATION_EXPIRED → 422 INVALID_STATUS → fichier
 * (400, 413, 415 ; jamais 422, que le front prend pour INVALID_STATUS) → 409 DUPLICATE_PROOF (même capture déjà
 * envoyée pour une autre réservation). La capture est gardée en privé, sans ses métadonnées.
 */
final class ProofUploader
{
    private const MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const OPERATOR_NOTES = ['orange' => 'Capture Orange Money reçue.', 'mtn' => 'Capture MTN MoMo reçue.'];

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ReservationLifecycle $lifecycle,
        private readonly PrivateMediaStorage $storage,
        private readonly PublicSettingsProvider $settings,
        private readonly ReservationNotifier $notifier,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.proof_upload')]
        private readonly RateLimiterFactory $limiter,
    ) {}

    public function upload(User $user, string $ref, Request $request): Reservation
    {
        $reservation = $this->reservations->findOwned($user, $ref)
            ?? throw ApiException::notFound('RESERVATION_NOT_FOUND', 'Cette réservation est introuvable.');

        $limit = $this->limiter->create('user-' . $user->getId())->consume();
        if (!$limit->isAccepted()) {
            throw ApiException::tooManyRequests('TOO_MANY_REQUESTS', 'Trop d’envois de captures. Réessayez dans quelques minutes.', max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }

        $stored = null;
        try {
            $this->lifecycle->locked($reservation, function (Reservation $r, \DateTimeImmutable $now) use ($request, &$stored): void {
                if ($r->isOverdue($now)) {
                    $this->lifecycle->releaseStock($r);
                    $r->record(Reservation::STATUS_EXPIRED, Reservation::ACTOR_SYSTEM, 'Délai de paiement dépassé : articles remis en vente.', $now);
                }
                if ($r->getStatus() === Reservation::STATUS_EXPIRED) {
                    throw new ApiException(410, 'RESERVATION_EXPIRED', 'Le délai de paiement de cette réservation est dépassé : les articles ont été remis en vente.', ['status' => $r->getStatus()]);
                }
                if (!$r->isAwaitingPayment()) {
                    throw new ApiException(422, 'INVALID_STATUS', 'Cette réservation n’attend plus de preuve de paiement.', ['status' => $r->getStatus()]);
                }

                $input = $this->input($request);
                $other = $this->reservations->refWithProof($input['sha256'], (int) $r->getId());
                if ($other !== null) {
                    throw new ApiException(409, 'DUPLICATE_PROOF', 'Cette capture a déjà été envoyée pour une autre réservation. Envoyez la capture du paiement de celle-ci.');
                }

                $stored = $this->storage->store('proofs/' . $r->getId(), $input['extension'], $input['bytes']);
                foreach ($r->getProofs() as $previous) {
                    if ($previous->getStatus() === ReservationProof::STATUS_PENDING) {
                        $previous->setStatus(ReservationProof::STATUS_SUPERSEDED);
                    }
                }
                $r->addProof(new ReservationProof(
                    $r, $stored, $input['mime'], \strlen($input['bytes']), $input['width'], $input['height'],
                    $input['sha256'], $input['operator'], $input['payerPhone'], $input['transactionId'], $now,
                ));

                $resent = $r->getStatus() === Reservation::STATUS_PAYMENT_REJECTED;
                $r->setPaymentOperator($input['operator'])->setRejection(null, null);
                $r->record(
                    Reservation::STATUS_PENDING_VALIDATION,
                    Reservation::ACTOR_CLIENT,
                    $resent ? 'Nouvelle preuve envoyée.' : self::OPERATOR_NOTES[$input['operator']],
                    $now,
                );
            }, commitOnError: true);
        } catch (\Throwable $e) {
            if ($stored !== null) {
                $this->storage->delete($stored); // enregistrement annulé : pas de fichier orphelin
            }
            throw $e;
        }

        $this->notifier->proofReceived($reservation);

        return $reservation;
    }

    /**
     * Fichier et champs du formulaire, vérifiés.
     *
     * @return array{bytes: string, mime: string, extension: string, width: int, height: int, sha256: string, operator: string, payerPhone: ?string, transactionId: ?string}
     */
    private function input(Request $request): array
    {
        $maxMb = max(1, (int) ($this->settings->get()['payment']['proofMaxSizeMb'] ?? 10));
        $tooLarge = fn () => new ApiException(413, 'FILE_TOO_LARGE', sprintf('Image trop lourde : %d Mo au maximum.', $maxMb));

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            // Corps plus lourd que post_max_size : PHP vide le formulaire et le fichier.
            if ($request->request->count() === 0 && (int) $request->headers->get('Content-Length', '0') > 0) {
                throw $tooLarge();
            }
            throw $this->invalid(['file' => 'Ajoutez la capture de votre paiement.'], 'Ajoutez la capture de votre paiement.');
        }
        if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
            throw $tooLarge();
        }
        if ($file->getError() === \UPLOAD_ERR_PARTIAL) {
            throw new ApiException(400, 'UPLOAD_INCOMPLETE', 'L’envoi de la capture a été interrompu. Réessayez.');
        }
        if (!$file->isValid()) {
            $this->logger->error('Capture reçue mais non enregistrée par PHP (réglage du serveur).', ['error' => $file->getError()]);
            throw new ApiException(503, 'UPLOAD_FAILED', 'L’envoi de la capture a échoué. Réessayez dans quelques instants.');
        }
        if ($file->getSize() > $maxMb * 1024 * 1024) {
            throw $tooLarge();
        }

        $original = (string) file_get_contents($file->getPathname());
        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($original) ?: '';
        $info = isset(self::MIME_EXTENSIONS[$mime]) ? @getimagesizefromstring($original) : false;
        if (!isset(self::MIME_EXTENSIONS[$mime]) || $info === false || $info[0] < 1 || $info[1] < 1) {
            throw new ApiException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Format non accepté : envoyez la capture en JPEG, PNG ou WebP.');
        }
        $clean = ImageMetadataStripper::strip($original, $mime);
        if ($clean === null) {
            throw new ApiException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Cette image est illisible : refaites la capture puis réessayez.');
        }

        $errors = [];
        $operator = (string) $request->request->get('operator', '');
        $enabled = [];
        foreach ($this->settings->get()['payment']['operators'] as $op) {
            if ($op['enabled']) {
                $enabled[$op['id']] = true;
            }
        }
        if (!isset(self::OPERATOR_NOTES[$operator]) || !isset($enabled[$operator])) {
            $errors['operator'] = 'Choisissez l’opérateur utilisé pour payer (Orange Money ou MTN MoMo).';
        }

        $payerRaw = TextInput::clean($request->request->get('payerPhone')) ?? '';
        $payerPhone = null;
        if ($payerRaw !== '') {
            $payerPhone = PhoneNumber::normalizeCameroonMobile($payerRaw);
            if ($payerPhone === null) {
                $errors['payerPhone'] = 'Numéro de paiement invalide : un mobile camerounais (6XX XX XX XX).';
            }
        }

        $transactionId = TextInput::clean($request->request->get('transactionId')) ?? '';
        if (TextInput::length($transactionId) > 64) {
            $errors['transactionId'] = 'La référence de transaction ne doit pas dépasser 64 caractères.';
        }

        if ($errors !== []) {
            throw $this->invalid($errors, (string) reset($errors));
        }

        return [
            'bytes'         => $clean,
            'mime'          => $mime,
            'extension'     => self::MIME_EXTENSIONS[$mime],
            'width'         => (int) $info[0],
            'height'        => (int) $info[1],
            // Empreinte calculée ici sur le fichier reçu (celle du front n'est qu'indicative).
            'sha256'        => hash('sha256', $original),
            'operator'      => $operator,
            'payerPhone'    => $payerPhone,
            'transactionId' => $transactionId !== '' ? $transactionId : null,
        ];
    }

    /** @param array<string, string> $errors */
    private function invalid(array $errors, string $message): ApiException
    {
        return new ApiException(400, 'VALIDATION_ERROR', $message, $errors);
    }
}
