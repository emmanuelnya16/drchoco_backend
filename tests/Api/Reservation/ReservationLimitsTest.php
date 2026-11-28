<?php

namespace App\Tests\Api\Reservation;

use App\Tests\ReservationTestCase;

/**
 * POST /api/reservations, protections de la P9 : montant confirmé par le client, plafonds par client, créations par
 * heure, demandes malformées, popularité rendue quand la réservation est annulée ou expire.
 */
final class ReservationLimitsTest extends ReservationTestCase
{
    public function testTheAmountConfirmedByTheClientMustStillBeRight(): void
    {
        $token = $this->loginNamed();
        $db = $this->em()->getConnection();
        $body = $this->pickup(quantity: 2, override: ['expectedTotal' => 90000]);

        // Le prix change entre l'affichage et la confirmation : rien n'est réservé.
        $db->executeStatement("UPDATE products SET price = 52000 WHERE id = 'prod-01'");
        $this->em()->clear();
        $data = $this->reserve($token, $body, 'cle-prix-change');
        self::assertSame([409, 'PRICE_CHANGED'], [$this->httpStatus(), $data['code']]);
        self::assertSame(['expectedTotal' => 90000, 'total' => 104000, 'subtotal' => 104000, 'deliveryFee' => 0, 'coinsDiscount' => 0, 'items' => [['variantId' => self::YDE_VARIANT, 'unitPrice' => 52000]]], $data['details']);
        self::assertStringContainsString("104\u{202F}000\u{00A0}FCFA au lieu de 90\u{202F}000\u{00A0}FCFA", $data['message']);
        self::assertSame(4, $this->stock(self::YDE_VARIANT));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM reservations'));

        // La même clé n'est pas consommée : le client confirme le nouveau montant.
        $data = $this->reserve($token, ['expectedTotal' => 104000] + $body, 'cle-prix-change');
        self::assertSame([201, 104000], [$this->httpStatus(), $data['total'] ?? null], json_encode($data));

        // Livraison : frais compris quand ils se payent tout de suite, pas quand ils se payent à la livraison.
        $home = $this->pickup(override: ['receptionMode' => 'delivery', 'city' => 'Yaoundé', 'district' => 'Bastos', 'landmark' => 'Portail noir après la pharmacie']);
        $data = $this->reserve($token, $home + ['expectedTotal' => 52000]);
        self::assertSame(['PRICE_CHANGED', 53500, 1500], [$data['code'], $data['details']['total'], $data['details']['deliveryFee']]);
        $this->reserve($token, $home + ['expectedTotal' => 53500]);
        self::assertSame(201, $this->httpStatus());

        foreach (['53500', 53500.5, -1, [53500], true] as $wrong) {
            $data = $this->reserve($token, $home + ['expectedTotal' => $wrong]);
            self::assertSame([422, 'VALIDATION_ERROR'], [$this->httpStatus(), $data['code']], json_encode($wrong));
            self::assertArrayHasKey('expectedTotal', $data['details']);
        }
        // Champ absent (ancienne version de l'app) : accepté.
        $this->reserve($token, $this->pickup(self::DLA_VARIANT));
        self::assertSame(201, $this->httpStatus());
    }

    public function testDeliveryPaidOnArrivalIsNotInTheAmountToPayNow(): void
    {
        $token = $this->loginNamed();
        $settings = $this->em()->getConnection();
        $value = json_decode((string) $settings->fetchOne("SELECT value FROM app_settings WHERE name = 'public'"), true);
        $settings->executeStatement("UPDATE app_settings SET value = ? WHERE name = 'public'", [json_encode(['deliveryFeePayment' => 'on_delivery'] + $value)]);
        $this->em()->clear();

        $data = $this->reserve($token, $this->pickup(override: ['receptionMode' => 'delivery', 'city' => 'Yaoundé', 'district' => 'Bastos', 'landmark' => 'Portail noir après la pharmacie', 'expectedTotal' => 45000]));
        self::assertSame([201, 45000, 1500], [$this->httpStatus(), $data['total'] ?? null, $data['deliveryFee'] ?? null], json_encode($data));
    }

