<?php

namespace App\Tests\Api\Reservation;

use App\Tests\ReservationTestCase;

/**
 * POST /api/reservations/{ref}/proof et lien signé de la capture (GET /api/media/private/proofs/{id}).
 */
final class ProofTest extends ReservationTestCase
{
    public function testProofIsStoredPrivatelyWithoutMetadata(): void
    {
        $token = $this->loginNamed();
        $ref = $this->reserve($token, $this->pickup())['ref'];
        $upload = self::pngWithText('Comment', 'GPS 3.8480,11.5021');

        $data = $this->uploadProof($token, $ref, $this->file($upload), [
            'operator' => 'orange', 'payerPhone' => '690 00 00 01', 'transactionId' => 'PP241030.1142.A12345', 'sha256' => 'calcul-du-front',
        ]);

        self::assertSame(201, $this->httpStatus());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame(['status', 'proof', 'reservation'], array_keys($data));
        self::assertSame('pending_validation', $data['status']);
        self::assertSame(hash('sha256', $upload), $data['proof']['sha256'], 'empreinte calculée par le serveur');
        self::assertSame(['orange', '+237690000001', 'PP241030.1142.A12345'], [$data['proof']['operator'], $data['proof']['payerPhone'], $data['proof']['transactionId']]);
        self::assertSame(['pending_validation', true], [$data['reservation']['status'], $data['reservation']['cancellable']]);
        self::assertSame('Capture Orange Money reçue.', end($data['reservation']['timeline'])['note']);
        self::assertSame($data['proof'], $data['reservation']['proof']);

        // Lien signé : l'image, sans la métadonnée, jamais mise en cache par le service worker (« /private/ »).
        $url = $data['proof']['thumbnailUrl'];
        self::assertMatchesRegularExpression('#^https?://[^/]+/api/media/private/proofs/\d+\?expires=\d+&signature=[0-9a-f]{64}$#', $url);
        $this->client->request('GET', $url);
        $response = $this->client->getInternalResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->getHeader('Content-Type'));
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        $image = (string) $response->getContent();
        self::assertStringNotContainsString('GPS', $image);
        self::assertSame(base64_decode(self::PNG), $image);

        // Lien modifié ou expiré : refusé.
        $this->client->request('GET', preg_replace('/signature=[0-9a-f]{4}/', 'signature=0000', $url));
        self::assertSame(403, $this->httpStatus());
        $this->wait(71 * 60);
        $this->client->request('GET', $url);
        self::assertSame(403, $this->httpStatus());
        $fresh = $this->api('GET', '/api/reservations/' . $ref, $token)['proof']['thumbnailUrl'];
        self::assertNotSame($url, $fresh, 'nouveau lien à chaque lecture');
        $this->client->request('GET', $fresh);
        self::assertSame(200, $this->httpStatus());

        // Déjà en attente de validation : 422 INVALID_STATUS (le front recharge la réservation).
        $data = $this->uploadProof($token, $ref, $this->file());
        self::assertSame(422, $this->httpStatus());
        self::assertSame(['INVALID_STATUS', 'pending_validation'], [$data['code'], $data['details']['status']]);
    }

    public function testFileProblemsAreNever422(): void
    {
        $token = $this->loginNamed();
        $ref = $this->reserve($token, $this->pickup())['ref'];

        foreach ([
            [null, ['operator' => 'orange'], 400, 'VALIDATION_ERROR'],
            [$this->file(), ['operator' => 'wave'], 400, 'VALIDATION_ERROR'],
            [$this->file(), ['operator' => 'mtn', 'payerPhone' => '12345'], 400, 'VALIDATION_ERROR'],
            [$this->file('pas une image', 'capture.jpg', 'image/jpeg'), ['operator' => 'orange'], 415, 'UNSUPPORTED_MEDIA_TYPE'],
            [$this->file(str_repeat('a', 10 * 1024 * 1024 + 1)), ['operator' => 'orange'], 413, 'FILE_TOO_LARGE'],
        ] as $i => [$file, $fields, $status, $code]) {
            $data = $this->uploadProof($token, $ref, $file, $fields);
            self::assertSame([$status, $code], [$this->httpStatus(), $data['code'] ?? null], "cas $i");
            self::assertNotSame('', $data['message']);
        }
        self::assertSame('reserved', $this->api('GET', '/api/reservations/' . $ref, $token)['status']);

        $this->uploadProof($this->loginNamed('+237690000002', 'Paul'), $ref, $this->file());
        self::assertSame(404, $this->httpStatus(), 'réservation d’un autre client');
    }

    public function testSameCaptureCannotPayTwoReservationsButCanBeResent(): void
    {
        $token = $this->loginNamed();
        $first = $this->reserve($token, $this->pickup())['ref'];
        $second = $this->reserve($token, $this->pickup())['ref'];
        $capture = self::pngWithText('Comment', 'capture A');

        $this->uploadProof($token, $first, $this->file($capture));
        self::assertSame(201, $this->httpStatus());
        $data = $this->uploadProof($token, $second, $this->file($capture));
        self::assertSame(409, $this->httpStatus());
        self::assertSame('DUPLICATE_PROOF', $data['code']);

        // Paiement rejeté par la boutique (P6) : nouvelle capture acceptée, même la précédente.
        $this->forceStatus($first, 'payment_rejected', ['rejection_reason' => 'Montant incorrect', 'rejected_at' => $this->clock->now()->format('Y-m-d H:i:s')]);
        $rejected = $this->api('GET', '/api/reservations/' . $first, $token);
        self::assertSame(['Montant incorrect', 'Montant incorrect'], [$rejected['rejection']['reason'], $rejected['rejectionReason']]);
        self::assertSame(1, \count($this->api('GET', '/api/reservations?status=reserved', $token)) - 1, 'rejetée : toujours dans la liste à payer');

        $data = $this->uploadProof($token, $first, $this->file($capture), ['operator' => 'mtn']);
        self::assertSame(201, $this->httpStatus());
        self::assertSame(['pending_validation', 'mtn', 'Nouvelle preuve envoyée.'], [$data['status'], $data['proof']['operator'], end($data['reservation']['timeline'])['note']]);
        self::assertArrayNotHasKey('rejection', $data['reservation']);
    }

    public function testProofAfterTheDeadlineExpiresTheReservation(): void
    {
        $token = $this->loginNamed();
        $ref = $this->reserve($token, $this->pickup(quantity: 2))['ref'];
        $this->wait(24 * 3600);

        $data = $this->uploadProof($token, $ref, $this->file());
        self::assertSame(410, $this->httpStatus());
        self::assertSame('RESERVATION_EXPIRED', $data['code']);
        self::assertSame(4, $this->stock(self::YDE_VARIANT));
        self::assertSame('expired', $this->api('GET', '/api/reservations/' . $ref, $token)['status']);
    }

    public function testUploadsAreLimitedPerHour(): void
    {
        $token = $this->loginNamed();
        $ref = $this->reserve($token, $this->pickup())['ref'];
        for ($i = 0; $i < 20; ++$i) {
            $this->uploadProof($token, $ref, null);
            self::assertSame(400, $this->httpStatus());
        }
        $data = $this->uploadProof($token, $ref, $this->file());
        self::assertSame(429, $this->httpStatus());
        self::assertGreaterThan(0, $data['details']['retryAfter']);
    }
}
