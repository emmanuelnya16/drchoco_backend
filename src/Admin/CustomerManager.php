<?php

namespace App\Admin;

use App\Account\PhoneNumber;
use App\Api\ApiDate;
use App\Api\ApiException;
use App\Audit\AuditLog;
use App\Entity\Admin;
use App\Entity\RefreshToken;
use App\Entity\Reservation;
use App\Entity\ReservationProof;
use App\Entity\User;
use App\Push\PushSubscriptions;
use App\Reservation\ReservationFileRetention;
use App\Service\Auth\RefreshTokenService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Compte d'un client, côté boutique (P9) :
 *  - bloqué : ses sessions et ses notifications sont coupées aussitôt, il ne peut plus se connecter ni réserver ; ses
 *    réservations ouvertes restent (le super admin les annule une par une s'il le faut) ;
 *  - sessions coupées sur tous ses appareils (téléphone perdu ou volé), sans blocage ;
 *  - droit d'accès : copie de ses données ; droit à l'effacement : compte, adresses, favoris, appareils et sessions
 *    supprimés, réservations rendues anonymes (montants et articles gardés pour la comptabilité).
 * Chaque action est écrite dans le journal d'audit.
 */
final class CustomerManager
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokens,
        private readonly PushSubscriptions $pushDevices,
        private readonly ReservationFileRetention $files,
        private readonly AuditLog $audit,
        private readonly EntityManagerInterface $em,
    ) {}

    public function block(User $user, ?Admin $actor, ?string $reason = null): void
    {
        $this->em->wrapInTransaction(function () use ($user): void {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            $user->setStatus(User::STATUS_BLOCKED);
            $this->em->flush();
            $this->cutSessions($user);
        });
        $this->audit->record('customer.blocked', $actor, $this->identity($user) + ($reason !== null ? ['reason' => $reason] : []));
    }

    public function unblock(User $user, ?Admin $actor): void
    {
        if (self::isErased($user)) {
            throw new ApiException(409, 'CUSTOMER_ERASED', 'Les données de ce client ont été effacées à sa demande : il se reconnecte avec son numéro.');
        }
        $user->setStatus(User::STATUS_ACTIVE);
        $this->em->flush();
        $this->audit->record('customer.unblocked', $actor, $this->identity($user));
    }

    /** Téléphone perdu ou volé : le client devra se reconnecter partout, avec un code SMS. */
    public function closeSessions(User $user, ?Admin $actor): void
    {
        $this->em->wrapInTransaction(function () use ($user): void {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            $this->cutSessions($user);
        });
        $this->audit->record('customer.sessions_closed', $actor, $this->identity($user));
    }

    /**
     * Efface les données personnelles du client. Refusé tant qu'une réservation est en cours, ou qu'un remboursement est dû.
     *
     * @return array{reservations: int, files: int}
     */
    public function erase(User $user, ?Admin $actor): array
    {
        $id = (int) $user->getId();
        $phone = $user->getPhone();
        $identity = $this->identity($user);
        $db = $this->em->getConnection();

        $db->beginTransaction();
        try {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            // Une réservation en cours, ou un remboursement encore à faire (Reservation::isRefundDue) : sans le numéro ni la
            // capture, la boutique ne pourrait plus rendre son argent au client.
            $open = $db->fetchFirstColumn(
                'SELECT r.ref FROM reservations r WHERE r.user_id = ? AND (r.status NOT IN (?) OR (r.status = ? AND r.refund_settled_at IS NULL
                 AND (r.paid_at IS NOT NULL OR EXISTS (SELECT 1 FROM reservation_proofs p WHERE p.reservation_id = r.id AND p.status = ?)))) ORDER BY r.id',
                [$id, Reservation::FINAL_STATUSES, Reservation::STATUS_CANCELLED, ReservationProof::STATUS_PENDING],
                [ParameterType::INTEGER, ArrayParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
            );
            if ($open !== []) {
                throw new ApiException(409, 'CUSTOMER_HAS_RESERVATIONS', 'Ce client a des réservations en cours ou un remboursement à prévoir : terminez-les, annulez-les ou closez le remboursement d’abord.', ['refs' => array_map('strval', $open)]);
            }

            $this->cutSessions($user);
            foreach (['addresses', 'favorites'] as $table) {
                $db->executeStatement('DELETE FROM ' . $table . ' WHERE user_id = ?', [$id]);
            }
            $db->executeStatement('DELETE FROM otp_codes WHERE phone = ?', [$phone]);
            $db->executeStatement('DELETE FROM refresh_tokens WHERE user_type = ? AND user_id = ?', [RefreshToken::TYPE_USER, $id]);
            // Ce que le client a écrit (remarque, motif d'annulation) et les notes internes à son sujet.
            $db->executeStatement('DELETE n FROM reservation_notes n INNER JOIN reservations r ON r.id = n.reservation_id WHERE r.user_id = ?', [$id]);
            $db->executeStatement('UPDATE reservation_events e INNER JOIN reservations r ON r.id = e.reservation_id SET e.note = NULL WHERE r.user_id = ? AND e.actor = ?', [$id, Reservation::ACTOR_CLIENT]);
            $count = (int) $db->executeStatement(
                "UPDATE reservations SET customer_first_name = 'Client', customer_last_name = 'effacé', customer_phone = '', recipient_name = NULL, recipient_phone = NULL, notes = NULL, district = NULL, landmark = NULL WHERE user_id = ?",
                [$id],
            );
            $user->setPhone(self::erasedPhone($id))->setFirstName(null)->setLastName(null)->setCity(null)->setLastLoginAt(null)->setStatus(User::STATUS_BLOCKED);
            $this->em->flush();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $files = 0;
        try {
            $files = $this->files->forgetCustomer($id); // un fichier qui résiste est repris par app:purge
        } finally {
            $this->audit->record('customer.erased', $actor, $identity + ['reservations' => $count, 'files' => $files]);
        }

        return ['reservations' => $count, 'files' => $files];
    }

    /** Copie des données du client (droit d'accès). @return array<string, mixed> */
    public function export(User $user, ?Admin $actor): array
    {
        $id = (int) $user->getId();
        $db = $this->em->getConnection();
        $reservations = [];
        foreach ($db->fetchAllAssociative('SELECT * FROM reservations WHERE user_id = ? ORDER BY id', [$id]) as $r) {
            $reservations[] = [
                'ref'           => $r['ref'],
                'statut'        => $r['status'],
                'boutique'      => $r['shop_city'],
                'reception'     => $r['reception_mode'],
                'client'        => trim($r['customer_first_name'] . ' ' . $r['customer_last_name']),
                'telephone'     => $r['customer_phone'],
                'livraison'     => array_filter(['ville' => $r['delivery_city_name'], 'quartier' => $r['district'], 'repere' => $r['landmark'], 'agence' => $r['agency_id'], 'destinataire' => $r['recipient_name'], 'telephone' => $r['recipient_phone']], fn ($v) => $v !== null && $v !== ''),
                'remarque'      => $r['notes'],
                'articles'      => $db->fetchAllAssociative('SELECT product_name AS article, size AS taille, color_name AS couleur, quantity AS quantite, unit_price AS prix FROM reservation_items WHERE reservation_id = ? ORDER BY position', [$r['id']]),
                'sousTotal'     => (int) $r['subtotal'],
                'fraisLivraison' => (int) $r['delivery_fee'],
                'total'         => (int) $r['total'],
                'historique'    => $db->fetchAllAssociative('SELECT at AS date, status AS statut, actor AS auteur, note FROM reservation_events WHERE reservation_id = ? ORDER BY id', [$r['id']]),
                'preuves'       => $db->fetchAllAssociative("SELECT uploaded_at AS date, operator AS operateur, payer_phone AS telephone, transaction_id AS transaction, status AS statut, storage_path <> '' AS imageGardee FROM reservation_proofs WHERE reservation_id = ? ORDER BY id", [$r['id']]),
                'photosColis'   => (int) $db->fetchOne('SELECT COUNT(*) FROM reservation_package_photos WHERE reservation_id = ?', [$r['id']]),
                'creeeLe'       => $r['created_at'],
                'modifieeLe'    => $r['updated_at'],
            ];
        }
        $this->audit->record('customer.exported', $actor, $this->identity($user));

        return [
            'compte' => [
                'identifiant'       => $id,
                'telephone'         => $user->getPhone(),
                'prenom'            => $user->getFirstName(),
                'nom'               => $user->getLastName(),
                'ville'             => $user->getCity(),
                'statut'            => $user->getStatus(),
                'creeLe'            => ApiDate::format($user->getCreatedAt()),
                'derniereConnexion' => $user->getLastLoginAt() !== null ? ApiDate::format($user->getLastLoginAt()) : null,
            ],
            'adresses'      => $db->fetchAllAssociative('SELECT * FROM addresses WHERE user_id = ? ORDER BY id', [$id]),
            'favoris'       => $db->fetchAllAssociative('SELECT p.name AS article, f.created_at AS date FROM favorites f INNER JOIN products p ON p.id = f.product_id WHERE f.user_id = ? ORDER BY f.id', [$id]),
            'appareils'     => $db->fetchAllAssociative('SELECT platform AS plateforme, user_agent AS navigateur, created_at AS creeLe, last_seen_at AS vuLe FROM push_subscriptions WHERE owner_kind = ? AND user_id = ? ORDER BY id', ['user', $id]),
            'sessions'      => $db->fetchAllAssociative('SELECT created_at AS creeeLe, expires_at AS expireLe FROM refresh_tokens WHERE user_type = ? AND user_id = ? AND is_revoked = 0 AND is_used = 0 ORDER BY id', [RefreshToken::TYPE_USER, $id]),
            'reservations'  => $reservations,
            'remarque'      => 'Les images (captures de paiement, photos de colis) se demandent à la boutique ; elles sont effacées au bout de 12 mois.',
        ];
    }

    public static function isErased(User $user): bool
    {
        return $user->getId() !== null && $user->getPhone() === self::erasedPhone((int) $user->getId());
    }

    private static function erasedPhone(int $id): string
    {
        return 'efface-' . $id;
    }

    private function cutSessions(User $user): void
    {
        $this->refreshTokens->revokeAll((int) $user->getId(), RefreshToken::TYPE_USER);
        $this->pushDevices->forgetUser((int) $user->getId());
    }

    /** @return array{customer: ?int, phone: string} numéro masqué */
    private function identity(User $user): array
    {
        return ['customer' => $user->getId(), 'phone' => PhoneNumber::mask($user->getPhone())];
    }
}
