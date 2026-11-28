<?php

namespace App\Reservation;

use App\Account\PhoneNumber;
use App\Account\TextInput;
use App\Api\ApiException;
use App\Controller\Account\ProfileController;
use App\Entity\Agency;
use App\Entity\DeliveryCity;
use App\Entity\ProductVariant;
use App\Entity\Reservation;
use App\Entity\ReservationItem;
use App\Entity\User;
use App\Repository\AgencyRepository;
use App\Repository\DeliveryCityRepository;
use App\Repository\ProductVariantRepository;
use App\Repository\ReservationRepository;
use App\Settings\PublicSettingsProvider;
use App\Wallet\CoinWallet;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * POST /api/reservations (contrat §10.1, décisions du 2026-11-16).
 *
 * - Les prix, les frais et le total sont recalculés ici (jamais repris du front).
 * - Un panier = une seule ville de boutique (422 MIXED_CITIES sinon).
 * - Retrait : à la boutique de la ville des articles. Livraison : à domicile dans cette ville, sinon par une
 *   agence de la ville de destination qui dessert cette boutique, au prix de l'agence.
 * - Le stock est retiré sous verrou : deux clients ne peuvent pas réserver la dernière pièce (409 STOCK_CONFLICT,
 *   avec les lignes au format de /cart/validate).
 * - Idempotency-Key : la même demande renvoyée (réseau coupé, double appui) donne la même réservation.
 * - Montant vu par le client (`expectedTotal`) différent du montant calculé : 409 PRICE_CHANGED, rien n'est réservé.
 * - Un seul compte ne peut pas bloquer le stock des boutiques (P9) : pièces par article, par réservation et en tout dans
 *   les réservations non payées ou à vérifier, nombre de ces réservations, créations par heure et par jour.
 */
final class ReservationCreator
{
    /** Pièces d'un même article (taille et couleur) dans une réservation. */
    public const MAX_PER_VARIANT = 10;
    /** Pièces d'une réservation. */
    public const MAX_PIECES = 30;
    /** Réservations ouvertes d'un client : paiement attendu, preuve à vérifier ou paiement rejeté. */
    public const MAX_OPEN = 5;
    /** Pièces de toutes ces réservations ouvertes. */
    public const MAX_OPEN_PIECES = 60;

