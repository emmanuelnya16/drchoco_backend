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
    /** Numéro WhatsApp de la boutique qui reçoit les codes, au format +2376XXXXXXXX (null si absent ou invalide). */
    public readonly ?string $businessNumber;

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
    ) {
        $this->businessNumber = PhoneNumber::normalizeCameroonMobile($businessNumber);
    }

    /**
     * Connexion possible : un numéro où envoyer le code, et la clé qui authentifie les messages transmis par Meta.
     * Sinon l'app propose le code par SMS.
     */
    public function isLoginReady(): bool
    {
        return $this->businessNumber !== null && $this->appSecret !== '';
    }

    /** Réponses au client (« Numéro vérifié ») possibles : sinon les messages sont seulement lus. */
    public function canReply(): bool
    {
        return $this->token !== '' && $this->phoneNumberId !== '';
    }

    /** Lien qui ouvre WhatsApp sur la conversation avec la boutique, message déjà écrit (le code seul). */
    public function waLink(string $code): string
    {
        return sprintf('https://wa.me/%s?text=%s', ltrim((string) $this->businessNumber, '+'), rawurlencode(self::messageFor($code)));
    }

    public static function messageFor(string $code): string
    {
        return 'Mon code DR Choco : ' . $code;
    }

    public function graphUrl(string $path): string
    {
        return sprintf('https://graph.facebook.com/%s/%s', rawurlencode($this->graphVersion !== '' ? $this->graphVersion : 'v21.0'), ltrim($path, '/'));
    }
}
