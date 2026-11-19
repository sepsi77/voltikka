<?php

namespace App\Services\DevelopmentDatabase;

use PDO;
use Throwable;

class ProductionMySqlConnection
{
    private const URL_VARIABLE = 'PRODUCTION_MYSQL_URL';

    public function connect(): PDO
    {
        [$dsn, $user, $password] = $this->connectionParameters();

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 15,
        ];

        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
        }

        try {
            return new PDO($dsn, $user, $password, $options);
        } catch (Throwable $exception) {
            throw new DatabaseSyncException('The production database connection failed.', previous: $exception);
        }
    }

    public function beginReadOnlyConsistentTransaction(PDO $source): void
    {
        try {
            $source->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        } catch (Throwable $exception) {
            throw new DatabaseSyncException('The read-only production transaction could not start.', previous: $exception);
        }
    }

    public function rollBackReadOnlyTransaction(PDO $source): void
    {
        try {
            $source->exec('ROLLBACK');
        } catch (Throwable) {
            // The source transaction is read-only. Closing PDO also ends it.
        }
    }

    /**
     * The wrapper script gives the source as PRODUCTION_MYSQL_URL in this process environment only.
     * It points to the local end of its SSH tunnel to the VPS database.
     *
     * @return array{string, string, string}
     */
    private function connectionParameters(): array
    {
        $url = getenv(self::URL_VARIABLE);

        if (! is_string($url) || $url === '') {
            throw new DatabaseSyncException(self::URL_VARIABLE.' is not set. Run scripts/sync-production-database.sh.');
        }

        $parts = parse_url($url);

        if (
            $parts === false
            || ($parts['scheme'] ?? null) !== 'mysql'
            || ! isset($parts['host'], $parts['port'], $parts['user'], $parts['pass'])
        ) {
            throw new DatabaseSyncException(self::URL_VARIABLE.' is not a valid MySQL URL.');
        }

        $database = ltrim((string) ($parts['path'] ?? ''), '/');

        if ($database === '') {
            throw new DatabaseSyncException(self::URL_VARIABLE.' does not contain a database name.');
        }

        return [
            $this->dsn((string) $parts['host'], (int) $parts['port'], rawurldecode($database)),
            rawurldecode((string) $parts['user']),
            rawurldecode((string) $parts['pass']),
        ];
    }

    private function dsn(string $host, int $port, string $database): string
    {
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
    }
}