    private const MAX_LINES = 100;
    private const MAX_QUANTITY = 999;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationRepository $reservations,
        private readonly ProductVariantRepository $variants,
        private readonly DeliveryCityRepository $cities,
        private readonly AgencyRepository $agencies,
        private readonly DeliveryOptions $delivery,
        private readonly PublicSettingsProvider $settings,
        private readonly ReservationNotifier $notifier,
        private readonly CoinWallet $wallet,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.reservation_create')]
        private readonly RateLimiterFactoryInterface $hourLimiter,
        #[Autowire(service: 'limiter.reservation_create_day')]
        private readonly RateLimiterFactoryInterface $dayLimiter,
    ) {}

    /**
     * @param array<string, mixed> $body
     *
     * @return array{reservation: Reservation, replayed: bool}
     */
    public function create(User $user, array $body, ?string $idempotencyKey): array
    {
        $key = trim((string) $idempotencyKey);
        if (preg_match('/^[A-Za-z0-9-]{8,64}\z/', $key) !== 1) {
            throw ApiException::validation(['idempotencyKey' => 'Clé de requête manquante ou invalide.'], 'La demande est incomplète : rechargez la page puis réessayez.');
        }
        $hash = hash('sha256', json_encode(self::canonical($body), \JSON_THROW_ON_ERROR));
        if (($replay = $this->replay($user, $key, $hash)) !== null) {
            return ['reservation' => $replay, 'replayed' => true];
        }

        $lines = $this->lines($body['items'] ?? null);
        $this->assertReasonable($lines);
        $found = $this->variants->findWithProducts(array_map('strval', array_keys($lines)));

        $shopCity = $this->shopCity($lines, $found);
        $errors = [];
        $plan = $this->fulfilment($user, $body, $shopCity, $errors);
        if ($errors !== []) {
            throw ApiException::validation($errors, (string) reset($errors));
        }

        // Contrôle rapide avant le verrou (article retiré, stock déjà insuffisant).
        foreach ($lines as $id => $line) {
            if (CartCheck::line($line, $found[$id] ?? null)['status'] !== 'ok') {
                $this->stockConflict($lines, $found);
            }
        }

        // Pièces du portefeuille (« Utiliser mes pièces ») : réduction sur les articles, solde revérifié sous verrou
        $useCoins = $body['useCoins'] ?? false;
        if (!\is_bool($useCoins)) {
            throw ApiException::validation(['useCoins' => 'Choix des pièces invalide.'], 'La demande est incomplète : rechargez la page puis réessayez.');
        }
        $plan['amounts'] = $this->amounts($lines, $found, (int) $plan['fee'], $useCoins ? $user->getCoins() : 0);
        $this->assertExpectedTotal($body, $plan['amounts'], $lines, $found);

        $now = $this->now();
        $ids = array_map('strval', array_keys($lines));
        sort($ids, \SORT_STRING);
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $outcome = $this->insideTransaction($user, $body, $plan, $lines, $ids, $found, $shopCity, $key, $hash, $now);
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        if ($outcome === null) {
            $connection->rollBack();
            $this->stockConflict($lines, $found, $this->freshStocks($ids));
        }
        $connection->commit();

        if (!$outcome['replayed']) {
            $this->notifier->created($outcome['reservation']);
        }

        return $outcome;
    }

    /**
     * @param array<string, mixed>                                  $body
     * @param array<string, mixed>                                  $plan
     * @param array<string, array{variantId: string, quantity: int}> $lines
     * @param list<string>                                          $ids variantes, dans l'ordre de verrouillage
     * @param array<string, ProductVariant>                         $found
     *
     * @return array{reservation: Reservation, replayed: bool}|null null : stock insuffisant (à annuler)
     */
    private function insideTransaction(User $user, array $body, array $plan, array $lines, array $ids, array $found, string $shopCity, string $key, string $hash, \DateTimeImmutable $now): ?array
    {
        $connection = $this->em->getConnection();

        // Une demande à la fois par client : deux envois de la même clé ne créent jamais deux réservations. Le solde de
        // pièces relu sous ce verrou : pas de double dépense entre deux réservations simultanées.
        $balance = (int) $connection->fetchOne('SELECT coins FROM users WHERE id = ? FOR UPDATE', [$user->getId()]);
        if (($replay = $this->replay($user, $key, $hash)) !== null) {
            return ['reservation' => $replay, 'replayed' => true];
        }
        if ($plan['amounts']['coinsUsed'] > $balance) {
            throw new ApiException(409, 'COINS_CHANGED', 'Votre solde de pièces a changé. Vérifiez votre réservation puis confirmez à nouveau.', ['coins' => $balance]);
        }
        // Sous le verrou du client : le compte est juste même avec des demandes en parallèle.
        $this->assertWithinOpenLimits($user, $lines, $now);
        $limiters = [$this->hourLimiter->create('user-' . $user->getId()), $this->dayLimiter->create('user-' . $user->getId())];
        $this->assertNotTooMany($limiters);

        // Popularité (tri « les plus réservés ») : articles verrouillés avant leurs variantes, dans le même ordre
        // que l'enregistrement d'un article par l'administration (pas d'interblocage entre les deux).
        $productIds = array_values(array_unique(array_map(fn (ProductVariant $v) => $v->getProduct()->getId(), array_values(array_intersect_key($found, $lines)))));
        sort($productIds, \SORT_STRING);
        $connection->executeStatement(
            'UPDATE products SET reservations_count = reservations_count + 1 WHERE id IN (?)',
            [$productIds],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );

        // Retrait sous condition : la ligne n'est modifiée que s'il reste assez de pièces (ordre fixe : pas
        // d'interblocage entre deux réservations des mêmes articles).
        foreach ($ids as $id) {
            $updated = $connection->executeStatement(
                'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?',
                [$lines[$id]['quantity'], $id, $lines[$id]['quantity']],
            );
            if ($updated !== 1) {
                return null;
            }
        }

        $reservation = $this->build($user, $body, $plan, $lines, $found, $shopCity, $key, $hash, $now);
        $this->em->persist($reservation);
        $this->em->flush();
        $reservation->setRef(sprintf('RES-%06d', $reservation->getId()));
        $this->wallet->spend($user, $reservation, $balance, $now);
        $this->em->flush();
        foreach ($limiters as $limiter) {
            $limiter->consume();
        }

        return ['reservation' => $reservation, 'replayed' => false];
    }

    private function replay(User $user, string $key, string $hash): ?Reservation
    {
        $existing = $this->reservations->findByIdempotencyKey($user, $key);
        if ($existing === null) {
            return null;
        }
        if (!hash_equals($existing->getRequestHash(), $hash)) {
            // Réservation déjà créée (réponse perdue) puis formulaire modifié : l'app ouvre cette réservation.
            throw new ApiException(422, 'IDEMPOTENCY_KEY_REUSED', 'Votre réservation est déjà enregistrée.', ['ref' => $existing->getRef()]);
        }

        return $existing;
    }

    /**
     * Lignes du panier, fusionnées par variante.
     *
     * @return array<string, array{variantId: string, quantity: int}>
     */
    private function lines(mixed $items): array
    {
        if (!\is_array($items) || !array_is_list($items) || $items === []) {
            throw ApiException::validation(['items' => 'Votre panier est vide.'], 'Votre panier est vide.');
        }
        if (\count($items) > self::MAX_LINES) {
            throw ApiException::validation(['items' => 'Le panier contient trop d’articles.'], 'Le panier contient trop d’articles.');
        }

        $lines = [];
        foreach ($items as $i => $entry) {
            $variantId = \is_array($entry) ? ($entry['variantId'] ?? null) : null;
            $quantity = \is_array($entry) ? ($entry['quantity'] ?? null) : null;
            if ((!\is_string($variantId) && !\is_int($variantId)) || (string) $variantId === '' || \strlen((string) $variantId) > 80) {
                throw ApiException::validation(["items[$i].variantId" => 'Article invalide.'], 'Un article du panier est invalide.');
            }
            if (!\is_int($quantity) || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
                throw ApiException::validation(["items[$i].quantity" => 'Quantité invalide.'], 'Une quantité du panier est invalide.');
            }
            $id = (string) $variantId;
            $lines[$id] = [
                'variantId' => $id,
                'quantity'  => min(self::MAX_QUANTITY, ($lines[$id]['quantity'] ?? 0) + $quantity),
            ];
        }

        return $lines;
    }

    /** @param array<string, array{variantId: string, quantity: int}> $lines */
    private function assertReasonable(array $lines): void
    {
        $quantities = array_column($lines, 'quantity');
        if (max($quantities) > self::MAX_PER_VARIANT || array_sum($quantities) > self::MAX_PIECES) {
            throw new ApiException(
                422,
                'TOO_MANY_ITEMS',
                sprintf('Une réservation contient au plus %d pièces, dont %d d’un même article (taille et couleur). Pour une plus grande quantité, écrivez-nous sur WhatsApp.', self::MAX_PIECES, self::MAX_PER_VARIANT),
                ['max' => self::MAX_PIECES, 'perLine' => self::MAX_PER_VARIANT],
            );
        }
    }

    /**
     * Réservations ouvertes du client (ni payées, ni terminées, ni échues) et leurs pièces.
     *
     * @param array<string, array{variantId: string, quantity: int}> $lines
     */
    private function assertWithinOpenLimits(User $user, array $lines, \DateTimeImmutable $now): void
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT COUNT(DISTINCT r.id) AS n, COUNT(DISTINCT CASE WHEN r.status <> ? THEN r.id END) AS unpaid, COALESCE(SUM(i.quantity), 0) AS pieces
             FROM reservations r LEFT JOIN reservation_items i ON i.reservation_id = r.id
             WHERE r.user_id = ? AND (r.status = ? OR (r.status IN (?, ?) AND r.expires_at > ?))',
            [Reservation::STATUS_PENDING_VALIDATION, $user->getId(), Reservation::STATUS_PENDING_VALIDATION, ...Reservation::AWAITING_PAYMENT, $now->format('Y-m-d H:i:s')],
        ) ?: ['n' => 0, 'unpaid' => 0, 'pieces' => 0];
        // Ne jamais conseiller de payer ou d'annuler une réservation déjà payée, en cours de vérification.
        $advice = (int) $row['unpaid'] > 0
            ? 'Payez celles qui attendent un paiement ou annulez-en une : la place se libère dès que la boutique a vérifié le paiement.'
            : 'Vos paiements sont en cours de vérification : réessayez ensuite, ou écrivez-nous sur WhatsApp.';

        $open = (int) $row['n'];
        if ($open >= self::MAX_OPEN) {
            throw new ApiException(
                422,
                'TOO_MANY_OPEN_RESERVATIONS',
                sprintf('Vous avez déjà %d réservations en attente de paiement ou de vérification. %s', $open, $advice),
                ['max' => self::MAX_OPEN, 'open' => $open],
            );
        }
        $pieces = (int) $row['pieces'];
        if ($pieces + array_sum(array_column($lines, 'quantity')) > self::MAX_OPEN_PIECES) {
            throw new ApiException(
                422,
                'TOO_MANY_ITEMS',
                sprintf('Vos réservations en attente de paiement ou de vérification contiennent déjà %d pièces (%d au plus en tout). %s', $pieces, self::MAX_OPEN_PIECES, $advice),
                ['max' => self::MAX_OPEN_PIECES, 'perLine' => self::MAX_PER_VARIANT, 'open' => $pieces],
            );
        }
    }

    /** @param list<LimiterInterface> $limiters */
    private function assertNotTooMany(array $limiters): void
    {
        foreach ($limiters as $limiter) {
            $check = $limiter->consume(0); // lecture seule : seule une réservation créée compte
            if ($check->getRemainingTokens() > 0) {
                continue;
            }
            $retryAfter = max(1, $check->getRetryAfter()->getTimestamp() - time());

            throw ApiException::tooManyRequests('RATE_LIMITED', sprintf('Trop de réservations en peu de temps. Réessayez dans %s.', self::delay($retryAfter)), $retryAfter);
        }
    }

    private static function delay(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return $minutes < 60 ? sprintf("%d\u{00A0}min", $minutes) : sprintf("%d\u{00A0}h", (int) ceil($minutes / 60));
    }

    /**
     * Montants de la réservation (prix et frais actuels, jamais ceux du front).
     *
     * @param array<string, array{variantId: string, quantity: int}> $lines
     * @param array<string, ProductVariant>                        $found
     *
     * @return array{subtotal: int, deliveryFee: int, feePayment: string, coinsUsed: int, coinsDiscount: int, total: int}
     */
    private function amounts(array $lines, array $found, int $fee, int $coinsBalance = 0): array
    {
        $subtotal = 0;
        foreach ($lines as $id => $line) {
            $subtotal += $found[$id]->getProduct()->getPrice() * $line['quantity'];
        }
        $feePayment = (string) $this->settings->get()['deliveryFeePayment'];
        // Pièces : 1 pièce = 20 FCFA, sur les articles seulement (Reservation::setAmounts)
        $coins = CoinWallet::discountFor($subtotal, $coinsBalance);
        $articles = $subtotal - $coins['discount'];

        return [
            'subtotal'      => $subtotal,
            'deliveryFee'   => $fee,
            'feePayment'    => $feePayment,
            'coinsUsed'     => $coins['coins'],
            'coinsDiscount' => $coins['discount'],
            // Frais payés à la livraison : pas dans le montant à payer maintenant (Reservation::setAmounts).
            'total'         => $feePayment === 'on_delivery' ? $articles : $articles + $fee,
        ];
    }

    /**
     * Le client a confirmé un montant précis : s'il n'est plus juste (prix, frais ou réglage changé entre-temps), rien
     * n'est réservé et l'app lui montre le nouveau montant. Champ absent : ancienne version de l'app.
     *
     * @param array<string, mixed>                                                                 $body
     * @param array{subtotal: int, deliveryFee: int, feePayment: string, coinsUsed: int, coinsDiscount: int, total: int} $amounts
     * @param array<string, array{variantId: string, quantity: int}>                               $lines
     * @param array<string, ProductVariant>                                                        $found
     */
    private function assertExpectedTotal(array $body, array $amounts, array $lines, array $found): void
    {
        $expected = $body['expectedTotal'] ?? null;
        if ($expected === null) {
            return;
        }
        if (\is_float($expected) && is_finite($expected) && floor($expected) === $expected && abs($expected) < 2 ** 53) {
            $expected = (int) $expected; // « 45000.0 » : même nombre
        }
        if (!\is_int($expected) || $expected < 0) {
            throw ApiException::validation(['expectedTotal' => 'Montant affiché invalide.'], 'La demande est incomplète : rechargez la page puis réessayez.');
        }
        if ($expected === $amounts['total']) {
            return;
        }

        $items = [];
        foreach ($lines as $id => $line) {
            $items[] = ['variantId' => (string) $id, 'unitPrice' => $found[$id]->getProduct()->getPrice()];
        }

        throw new ApiException(
            409,
            'PRICE_CHANGED',
            sprintf('Le montant a changé : %s au lieu de %s. Vérifiez votre réservation puis confirmez à nouveau.', self::fcfa($amounts['total']), self::fcfa($expected)),
            ['expectedTotal' => $expected, 'total' => $amounts['total'], 'subtotal' => $amounts['subtotal'], 'deliveryFee' => $amounts['deliveryFee'], 'coinsDiscount' => $amounts['coinsDiscount'], 'items' => $items],
        );
    }

    private static function fcfa(int $amount): string
    {
        return number_format($amount, 0, ',', "\u{202F}") . "\u{00A0}FCFA";
    }

    /**
     * Ville de boutique des articles (une seule). Articles inconnus ou retirés : traités ensuite (409).
     *
     * @param array<string, array{variantId: string, quantity: int}> $lines
     * @param array<string, ProductVariant>                        $found
     */
    private function shopCity(array $lines, array $found): string
    {
        $cities = [];
        $byItem = [];
        foreach ($lines as $id => $line) {
            $variant = $found[$id] ?? null;
            if ($variant === null || !$variant->getProduct()->isVisible()) {
                continue;
            }
            $city = $variant->getProduct()->getCity();
            $cities[$city] = true;
            $byItem[] = ['variantId' => $id, 'city' => $city];
        }

        if (\count($cities) > 1) {
            throw new ApiException(
                422,
                'MIXED_CITIES',
                sprintf(
                    'Votre panier contient des articles de %s. Réservez les articles d’une seule ville à la fois.',
                    implode(' et de ', array_keys($cities)),
                ),
                ['cities' => array_keys($cities), 'items' => $byItem],
            );
        }
        if ($cities === []) {
            $this->stockConflict($lines, $found); // rien de réservable
        }

        return (string) array_key_first($cities);
    }

    /**
     * Retrait ou livraison : destinataire, adresse ou agence, frais.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function fulfilment(User $user, array $body, string $shopCity, array &$errors): array
    {
        $plan = ['fee' => 0];

        $mode = $body['receptionMode'] ?? null;
        if (!\in_array($mode, [Reservation::MODE_PICKUP, Reservation::MODE_DELIVERY], true)) {
            $errors['receptionMode'] = 'Choisissez le retrait en boutique ou la livraison.';

            return $plan;
        }
        $plan['mode'] = $mode;

        $first = TextInput::clean($body['firstName'] ?? null) ?? '';
        $last = TextInput::clean($body['lastName'] ?? null) ?? '';
        if ($first === '' || TextInput::length($first) > 100) {
            $errors['firstName'] = $first === '' ? 'Indiquez votre prénom.' : 'Le prénom ne doit pas dépasser 100 caractères.';
        }
        if (TextInput::length($last) > 100) {
            $errors['lastName'] = 'Le nom ne doit pas dépasser 100 caractères.';
        }
        $plan['firstName'] = $first;
        $plan['lastName'] = $last;

        $notes = TextInput::clean($body['notes'] ?? null) ?? '';
        if (TextInput::length($notes) > 200) {
            $errors['notes'] = 'La remarque ne doit pas dépasser 200 caractères.';
        }
        $plan['notes'] = $notes !== '' ? $notes : null;

        // Destinataire : le client lui-même ; une autre personne seulement pour un envoi par agence (le front ne
        // propose le choix que là : un ancien choix laissé dans le formulaire ne doit pas bloquer un retrait).
        $plan['recipient'] = [mb_substr(trim($first . ' ' . $last), 0, 120, 'UTF-8'), $user->getPhone()];

        if ($mode === Reservation::MODE_PICKUP) {
            $shop = $this->settings->shop($shopCity);
            unset($shop['homeDeliveryFee']);
            $plan['pickupShop'] = $shop;

            return $plan;
        }

        $cityRaw = \is_string($body['city'] ?? null) ? trim(mb_substr($body['city'], 0, 80)) : '';
        $city = $cityRaw !== '' ? $this->cities->findVisibleByIdOrName($cityRaw) : null;
        if (!$city instanceof DeliveryCity) {
            $errors['city'] = 'Choisissez une ville de livraison de la liste.';

            return $plan;
        }
        $plan['city'] = $city;

        if (DeliveryOptions::isHomeDelivery($city, $shopCity)) {
            $district = TextInput::clean($body['district'] ?? null) ?? '';
            $landmark = TextInput::clean($body['landmark'] ?? null) ?? '';
            if (TextInput::length($district) < 2 || TextInput::length($district) > 120) {
                $errors['district'] = 'Indiquez votre quartier.';
            }
            if (TextInput::length($landmark) < 5 || TextInput::length($landmark) > 255) {
                $errors['landmark'] = 'Indiquez un point de repère précis (5 caractères au moins).';
            }
            $plan['home'] = [$district, $landmark];
            $plan['fee'] = $this->delivery->homeDeliveryFee($shopCity);

            return $plan;
        }

        $agencyId = \is_string($body['agencyId'] ?? null) ? trim($body['agencyId']) : '';
        $agency = $agencyId !== '' && \strlen($agencyId) <= 40 ? $this->agencies->find($agencyId) : null;
        if (!$agency instanceof Agency || !$agency->isActive() || $agency->getCity()->getId() !== $city->getId() || !$agency->servesFrom($shopCity)) {
            $errors['agencyId'] = sprintf('Choisissez une agence de %s dans la liste.', $city->getName());

            return $plan;
        }
        $plan['agency'] = $agency;
        $plan['fee'] = $agency->effectiveFee();

        if (($body['recipientType'] ?? 'self') === 'other') {
            $name = TextInput::clean($body['recipientName'] ?? null) ?? '';
            $phone = PhoneNumber::normalizeCameroonMobile($body['recipientPhone'] ?? null);
            if (TextInput::length($name) < 2 || TextInput::length($name) > 120) {
                $errors['recipientName'] = 'Indiquez le nom de la personne qui récupérera le colis à l’agence.';
            }
            if ($phone === null) {
                $errors['recipientPhone'] = 'Numéro du destinataire invalide : un mobile camerounais (6XX XX XX XX).';
            }
            $plan['recipient'] = [$name, $phone];
        }

        return $plan;
    }

    /**
     * @param array<string, mixed>                                  $body
     * @param array<string, mixed>                                  $plan
     * @param array<string, array{variantId: string, quantity: int}> $lines
     * @param array<string, ProductVariant>                         $found
     */
    private function build(User $user, array $body, array $plan, array $lines, array $found, string $shopCity, string $key, string $hash, \DateTimeImmutable $now): Reservation
    {
        $settings = $this->settings->get();
        $reservation = new Reservation($user, $shopCity, $plan['mode'], $now);
        $reservation->setCustomer($plan['firstName'], $plan['lastName'], $user->getPhone())
            ->setRecipient($plan['recipient'][0] !== '' ? $plan['recipient'][0] : null, $plan['recipient'][1])
            ->setNotes($plan['notes'])
            ->setIdempotency($key, $hash);

        // Profil sans nom (étape « Plus tard » à la connexion) : le nom donné pour la réservation le complète.
        // Un nom déjà enregistré n'est jamais remplacé (mêmes longueurs que le formulaire du profil).
        if (($user->getFirstName() ?? '') === '') {
            $user->setFirstName(mb_substr($plan['firstName'], 0, ProfileController::NAME_MAX_LENGTH));
            if (($user->getLastName() ?? '') === '' && $plan['lastName'] !== '') {
                $user->setLastName(mb_substr($plan['lastName'], 0, ProfileController::NAME_MAX_LENGTH));
            }
        }

        $hours = max(1, min(72, (int) $settings['reservationHoldHours']));
        $reservation->setHold($hours, $now->modify(sprintf('+%d hours', $hours)));

        if ($plan['mode'] === Reservation::MODE_PICKUP) {
            $reservation->setPickupShop($plan['pickupShop']);
        } else {
            /** @var DeliveryCity $city */
            $city = $plan['city'];
            $reservation->setDeliveryCity($city->getId(), $city->getName());
            if (isset($plan['home'])) {
                $reservation->setHomeAddress($plan['home'][0], $plan['home'][1]);
            } else {
                /** @var Agency $agency */
                $agency = $plan['agency'];
                $reservation->setAgency($agency->getId(), [
                    'id'           => $agency->getId(),
                    'name'         => $agency->getName(),
                    'address'      => $agency->getAddress(),
                    'city'         => $city->getName(),
                    'phone'        => $agency->getPhone(),
                    'openingHours' => $agency->getOpeningHours(),
                    'fee'          => $agency->effectiveFee(),
                ]);
            }
        }

        $position = 0;
        foreach ($lines as $id => $line) {
            $reservation->addItem(new ReservationItem($reservation, $position++, $found[$id], $line['quantity']));
        }
        $reservation->setAmounts(
            $plan['amounts']['subtotal'],
            $plan['amounts']['deliveryFee'],
            $plan['amounts']['feePayment'],
            $plan['amounts']['coinsUsed'],
            $plan['amounts']['coinsDiscount'],
        );

        $note = $plan['mode'] === Reservation::MODE_PICKUP
            ? sprintf('Réservation enregistrée : retrait à la boutique de %s.', $shopCity)
            : 'Réservation enregistrée.';
        $reservation->record(Reservation::STATUS_RESERVED, Reservation::ACTOR_CLIENT, $note, $now);

        // Rien à payer maintenant (articles entièrement réglés avec les pièces) : confirmée tout de suite
        if ($reservation->getTotal() === 0 && $reservation->getCoinsUsed() > 0) {
            $reservation->setPaidAt($now);
            $reservation->record(Reservation::STATUS_CONFIRMED, Reservation::ACTOR_SYSTEM, 'Montant entièrement réglé avec vos pièces.', $now);
        }

        return $reservation;
    }

    /**
     * 409 STOCK_CONFLICT avec toutes les lignes au format de /cart/validate (le front met son panier à jour).
     *
     * @param array<string, array{variantId: string, quantity: int}> $lines
     * @param array<string, ProductVariant>                         $found
     * @param array<string, int>|null                               $stocks stock relu en base
     */
    private function stockConflict(array $lines, array $found, ?array $stocks = null): never
    {
        $items = [];
        foreach ($lines as $id => $line) {
            $items[] = CartCheck::line($line, $found[$id] ?? null, $stocks !== null ? ($stocks[$id] ?? 0) : null);
        }

        throw new ApiException(409, 'STOCK_CONFLICT', 'Le stock a changé pendant votre réservation : votre panier a été mis à jour.', ['items' => $items]);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, int>
     */
    private function freshStocks(array $ids): array
    {
        $rows = $this->em->getConnection()->fetchAllKeyValue(
            'SELECT id, stock FROM product_variants WHERE id IN (?)',
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return array_map('intval', $rows);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
    }

    private static function canonical(mixed $value): mixed
    {
        if (\is_float($value) && !is_finite($value)) {
            return (string) $value; // « 1e999 » : infini, impossible à écrire en JSON
        }
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
