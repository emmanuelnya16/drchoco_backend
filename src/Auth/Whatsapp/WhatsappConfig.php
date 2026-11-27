<?php

namespace App\Auth\Whatsapp;

use App\Account\PhoneNumber;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Réglages de la connexion par WhatsApp (WHATSAPP_OTP.md). Les identifiants publics (numéro, identifiants Meta) sont
 * dans .env ; les secrets (WHATSAPP_APP_SECRET, WHATSAPP_VERIFY_TOKEN, WHATSAPP_TOKEN) seulement dans .env.local.
 */
final class WhatsappConfig
{
    /** Caractères d'une référence : ni I, ni O, ni 0, ni 1 (faciles à confondre). */
    public const REFERENCE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const REFERENCE_LENGTH = 6;

    public const DEFAULT_GRAPH_URL = 'https://graph.facebook.com';

    /** Numéro WhatsApp de la boutique qui envoie les codes, au format +2376XXXXXXXX (null si absent ou invalide). */
    public readonly ?string $businessNumber;

    /** Compte WhatsApp Business chez Meta (WABA) : abonnement de l'app aux messages reçus (app:diagnostic). */
    public readonly string $businessAccountId;

    public function __construct(
        #[Autowire('%env(WHATSAPP_BUSINESS_NUMBER)%')]
        string $businessNumber,
        #[Autowire('%env(WHATSAPP_PHONE_NUMBER_ID)%')]
        public readonly string $phoneNumberId,
        #[Autowire('%env(WHATSAPP_VERIFY_TOKEN)%')]
        public readonly string $verifyToken,
        #[Autowire('%env(WHATSAPP_APP_SECRET)%')]
        public readonly string $appSecret,
        #[Autowire('%env(WHATSAPP_TOKEN)%')]
        public readonly string $token,
        #[Autowire('%env(WHATSAPP_GRAPH_VERSION)%')]
        public readonly string $graphVersion,
        // Adresse de l'API de Meta ; un faux serveur local seulement pour les essais de bout en bout
        #[Autowire('%env(WHATSAPP_GRAPH_URL)%')]
        private readonly string $graphBaseUrl = self::DEFAULT_GRAPH_URL,
        // Facultatif : son absence ne casse rien (le diagnostic le signale)
        #[Autowire('%env(default::WHATSAPP_BUSINESS_ACCOUNT_ID)%')]
        ?string $businessAccountId = null,
    ) {
        $this->businessNumber = PhoneNumber::normalizeCameroonMobile($businessNumber);
        $this->businessAccountId = trim((string) $businessAccountId);
    }

    /**
     * Connexion possible : un numéro à qui écrire, la clé qui authentifie les messages transmis par Meta, et le jeton
     * qui permet de répondre au client avec son code. Sinon l'app propose seulement le code par SMS.
     */
    public function isLoginReady(): bool
    {
        return $this->businessNumber !== null && $this->appSecret !== '' && $this->canReply();
    }

    /** Réponses au client possibles (jeton et identifiant du numéro chez Meta). */
    public function canReply(): bool
    {
        return $this->token !== '' && $this->phoneNumberId !== '';
    }

    /** Lien qui ouvre WhatsApp sur la conversation avec la boutique, message déjà écrit (avec la référence). */
    public function waLink(string $reference): string
    {
        return sprintf('https://wa.me/%s?text=%s', ltrim((string) $this->businessNumber, '+'), rawurlencode(self::messageFor($reference)));
    }

    /** Message prérempli : le même que celui de l'app (src/features/auth/whatsappCode.ts). */
    public static function messageFor(string $reference): string
    {
        return 'Je veux recevoir mon code de connexion DR Choco. Réf. ' . $reference;
    }

    /** Référence au bon format (majuscules), ou null. */
    public static function normalizeReference(mixed $raw): ?string
    {
        if (!\is_string($raw)) {
            return null;
        }
        $reference = strtoupper(trim($raw));
        $pattern = sprintf('/^[%s]{%d}\z/', self::REFERENCE_ALPHABET, self::REFERENCE_LENGTH);

        return preg_match($pattern, $reference) === 1 ? $reference : null;
    }

    public static function newReference(): string
    {
        $reference = '';
        for ($i = 0; $i < self::REFERENCE_LENGTH; ++$i) {
            $reference .= self::REFERENCE_ALPHABET[random_int(0, \strlen(self::REFERENCE_ALPHABET) - 1)];
        }

        return $reference;
    }

    public function graphUrl(string $path): string
    {
        $base = preg_match('#^https?://#i', $this->graphBaseUrl) === 1 ? rtrim($this->graphBaseUrl, '/') : self::DEFAULT_GRAPH_URL;

        return sprintf('%s/%s/%s', $base, rawurlencode($this->graphVersion !== '' ? $this->graphVersion : 'v21.0'), ltrim($path, '/'));
    }
}
