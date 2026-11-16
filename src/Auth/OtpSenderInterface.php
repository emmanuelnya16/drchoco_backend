<?php

namespace App\Auth;

/**
 * Envoi du code de connexion. Remplacé par un faux en test (aucun SMS réel, code lisible par les tests).
 */
interface OtpSenderInterface
{
    /** Renvoie true si le code est parti. Ne lève jamais d'exception. */
    public function send(string $phone, string $code): bool;
}
