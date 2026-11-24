<?php

namespace App\Push;

use App\Api\ApiException;
use App\Entity\Admin;
use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Abonnement d'un appareil aux notifications (contrat §2.11) : contrôle de l'abonnement envoyé par le navigateur,
 * enregistrement idempotent par appareil, 10 appareils au plus par compte.
 *
 * Le serveur envoie ensuite des requêtes vers l'endpoint : seuls les services de push des navigateurs sont
 * acceptés, en https et sur le port 443 (sinon on pourrait lui faire appeler n'importe quelle adresse).
 */
final class PushSubscriptions
{
    public const MAX_DEVICES = 10;

    /** Services de push connus (hôtes exacts, ou suffixes commençant par un point). */
    private const PUSH_HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'jmt17.google.com', '.push.services.mozilla.com', 'web.push.apple.com', '.push.apple.com', '.notify.windows.com'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly string $appEnv,
        /** Hôtes ajoutés pour les tests de bout en bout (faux service de push local) ; ignorés en production. */
        #[Autowire('%env(string:default::PUSH_EXTRA_HOSTS)%')]
        private readonly string $extraHosts = '',
    ) {}

    /** Retire tous les appareils d'un membre de l'équipe (mot de passe changé, compte désactivé). */
    public function forgetAdmin(int $adminId): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM push_subscriptions WHERE owner_kind = ? AND admin_id = ?', [PushSubscription::KIND_ADMIN, $adminId]);
    }

    /** Retire tous les appareils d'un client (compte bloqué, sessions coupées). */
    public function forgetUser(int $userId): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM push_subscriptions WHERE owner_kind = ? AND user_id = ?', [PushSubscription::KIND_USER, $userId]);
    }

    /**
     * Enregistre l'appareil pour ce compte (client ou équipe).
     *
     * @param array<string, mixed> $body `{subscription:{endpoint, keys:{p256dh, auth}}, platform?, userAgent?, oldEndpoint?}`
     *
     * @return int nombre d'appareils du compte
     */
    public function subscribe(User|Admin $owner, array $body): int
    {
        $subscription = $body['subscription'] ?? null;
        $endpoint = \is_array($subscription) ? ($subscription['endpoint'] ?? null) : null;
        $keys = \is_array($subscription) && \is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
        $p256dh = $keys['p256dh'] ?? null;
        $auth = $keys['auth'] ?? null;
        if (!\is_string($endpoint) || !$this->isAllowedEndpoint($endpoint) || !self::isKey($p256dh, 65) || !self::isKey($auth, 16)) {
            throw new ApiException(422, 'INVALID_SUBSCRIPTION', 'Abonnement aux notifications invalide.');
        }
        $platform = \in_array($body['platform'] ?? null, ['ios', 'android', 'web'], true) ? $body['platform'] : 'web';
        $userAgent = \is_string($body['userAgent'] ?? null) && trim($body['userAgent']) !== '' ? mb_substr(trim($body['userAgent']), 0, 255) : null;
        $now = $this->now();
        $kind = $owner instanceof Admin ? PushSubscription::KIND_ADMIN : PushSubscription::KIND_USER;

        // Ancien endpoint du même appareil (abonnement renouvelé par le navigateur) : retiré s'il est à ce compte ;
        // sa plateforme est gardée (le service worker envoie toujours « web »).
        $old = $body['oldEndpoint'] ?? null;
        if (\is_string($old) && $old !== '' && $old !== $endpoint) {
            $previous = $this->find($kind, $old);
            if ($previous !== null && $previous->isOwnedBy($owner)) {
                if ($platform === 'web') {
                    $platform = $previous->getPlatform();
                }
                $this->em->remove($previous);
            }
        }

        $row = $this->find($kind, $endpoint);
        if ($row === null) {
            $row = new PushSubscription($owner, $endpoint, $now);
            $this->em->persist($row);
        } elseif (!$row->isOwnedBy($owner)) {
            $row->setOwner($owner); // un appareil = le dernier compte connecté dessus
        }
        $row->refresh($p256dh, $auth, $platform, $userAgent, $now);
        $this->em->flush();

        return $this->capDevices($owner, $kind);
    }

    /** Retire l'appareil s'il appartient à ce compte (sinon rien : réponse identique). */
    public function unsubscribe(User|Admin $owner, mixed $endpoint): void
    {
        if (!\is_string($endpoint) || $endpoint === '') {
            return;
        }
        $row = $this->find($owner instanceof Admin ? PushSubscription::KIND_ADMIN : PushSubscription::KIND_USER, $endpoint);
        if ($row !== null && $row->isOwnedBy($owner)) {
            $this->em->remove($row);
            $this->em->flush();
        }
    }

    public function isAllowedEndpoint(string $endpoint): bool
    {
        if (\strlen($endpoint) > 1000) {
            return false;
        }
        $parts = parse_url($endpoint);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($this->appEnv !== 'prod') {
            foreach (array_filter(array_map('trim', explode(',', strtolower($this->extraHosts)))) as $extra) {
                if ($host === $extra && \in_array($scheme, ['http', 'https'], true)) {
                    return true;
                }
            }
        }
        if ($scheme !== 'https' || !\in_array($parts['port'] ?? 443, [443], true) || preg_match('/^[a-z0-9.-]+\z/', $host) !== 1) {
            return false;
        }
        foreach (self::PUSH_HOSTS as $allowed) {
            if (str_starts_with($allowed, '.') ? str_ends_with($host, $allowed) : $host === $allowed) {
                return true;
            }
        }

        return false;
    }

    /** Clé en base64url (sans remplissage) de la longueur attendue une fois décodée. */
    private static function isKey(mixed $value, int $bytes): bool
    {
        if (!\is_string($value) || preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value) !== 1) {
            return false;
        }
        $decoded = base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true);

        return $decoded !== false && \strlen($decoded) === $bytes && ($bytes !== 65 || $decoded[0] === "\x04");
    }

    private function find(string $kind, string $endpoint): ?PushSubscription
    {
        return $this->em->getRepository(PushSubscription::class)->findOneBy(['ownerKind' => $kind, 'endpointHash' => PushSubscription::hash($endpoint)]);
    }

    /** Au-delà de 10 appareils, les moins récemment vus sont retirés. @return int appareils gardés */
    private function capDevices(User|Admin $owner, string $kind): int
    {
        $rows = $this->em->getRepository(PushSubscription::class)->findBy(
            [$kind === PushSubscription::KIND_ADMIN ? 'admin' : 'user' => $owner],
            ['lastSeenAt' => 'DESC', 'id' => 'DESC'],
        );
        foreach (\array_slice($rows, self::MAX_DEVICES) as $extra) {
            $this->em->remove($extra);
        }
        $this->em->flush();

        return min(\count($rows), self::MAX_DEVICES);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
    }
}
