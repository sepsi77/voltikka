<?php

namespace App\Support;

use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\QueryException;
use PDOException;
use Sentry\Event;
use Sentry\EventHint;
use Throwable;

/**
 * Recognizes a database that is unreachable (restart, upgrade, network loss), not a faulty query.
 * Only PDO/query exceptions count, because the framework phrase list also matches generic
 * socket text such as "reset by peer" that HTTP clients produce.
 */
final class DatabaseConnectionLost
{
    use DetectsLostConnections;

    public const FINGERPRINT = 'database-connection-lost';

    public static function matches(Throwable $exception): bool
    {
        $detector = new self;

        // Blade/Livewire rendering can wrap the database exception in a ViewException.
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (($current instanceof QueryException || $current instanceof PDOException)
                && $detector->causedByLostConnection($current)) {
                return true;
            }
        }

        return false;
    }

    /** One outage creates many distinct query messages; group them into one Sentry Issue. */
    public static function beforeSend(Event $event, ?EventHint $hint): ?Event
    {
        if ($hint?->exception !== null && self::matches($hint->exception)) {
            $event->setFingerprint([self::FINGERPRINT]);
        }

        return $event;
    }
}