    public function testPiecesPerReservationAreLimited(): void
    {
        $token = $this->loginNamed();
        $this->em()->getConnection()->executeStatement('UPDATE product_variants SET stock = 200');
        $this->em()->clear();

        $data = $this->reserve($token, $this->pickup(quantity: 11));
        self::assertSame([422, 'TOO_MANY_ITEMS', ['max' => 30, 'perLine' => 10]], [$this->httpStatus(), $data['code'], $data['details']]);
        // Deux lignes du même article comptent ensemble.
        $data = $this->reserve($token, $this->pickup(override: ['items' => [['variantId' => self::YDE_VARIANT, 'quantity' => 6], ['variantId' => self::YDE_VARIANT, 'quantity' => 5]]]));
        self::assertSame('TOO_MANY_ITEMS', $data['code']);
        $lines = array_map(fn (string $size) => ['variantId' => 'prod-01-noir-' . $size, 'quantity' => 8], ['s', 'm', 'l', 'xl']);
        self::assertSame('TOO_MANY_ITEMS', $this->reserve($token, $this->pickup(override: ['items' => $lines]))['code'], '32 pièces');
        self::assertSame(200, $this->stock(self::YDE_VARIANT));

        $this->reserve($token, $this->pickup(quantity: 10));
        self::assertSame(201, $this->httpStatus());
    }

    public function testOpenReservationsAndTheirPiecesAreLimited(): void
    {
        $token = $this->loginNamed();
        $db = $this->em()->getConnection();
        $db->executeStatement('UPDATE product_variants SET stock = 200');
        $this->em()->clear();
        $thirty = fn () => $this->pickup(override: ['items' => array_map(fn (string $size) => ['variantId' => 'prod-01-noir-' . $size, 'quantity' => 10], ['s', 'm', 'l'])]);

        $first = $this->reserve($token, $thirty());
        self::assertSame(201, $this->httpStatus(), json_encode($first));
        $this->reserve($token, $thirty());
        self::assertSame(201, $this->httpStatus());
        $data = $this->reserve($token, $this->pickup());
        self::assertSame([422, 'TOO_MANY_ITEMS', 60, 60], [$this->httpStatus(), $data['code'], $data['details']['open'], $data['details']['max']]);

        // Une preuve envoyée ne libère pas de place (elle n'est pas encore vérifiée) ; une annulation, si.
        $this->forceStatus($first['ref'], 'pending_validation');
        self::assertSame('TOO_MANY_ITEMS', $this->reserve($token, $this->pickup())['code']);
        $this->api('POST', '/api/reservations/' . $first['ref'] . '/cancel', $token, []);
        self::assertSame(200, $this->httpStatus());
        foreach (range(1, 4) as $n) {
            $this->reserve($token, $this->pickup());
            self::assertSame(201, $this->httpStatus(), (string) $n);
        }
        $data = $this->reserve($token, $this->pickup());
        self::assertSame([422, 'TOO_MANY_OPEN_RESERVATIONS', ['max' => 5, 'open' => 5]], [$this->httpStatus(), $data['code'], $data['details']]);

        // Un autre client n'est pas concerné ; payée et confirmée, une réservation ne compte plus.
        $this->reserve($this->loginNamed('+237690000002', 'Paul'), $this->pickup(override: ['phone' => '+237690000002']));
        self::assertSame(201, $this->httpStatus());
        $db->executeStatement("UPDATE reservations SET status = 'confirmed' WHERE status = 'reserved' AND customer_phone = ? LIMIT 1", [self::PHONE]);
        $this->reserve($token, $this->pickup());
        self::assertSame(201, $this->httpStatus());

        // Échues (pas encore passées à « expirée » en base) : elles ne comptent plus.
        self::assertSame('TOO_MANY_OPEN_RESERVATIONS', $this->reserve($token, $this->pickup())['code']);
        $this->wait(24 * 3600);
        $token = $this->login()['token'];
        $this->reserve($token, $this->pickup());
        self::assertSame(201, $this->httpStatus());
    }

    public function testTenCreationsPerHourRefusalsExcluded(): void
    {
        $token = $this->loginNamed();
        foreach (range(1, 10) as $n) {
            $data = $this->reserve($token, $this->pickup(self::DLA_VARIANT));
            self::assertSame(201, $this->httpStatus(), (string) $n);
            $this->api('POST', '/api/reservations/' . $data['ref'] . '/cancel', $token, []);
            // Refus (stock insuffisant, montant changé) : ne compte pas.
            self::assertSame(409, $this->statusOf($token, $this->pickup(quantity: 9)));
            self::assertSame(409, $this->statusOf($token, $this->pickup(override: ['expectedTotal' => 1])));
        }
        $data = $this->reserve($token, $this->pickup(self::DLA_VARIANT));
        self::assertSame([429, 'RATE_LIMITED'], [$this->httpStatus(), $data['code']]);
        self::assertGreaterThan(0, $data['details']['retryAfter']);
        self::assertSame((string) $data['details']['retryAfter'], $this->header('Retry-After'));
        self::assertStringStartsWith('Trop de réservations en peu de temps. Réessayez dans ', $data['message']);
        self::assertSame(8, $this->stock(self::DLA_VARIANT));
    }

