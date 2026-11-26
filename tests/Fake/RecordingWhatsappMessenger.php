<?php

namespace App\Tests\Fake;

use App\Auth\Whatsapp\WhatsappMessengerInterface;

/**
 * Faux envoi de messages WhatsApp (tests) : aucun appel à Meta, chaque réponse est enregistrée.
 */
final class RecordingWhatsappMessenger implements WhatsappMessengerInterface
{
    /** @var list<array{to: string, text: string}> */
    public array $sent = [];

    public function send(string $to, string $text): bool
    {
        $this->sent[] = ['to' => $to, 'text' => $text];

        return true;
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
