<?php

namespace App\Tests\Api\Reservation;

use App\Tests\ReservationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Lecture (fiche, listes, pastille), expiration (à la lecture et par la tâche planifiée), rappel, annulation.
 */
final class ReservationLifecycleTest extends ReservationTestCase
{
    public function testListsSummaryAndOwnership(): void
    {
        $token = $this->loginNamed();
        $first = $this->reserve($token, $this->pickup());
        $this->wait(60);
        $second = $this->reserve($token, $this->pickup(self::DLA_VARIANT, 2));
        $this->forceStatus($first['ref'], 'confirmed');

        $pending = $this->api('GET', '/api/reservations?status=reserved', $token);
        self::assertSame(200, $this->httpStatus());
        $headers = $this->client->getResponse()->headers;
        self::assertSame('no-cache, private', $headers->get('Cache-Control'));
        self::assertStringNotContainsString('Authorization', (string) $headers->get('Vary'), 'copie hors connexion du front');
        self::assertSame([$second['ref']], array_column($pending, 'ref'));
        self::assertArrayHasKey('timeline', $pending[0], 'fiches complètes');

        $active = $this->api('GET', '/api/reservations?scope=active&page=1', $token);
        self::assertSame(['items', 'page', 'pageSize', 'total', 'totalPages', 'counts'], array_keys($active));
        self::assertSame([1, 10, 2, 1, ['active' => 2, 'done' => 0]], [$active['page'], $active['pageSize'], $active['total'], $active['totalPages'], $active['counts']]);
        self::assertSame([$second['ref'], $first['ref']], array_column($active['items'], 'ref'), 'dernière mise à jour d’abord');
        $item = $active['items'][0];
        self::assertSame(['ref', 'status', 'createdAt', 'updatedAt', 'serverTime', 'amount', 'itemsCount', 'itemsPreview', 'packagePhotoThumb', 'receptionMode', 'city', 'shopCity', 'expiresAt'], array_keys($item));
        self::assertSame([['total' => 36000], 2, 'Douala'], [$item['amount'], $item['itemsCount'], $item['city']]);
        self::assertArrayNotHasKey('expiresAt', $active['items'][1], 'payée : plus de compte à rebours');
        self::assertSame([], $this->api('GET', '/api/reservations?scope=done', $token)['items']);

        $summary = $this->api('GET', '/api/reservations/summary', $token);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(1, $summary['needsAction']);
        self::assertSame([$second['ref'], $first['ref']], array_column($summary['items'], 'ref'));
        self::assertSame(['ref', 'status', 'updatedAt', 'hasPackagePhotos'], array_keys($summary['items'][0]));

        // Un autre client ne voit rien : 404, jamais 403.
        $other = $this->loginNamed('+237690000002', 'Paul');
        $data = $this->api('GET', '/api/reservations/' . $first['ref'], $other);
        self::assertSame(404, $this->httpStatus());
        self::assertSame('RESERVATION_NOT_FOUND', $data['code']);
        $this->api('POST', '/api/reservations/' . $first['ref'] . '/cancel', $other);
        self::assertSame(404, $this->httpStatus());
        self::assertSame([], $this->api('GET', '/api/reservations?status=reserved', $other));
        $this->api('GET', '/api/reservations/RES-ABC', $token);
        self::assertSame(404, $this->httpStatus());
    }

    public function testUnpaidReservationExpiresAtTheDeadline(): void
    {
        $token = $this->loginNamed();
        $data = $this->reserve($token, $this->pickup(quantity: 3));
        self::assertSame(1, $this->stock(self::YDE_VARIANT));

        $this->wait(24 * 3600 - 1);
        self::assertSame('reserved', $this->api('GET', '/api/reservations/' . $data['ref'], $token)['status']);

        $this->wait(1);
        $detail = $this->api('GET', '/api/reservations/' . $data['ref'], $token);
        self::assertSame(['expired', false], [$detail['status'], $detail['cancellable']]);
        self::assertSame(['status' => 'expired', 'at' => $detail['updatedAt'], 'note' => 'Délai de paiement dépassé : articles remis en vente.', 'actor' => 'system'], end($detail['timeline']));
        self::assertSame(4, $this->stock(self::YDE_VARIANT), 'articles remis en vente');

        self::assertSame([], $this->api('GET', '/api/reservations?status=reserved', $token));
        $done = $this->api('GET', '/api/reservations?scope=done', $token);
        self::assertSame([['ref' => $data['ref'], 'reachedStatus' => 'reserved']], array_map(fn (array $i) => ['ref' => $i['ref'], 'reachedStatus' => $i['reachedStatus']], $done['items']));

        // Plus rien ne la fait revivre : annulation refusée, stock intact.
        $data = $this->api('POST', '/api/reservations/' . $data['ref'] . '/cancel', $token, []);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(['CANCEL_NOT_ALLOWED', 'expired'], [$data['code'], $data['details']['status']]);
        self::assertSame(4, $this->stock(self::YDE_VARIANT));
    }

