<?php

namespace App\Reservation;

use App\Entity\Reservation;
use App\Entity\ReservationProof;
use App\Media\PrivateMediaStorage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * Conservation des captures de paiement et des photos de colis : 12 mois (décision du 17/11/2026), puis effacement par
 * app:purge. Une réservation encore en cours garde les siennes.
 *
 * Capture : le fichier, le numéro payeur et la transaction sont effacés ; la ligne reste avec l'empreinte de l'image, pour
 * qu'une vieille capture ne paie pas une nouvelle réservation. Photo de colis : fichier et ligne effacés.
 * Un fichier impossible à supprimer garde sa ligne : il est réessayé au passage suivant.
 */
final class ReservationFileRetention
{
    public const MONTHS = 12;

    private const BATCH = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly PrivateMediaStorage $storage,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Captures et photos de plus de 12 mois des réservations terminées, et restes d'un client effacé (fichier qui n'avait
     * pas pu être supprimé).
     *
     * @return int fichiers effacés
     */
    public function purgeOld(): int
    {
        $params = [$this->limit(), Reservation::FINAL_STATUSES];
        $types = [ParameterType::STRING, ArrayParameterType::STRING];

        return $this->forgetProofs($this->db->fetchAllKeyValue(
            "SELECT p.id, p.storage_path FROM reservation_proofs p INNER JOIN reservations r ON r.id = p.reservation_id INNER JOIN users u ON u.id = r.user_id
             WHERE p.storage_path <> '' AND (p.uploaded_at < ? OR (u.phone LIKE 'efface-%' AND p.status <> 'accepted')) AND r.status IN (?) ORDER BY p.id LIMIT " . self::BATCH,
            $params,
            $types,
        )) + $this->forgetPhotos($this->db->fetchAllKeyValue(
            "SELECT f.id, f.storage_path FROM reservation_package_photos f INNER JOIN reservations r ON r.id = f.reservation_id INNER JOIN users u ON u.id = r.user_id
             WHERE (f.taken_at < ? OR u.phone LIKE 'efface-%') AND r.status IN (?) ORDER BY f.id LIMIT " . self::BATCH,
            $params,
            $types,
        ));
    }

    /**
     * Client effacé : toutes ses captures et photos, sauf les captures de paiements validés depuis moins de 12 mois
     * (justificatifs d'un encaissement), effacées à leur tour à cette date.
     *
     * @return int fichiers effacés
     */
    public function forgetCustomer(int $userId): int
    {
        return $this->forgetProofs($this->db->fetchAllKeyValue(
            "SELECT p.id, p.storage_path FROM reservation_proofs p INNER JOIN reservations r ON r.id = p.reservation_id
             WHERE p.storage_path <> '' AND r.user_id = ? AND NOT (p.status = ? AND p.uploaded_at >= ?) ORDER BY p.id",
            [$userId, ReservationProof::STATUS_ACCEPTED, $this->limit()],
        )) + $this->forgetPhotos($this->db->fetchAllKeyValue(
            'SELECT f.id, f.storage_path FROM reservation_package_photos f INNER JOIN reservations r ON r.id = f.reservation_id
             WHERE r.user_id = ? ORDER BY f.id',
            [$userId],
        ));
    }

    /** @param array<int, string> $files chemin par identifiant */
    private function forgetProofs(array $files): int
    {
        $done = 0;
        foreach ($files as $id => $path) {
            if ($this->storage->delete($path)) {
                $this->db->executeStatement("UPDATE reservation_proofs SET storage_path = '', payer_phone = NULL, transaction_id = NULL WHERE id = ?", [$id]);
                ++$done;
            }
        }

        return $done;
    }

    /** @param array<int, string> $files chemin par identifiant */
    private function forgetPhotos(array $files): int
    {
        $done = 0;
        foreach ($files as $id => $path) {
            if ($this->storage->delete($path)) {
                $this->db->executeStatement('DELETE FROM reservation_package_photos WHERE id = ?', [$id]);
                ++$done;
            }
        }

        return $done;
    }

    private function limit(): string
    {
        return (new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp()))->modify('-' . self::MONTHS . ' months')->format('Y-m-d H:i:s');
    }
}
