<?php

namespace App\Auth\Whatsapp;

/**
 * Envoi d'un message WhatsApp texte au client (réponse à son message, gratuite dans les 24 h qui suivent).
 * Ne lève jamais d'exception : false si l'envoi n'a pas pu se faire.
 */
interface WhatsappMessengerInterface
{
    /** @param string $to numéro au format international, avec ou sans « + » */
    public function send(string $to, string $text): bool;
}