    public function testAReusedKeyLeadsToTheExistingReservation(): void
    {
        $token = $this->loginNamed();
        $first = $this->reserve($token, $this->pickup(), 'cle-reponse-perdue');
        $data = $this->reserve($token, $this->pickup(override: ['notes' => 'Autre remarque']), 'cle-reponse-perdue');
        self::assertSame([422, 'IDEMPOTENCY_KEY_REUSED', ['ref' => $first['ref']]], [$this->httpStatus(), $data['code'], $data['details']]);
        // La même demande renvoyée donne la même réservation, même avec le plafond atteint.
        self::assertSame($first['ref'], $this->reserve($token, $this->pickup(), 'cle-reponse-perdue')['ref']);
    }

    public function testMalformedRequestsNeverBreakTheServer(): void
    {
        $token = $this->loginNamed();
        // Identifiants en chiffres : comparés comme du texte.
        $data = $this->reserve($token, $this->pickup(override: ['items' => [['variantId' => '1', 'quantity' => 1], ['variantId' => 'x', 'quantity' => 1]]]));
        self::assertSame([409, 'STOCK_CONFLICT', ['1', 'x']], [$this->httpStatus(), $data['code'], array_column($data['details']['items'], 'variantId')]);
        $data = $this->reserve($token, $this->pickup(override: ['items' => [['variantId' => 1, 'quantity' => 1]]]));
        self::assertSame([409, 'STOCK_CONFLICT'], [$this->httpStatus(), $data['code']]);

        // Nombre infini dans un champ ignoré : la réservation se fait (il ne sert qu'à l'empreinte de la demande).
        $json = str_replace('"INFINI"', '1e999', json_encode($this->pickup(override: ['total' => 'INFINI']), \JSON_THROW_ON_ERROR));
        $this->client->request('POST', '/api/reservations', [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'cle-nombre-infini'], $json);
        self::assertSame(201, $this->httpStatus(), (string) $this->client->getResponse()->getContent());

        // Prénom long : accepté pour la réservation, raccourci comme dans le formulaire du profil.
        $other = $this->login('+237690000002')['token'];
        $this->reserve($other, $this->pickup(override: ['firstName' => str_repeat('Ã', 90), 'lastName' => str_repeat('b', 100), 'phone' => '+237690000002']));
        self::assertSame(201, $this->httpStatus());
        $me = $this->api('GET', '/api/me', $other);
        self::assertSame([str_repeat('Ã', 40), str_repeat('b', 40)], [$me['firstName'], $me['lastName']]);
    }

    public function testPopularityIsGivenBackWhenTheReservationEnds(): void
    {
        $token = $this->loginNamed();
        $count = fn (): int => (int) $this->em()->getConnection()->fetchOne("SELECT reservations_count FROM products WHERE id = 'prod-01'");
        $start = $count();

        $cancelled = $this->reserve($token, $this->pickup());
        $expired = $this->reserve($token, $this->pickup());
        $kept = $this->reserve($token, $this->pickup());
        self::assertSame($start + 3, $count());
        $this->api('POST', '/api/reservations/' . $cancelled['ref'] . '/cancel', $token, []);
        self::assertSame($start + 2, $count());
        $this->forceStatus($kept['ref'], 'confirmed');
        $this->wait(24 * 3600);
        $token = $this->login()['token'];
        self::assertSame('expired', $this->api('GET', '/api/reservations/' . $expired['ref'], $token)['status']);
        self::assertSame($start + 1, $count());

        // Jamais négative (compteur remis à zéro entre-temps).
        $this->em()->getConnection()->executeStatement("UPDATE products SET reservations_count = 0 WHERE id = 'prod-01'");
        $this->api('POST', '/api/reservations/' . $kept['ref'] . '/cancel', $token, []);
        self::assertSame([200, 0], [$this->httpStatus(), $count()]);
    }

    public function testOnlyThePendingListCanBeAskedByStatus(): void
    {
        $token = $this->loginNamed();
        $this->reserve($token, $this->pickup());
        self::assertCount(1, $this->api('GET', '/api/reservations?status=reserved', $token));
        foreach (['cancelled', 'confirmed', 'inconnu'] as $status) {
            $data = $this->api('GET', '/api/reservations?status=' . $status, $token);
            self::assertSame([422, 'VALIDATION_ERROR'], [$this->httpStatus(), $data['code'] ?? null], $status);
        }
    }

    /** @param array<string, mixed> $body */
    private function statusOf(string $token, array $body): int
    {
        $this->reserve($token, $body);

        return $this->httpStatus();
    }
}
