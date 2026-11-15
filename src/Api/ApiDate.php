<?php

namespace App\Api;

/**
 * Dates de l'API : ISO 8601 en UTC avec millisecondes (« 2026-10-31T11:00:00.000Z »), contrat §1.3.
 */
final class ApiDate
{
    public static function format(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Lit une date ISO 8601 (avec fuseau) et la ramène en UTC. */
    public static function parse(string $value): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
    }
}
