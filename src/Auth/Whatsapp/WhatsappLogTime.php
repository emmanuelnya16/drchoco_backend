<?php

namespace App\Auth\Whatsapp;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Journal de la connexion par WhatsApp : heures du Cameroun (comme sur le téléphone du client), alors que le reste de
 * l'API travaille en UTC.
 */
#[AsMonologProcessor(channel: 'whatsapp')]
final class WhatsappLogTime
{
    private const TIMEZONE = 'Africa/Douala';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(datetime: $record->datetime->setTimezone(new \DateTimeZone(self::TIMEZONE)));
    }
}
