<?php

namespace App\Audit;

use App\Entity\Admin;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Journal des actions sensibles de l'équipe (P9) : qui, quand, depuis quelle adresse, quoi (avant / après).
 * Connexions, codes marchands et réglages, comptes, prix et stocks, paiements validés, clients bloqués…
 *
 * Écrit dans var/log/audit-AAAA-MM-JJ.log (une ligne JSON par action), gardé 400 jours, y compris en production (le
 * journal général n'y garde que les erreurs). Aucune donnée de client, sauf la référence d'une réservation ou un
 * numéro masqué. Une panne d'écriture n'empêche jamais l'action.
 */
final class AuditLog
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.audit')]
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requests,
    ) {}

    /**
     * Champs modifiés, avec leur valeur avant et après (textes longs coupés).
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public static function changes(array $before, array $after): array
    {
        $short = static fn (mixed $v): mixed => \is_string($v) ? mb_substr($v, 0, 200) : $v;
        $changes = [];
        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $changes[$field] = ['before' => $short($before[$field] ?? null), 'after' => $short($value)];
            }
        }

        return $changes;
    }

    /**
     * @param string               $event nom court et stable (« settings.payment », « seller.password »…)
     * @param array<string, mixed> $data  valeurs utiles à une enquête, sans secret
     * @param bool                 $withIp faux pour un événement causé par un client (son adresse n'est pas gardée 400 jours)
     */
    public function record(string $event, ?Admin $actor = null, array $data = [], bool $withIp = true): void
    {
        $context = [];
        if ($actor !== null) {
            $context['admin'] = ['id' => $actor->getId(), 'name' => $actor->getName(), 'role' => $actor->getRole()];
        }
        if ($withIp) {
            $context['ip'] = $this->requests->getMainRequest()?->getClientIp() ?? 'ligne de commande';
        }
        if ($data !== []) {
            $context['data'] = $data;
        }

        try {
            $this->logger->notice($event, $context);
        } catch (\Throwable) {
            // Journal indisponible (disque plein…) : l'action n'est jamais bloquée pour autant.
        }
    }
}
