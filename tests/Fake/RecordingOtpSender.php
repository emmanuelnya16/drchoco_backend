<?php

namespace App\Tests\Fake;

use App\Auth\OtpSenderInterface;

/**
 * Faux envoi de SMS pour les tests : aucun SMS réel, les codes envoyés sont gardés pour être lus par le test.
 */
final class RecordingOtpSender implements OtpSenderInterface
{
    /** @var list<array{phone: string, code: string}> */
    public array $sent = [];

    public bool $fail = false;

    /** @var (\Closure(string, string): void)|null appelé pendant l'envoi (le fournisseur n'a pas encore répondu) */
    public ?\Closure $whileSending = null;

    /** Simule un envoi qui lève une exception (ne devrait jamais arriver avec le vrai envoi). */
    public bool $throw = false;

    public function send(string $phone, string $code): bool
    {
        if ($this->whileSending !== null) {
            ($this->whileSending)($phone, $code);
        }
        if ($this->throw) {
            throw new \RuntimeException('Panne simulée du fournisseur de SMS');
        }
        if ($this->fail) {
            return false;
        }
        $this->sent[] = ['phone' => $phone, 'code' => $code];

        return true;
    }

    public function lastCodeFor(string $phone): ?string
    {
        for ($i = \count($this->sent) - 1; $i >= 0; --$i) {
            if ($this->sent[$i]['phone'] === $phone) {
                return $this->sent[$i]['code'];
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->fail = false;
        $this->throw = false;
        $this->whileSending = null;
    }
}
