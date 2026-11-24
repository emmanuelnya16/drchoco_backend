<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /** Longueur minimale de APP_SECRET en production (« openssl rand -hex 32 » en donne 64). */
    public const SECRET_MIN_LENGTH = 32;

    /** Empreinte (SHA-256) du secret de développement présent dans l'historique git : connu de qui lit le dépôt. */
    private const PUBLISHED_SECRET = 'abd13c6e3f55670a75b6d3ed97349d9be9a955798c96e33f0250389ba3d0661e';

    public function boot(): void
    {
        // Liens des preuves de paiement, codes SMS, sessions : tout est signé avec APP_SECRET. Sans lui, le site
        // fonctionnerait normalement mais n'importe qui pourrait fabriquer ces signatures : on refuse de démarrer.
        if ($this->environment === 'prod' && !self::isStrongSecret(self::secret())) {
            throw new \LogicException(sprintf('APP_SECRET est absent, trop court ou déjà publié : mettre dans .env.local une valeur aléatoire d’au moins %d caractères (php -r "echo bin2hex(random_bytes(32));").', self::SECRET_MIN_LENGTH));
        }

        // Toutes les dates sont calculées et stockées en UTC, quel que soit le php.ini
        // (XAMPP est réglé sur Europe/Berlin ; le PHP des crons cPanel peut différer du PHP web).
        // L'affichage à l'heure de Yaoundé/Douala (UTC+1) est fait par le front.
        date_default_timezone_set('UTC');

        parent::boot();
    }

    /**
     * Le mode développement montre les erreurs en détail : il ne répond qu'à cet ordinateur et au réseau local. Un
     * serveur installé sans APP_ENV=prod est ainsi arrêté net au lieu d'être en ligne sans protection.
     * APP_DEV_HOSTS (noms séparés par des virgules) autorise d'autres noms, par exemple un tunnel d'essai.
     */
    public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
    {
        if ($type === HttpKernelInterface::MAIN_REQUEST && $this->environment === 'dev' && !self::isLocal($request)) {
            return new JsonResponse([
                'code'    => 'DEV_MODE',
                'message' => 'API en mode développement : elle ne répond qu’aux adresses locales. Sur un serveur, mettre APP_ENV=prod dans .env.local.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return parent::handle($request, $type, $catch);
    }

    public static function isStrongSecret(string $secret): bool
    {
        return \strlen(trim($secret)) >= self::SECRET_MIN_LENGTH && hash('sha256', trim($secret)) !== self::PUBLISHED_SECRET;
    }

    private static function isLocal(Request $request): bool
    {
        // REMOTE_ADDR et non l'adresse annoncée par un en-tête : c'est la connexion réelle qui compte. PRIVATE_RANGES : liste
        // prévue pour autoriser (celle de isPrivateIp() contient des adresses qu'un visiteur public peut avoir).
        $ip = (string) $request->server->get('REMOTE_ADDR', '');
        if ($ip !== '' && !IpUtils::checkIp($ip, IpUtils::PRIVATE_RANGES)) {
            return false;
        }

        try {
            $host = strtolower(trim($request->getHost(), '[]'));
        } catch (SuspiciousOperationException) {
            return false;
        }
        $allowed = array_filter(array_map('trim', explode(',', strtolower((string) ($_SERVER['APP_DEV_HOSTS'] ?? $_ENV['APP_DEV_HOSTS'] ?? '')))));

        return $host === 'localhost'
            || preg_match('/\.(?:localhost|local|test)$/D', $host) === 1
            || (filter_var($host, \FILTER_VALIDATE_IP) !== false && IpUtils::checkIp($host, IpUtils::PRIVATE_RANGES))
            || \in_array($host, $allowed, true);
    }

    private static function secret(): string
    {
        $value = $_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? getenv('APP_SECRET');

        return \is_string($value) ? trim($value) : '';
    }
}
