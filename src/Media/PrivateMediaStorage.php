<?php

namespace App\Media;

use App\Api\ApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Fichiers privés (preuves de paiement, photos du colis) : rangés hors du site public (var/private), servis seulement par des
 * liens signés qui expirent (contrat §2.8 : 1 h, régénérés à chaque lecture).
 *
 * L'échéance est arrondie aux 10 minutes : le lien reste le même entre deux rafraîchissements du front
 * (pas de nouveau téléchargement de l'image toutes les 20 s) et vaut de 60 à 70 minutes.
 */
class PrivateMediaStorage
{
    private const LIFETIME = 3600;
    private const ROUNDING = 600;

    /** Espace gardé libre sur le disque (base de données, sessions, journaux) : en dessous, plus d'image enregistrée. */
    private const MIN_FREE_BYTES = 200 * 1024 * 1024;

    /** Photo du colis dans une notification push : lien valable 25 h (durée de vie d'une notification : 24 h). */
    public const KIND_PACKAGE_PUSH = 'package-push';
    private const LIFETIMES = [self::KIND_PACKAGE_PUSH => 90000];

    public function __construct(
        #[Autowire('%app.private_media_dir%')]
        private readonly string $directory,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Enregistre le contenu et renvoie son chemin relatif.
     *
     * @throws ApiException 507 disque plein ou dossier impossible à écrire (rien n'est gardé)
     */
    public function store(string $folder, string $extension, string $bytes): string
    {
        $relative = trim($folder, '/') . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $path = $this->absolute($relative);
        if (!is_dir(\dirname($path)) && !@mkdir(\dirname($path), 0770, true) && !is_dir(\dirname($path))) {
            throw $this->full('dossier impossible à créer');
        }
        $free = \function_exists('disk_free_space') ? @disk_free_space(\dirname($path)) : false; // désactivée chez certains hébergeurs
        if ($free !== false && $free < self::MIN_FREE_BYTES + \strlen($bytes)) {
            throw $this->full(sprintf('%d Mo libres', intdiv((int) $free, 1024 * 1024)));
        }
        if (@file_put_contents($path, $bytes, \LOCK_EX) !== \strlen($bytes)) {
            @unlink($path); // écriture partielle : elle n'occupe pas le peu de place qui reste
            throw $this->full('écriture incomplète');
        }

        return $relative;
    }

    private function full(string $reason): ApiException
    {
        $this->logger->critical('Image non enregistrée dans le dossier privé ({reason}) : libérer de la place ou vérifier les droits.', ['reason' => $reason]);

        return new ApiException(507, 'STORAGE_FULL', 'L’envoi des images est momentanément indisponible. Écrivez-nous sur WhatsApp.');
    }

    /** Supprime le fichier (et son dossier s'il est vide). @return bool le fichier n'existe plus */
    public function delete(string $relative): bool
    {
        $path = $this->absolute($relative);
        if (is_file($path) && !@unlink($path)) {
            return false;
        }
        @rmdir(\dirname($path)); // ignoré s'il reste d'autres fichiers

        return true;
    }

    public function absolute(string $relative): string
    {
        if (str_contains($relative, '..') || str_contains($relative, '\\')) {
            throw new \InvalidArgumentException('Chemin privé invalide.');
        }

        return rtrim($this->directory, '/\\') . '/' . ltrim($relative, '/');
    }

    /** Lien signé (absolu) vers une preuve de paiement. */
    public function proofUrl(int $proofId, \DateTimeImmutable $now): string
    {
        return $this->signedUrl('api_private_proof', 'proof', $proofId, $now);
    }

    /** Lien signé (absolu) vers une photo du colis (P6). */
    public function packagePhotoUrl(int $photoId, \DateTimeImmutable $now): string
    {
        return $this->signedUrl('api_private_package_photo', 'package', $photoId, $now);
    }

    /**
     * Photo du colis pour une notification push (P7) : lien de 25 h et chemin sans domaine (« /api/media/… ») —
     * le front et l'API partagent le même domaine, et l'adresse ne dépend pas du domaine connu d'une tâche planifiée.
     * Le chemin garde « /private/ » et la signature : le service worker ne la met jamais en cache.
     */
    public function packagePhotoPushUrl(int $photoId, \DateTimeImmutable $now): string
    {
        return $this->signedUrl('api_private_package_photo', self::KIND_PACKAGE_PUSH, $photoId, $now, UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    private function signedUrl(string $route, string $kind, int $id, \DateTimeImmutable $now, int $reference = UrlGeneratorInterface::ABSOLUTE_URL): string
    {
        $expires = (int) (ceil(($now->getTimestamp() + self::lifetime($kind)) / self::ROUNDING) * self::ROUNDING);

        return $this->urls->generate($route, [
            'id'        => $id,
            'expires'   => $expires,
            'signature' => $this->signature($kind, $id, $expires),
        ], $reference);
    }

    public function isValid(string $kind, int $id, int $expires, string $signature, \DateTimeImmutable $now): bool
    {
        return $expires >= $now->getTimestamp()
            && $expires <= $now->getTimestamp() + self::lifetime($kind) + self::ROUNDING
            && hash_equals($this->signature($kind, $id, $expires), $signature);
    }

    private static function lifetime(string $kind): int
    {
        return self::LIFETIMES[$kind] ?? self::LIFETIME;
    }

    private function signature(string $kind, int $id, int $expires): string
    {
        return hash_hmac('sha256', sprintf('private-media|%s|%d|%d', $kind, $id, $expires), $this->secret);
    }
}