    public function testScheduledExpiryAndReminder(): void
    {
        $token = $this->loginNamed();
        $unpaid = $this->reserve($token, $this->pickup(quantity: 2));
        $waiting = $this->reserve($token, $this->pickup(self::DLA_VARIANT, 1));
        $this->forceStatus($waiting['ref'], 'pending_validation');

        $this->wait(22 * 3600 + 30 * 60);
        self::assertStringContainsString('1 rappel(s)', $this->command('app:reservations:remind'));
        self::assertStringContainsString('0 rappel(s)', $this->command('app:reservations:remind'), 'une seule fois');

        $this->wait(2 * 3600);
        self::assertStringContainsString('1 réservation(s) expirée(s)', $this->command('app:reservations:expire'));
        self::assertStringContainsString('0 réservation(s) expirée(s)', $this->command('app:reservations:expire'));
        self::assertSame(4, $this->stock(self::YDE_VARIANT), 'remis une seule fois');
        self::assertSame(7, $this->stock(self::DLA_VARIANT), 'en attente de validation : n’expire jamais');
        self::assertSame('expired', $this->api('GET', '/api/reservations/' . $unpaid['ref'], $token)['status']);
        self::assertSame('pending_validation', $this->api('GET', '/api/reservations/' . $waiting['ref'], $token)['status']);
    }

    public function testClientCancellation(): void
    {
        $token = $this->loginNamed();
        $data = $this->reserve($token, $this->pickup(quantity: 2));

        // Écran de paiement : aucun corps.
        $this->client->request('POST', '/api/reservations/' . $data['ref'] . '/cancel', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json']);
        $result = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['status', 'reservation'], array_keys($result));
        self::assertSame(['cancelled', 'cancelled', false], [$result['status'], $result['reservation']['status'], $result['reservation']['cancellable']]);
        self::assertSame(['status' => 'cancelled', 'at' => $result['reservation']['updatedAt'], 'note' => 'Annulée par le client.', 'actor' => 'client'], end($result['reservation']['timeline']));
        self::assertSame(4, $this->stock(self::YDE_VARIANT));

        $again = $this->api('POST', '/api/reservations/' . $data['ref'] . '/cancel', $token, []);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('CANCEL_NOT_ALLOWED', $again['code']);
        self::assertSame(4, $this->stock(self::YDE_VARIANT), 'stock remis une seule fois');

        // Payée (confirmée) : encore annulable, avec un motif ; en préparation : non.
        $paid = $this->reserve($token, $this->pickup());
        $this->forceStatus($paid['ref'], 'confirmed');
        $result = $this->api('POST', '/api/reservations/' . $paid['ref'] . '/cancel', $token, ['reason' => 'Je me suis trompé de taille']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('Je me suis trompé de taille', end($result['reservation']['timeline'])['note']);

        $late = $this->reserve($token, $this->pickup());
        $this->forceStatus($late['ref'], 'preparing');
        $result = $this->api('POST', '/api/reservations/' . $late['ref'] . '/cancel', $token, ['reason' => 'Trop tard']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(['CANCEL_NOT_ALLOWED', 'Votre réservation est déjà en préparation et ne peut plus être annulée ici.', 'preparing'], [$result['code'], $result['message'], $result['details']['status']]);
        self::assertSame(3, $this->stock(self::YDE_VARIANT));
    }

    private function command(string $name): string
    {
        $tester = new CommandTester((new Application(static::$kernel))->find($name));
        $tester->execute([]);

        return $tester->getDisplay();
    }
}
