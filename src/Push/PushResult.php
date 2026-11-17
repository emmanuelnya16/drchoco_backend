<?php

namespace App\Push;

/**
 * Réponse du service de push à un envoi : code HTTP (0 = pas de réponse : réseau, chiffrement…), délai
 * d'attente demandé (`Retry-After`, secondes) et raison courte pour le journal.
 */
final class PushResult
{
    public function __construct(
        public readonly int $status,
        public readonly ?int $retryAfter = null,
        public readonly ?string $error = null,
    ) {}

    public function isSuccess(): bool { return $this->status >= 200 && $this->status < 300; }

    /** Appareil désabonné ou inconnu du service de push : à supprimer. */
    public function isGone(): bool { return \in_array($this->status, [404, 410], true); }

    /** Erreur passagère : nouvel essai plus tard. */
    public function isTemporary(): bool { return $this->status === 0 || $this->status === 429 || $this->status >= 500; }

    /** Clés VAPID refusées : problème de configuration du serveur, pas de l'appareil. */
    public function isAuthError(): bool { return \in_array($this->status, [401, 403], true); }
}
