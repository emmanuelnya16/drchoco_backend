<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Clé des limites de débit par adresse IP.
 *
 * - IPv4 : l'adresse entière ; IPv6 : le préfixe /64 (un abonné ou un serveur dispose de tout un /64 :
 *   sans ce regroupement, chaque adresse aurait sa propre limite).
 * - Empreinte HMAC (secret de l'application) : l'adresse n'apparaît jamais en clair dans les compteurs
 *   ni dans les noms des fichiers de verrou (var/lock), et ne peut pas être retrouvée sans le secret.
 */
final class ClientIp
{
    public static function limiterKey(?string $ip, string $secret): string
    {
        if ($ip === null || filter_var($ip, \FILTER_VALIDATE_IP) === false) {
            return 'inconnue';
        }

        return substr(hash_hmac('sha256', IpUtils::anonymize($ip, 0, 8), $secret), 0, 32);
    }
}
