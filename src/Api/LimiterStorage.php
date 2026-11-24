<?php

namespace App\Api;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Les compteurs des limites de débit sont des fichiers. Si le disque est plein, Symfony ne peut plus les écrire et
 * accepte tout : les mots de passe de l'équipe pourraient alors être essayés sans limite. Cette vérification refuse la
 * connexion tant que les compteurs ne peuvent pas être écrits.
 */
final class LimiterStorage
{
    public function __construct(
        #[Autowire(service: 'cache.rate_limiter')]
        private readonly CacheItemPoolInterface $pool,
        private readonly LoggerInterface $logger,
    ) {}

    /** @throws ApiException 503 */
    public function assertWritable(): void
    {
        $item = $this->pool->getItem('ecriture_possible');
        $item->set(bin2hex(random_bytes(4)));
        $item->expiresAfter(3600);
        if ($this->pool->save($item)) {
            return;
        }

        $this->logger->critical('Compteurs d’essais impossibles à écrire (disque plein ou droits du dossier var) : connexion de l’équipe refusée.');

        throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Connexion momentanément indisponible. Réessayez dans quelques minutes.');
    }
}
