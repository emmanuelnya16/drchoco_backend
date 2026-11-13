<?php

namespace App\Security\Exception;

/**
 * Session refusée au rafraîchissement : cookie absent, expiré, révoqué ou réutilisé, compte bloqué.
 * Le contrôleur la traduit en 401 NO_SESSION (le front efface alors la session).
 * Le message est interne (journaux), jamais renvoyé tel quel au client.
 */
final class RefreshDeniedException extends \RuntimeException
{
}
