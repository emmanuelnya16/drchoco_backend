<?php

namespace App\Admin;

use App\Entity\Admin;
use App\Security\CredentialFingerprint;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Appareil déjà utilisé par un compte de l'équipe : cookie signé posé après une connexion réussie (180 jours).
 *
 * Il n'ouvre rien et ne remplace pas le mot de passe. Il évite seulement qu'un inconnu, en essayant de faux mots de
 * passe depuis plusieurs adresses, empêche le vrai propriétaire de se connecter : un appareil connu a son propre
 * compteur d'essais, les autres partagent celui du compte (App\Admin\AdminLogin). Il cesse d'être valable dès que le
 * mot de passe change.
 */
final class AdminDevice
{
    public const COOKIE = 'drc_admin_device';
    private const PATH = '/api/admin/auth';
    private const TTL_SECONDS = 180 * 86400;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly string $appEnv,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {}

    /** Identifiant de l'appareil s'il est connu de ce compte, sinon null. */
    public function knownTo(Admin $admin, Request $request): ?string
    {
        $value = $request->cookies->all()[self::COOKIE] ?? null;
        if (!\is_string($value) || preg_match('/^(\d{1,10})\.([0-9a-f]{32})\.([0-9a-f]{64})\z/', $value, $m) !== 1) {
            return null;
        }
        [, $expires, $device, $signature] = $m;
        if ((int) $expires < $this->clock->now()->getTimestamp()) {
            return null;
        }

        return hash_equals($this->sign($admin, (int) $expires, $device), $signature) ? $device : null;
    }

    public function cookieFor(Admin $admin, Request $request): Cookie
    {
        $device = $this->knownTo($admin, $request) ?? bin2hex(random_bytes(16));
        $expires = $this->clock->now()->getTimestamp() + self::TTL_SECONDS;

        return Cookie::create(self::COOKIE)
            ->withValue($expires . '.' . $device . '.' . $this->sign($admin, $expires, $device))
            ->withExpires($expires)
            ->withPath(self::PATH)
            ->withSecure($this->appEnv === 'prod' || $request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    private function sign(Admin $admin, int $expires, string $device): string
    {
        return hash_hmac('sha256', sprintf('admin-device|%d|%d|%s|%s', $admin->getId(), $expires, $device, CredentialFingerprint::of($admin, $this->secret)), $this->secret);
    }
}
