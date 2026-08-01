<?php

namespace App\Services\DevelopmentDatabase;

use PDO;
use Throwable;

class ProductionMySqlConnection
{
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
     * @return array{string, string, string}
     */
    private function connectionParameters(): array
    {
        $url = $this->value('MYSQL_PUBLIC_URL');

        if ($url !== null) {
            $parts = parse_url($url);

            if (
                $parts === false
                || ($parts['scheme'] ?? null) !== 'mysql'
                || ! isset($parts['host'], $parts['user'], $parts['pass'])
            ) {
                throw new DatabaseSyncException('MYSQL_PUBLIC_URL is not a valid MySQL URL.');
            }

            $database = ltrim((string) ($parts['path'] ?? ''), '/');

            if ($database === '') {
                throw new DatabaseSyncException('MYSQL_PUBLIC_URL does not contain a database name.');
            }

            $port = isset($parts['port']) ? (int) $parts['port'] : 3306;

            return [
                $this->dsn((string) $parts['host'], $port, rawurldecode($database)),
                rawurldecode((string) $parts['user']),
                rawurldecode((string) $parts['pass']),
            ];
        }

        $proxyHost = $this->value('RAILWAY_TCP_PROXY_DOMAIN');
        $proxyPort = $this->value('RAILWAY_TCP_PROXY_PORT');

        if (($proxyHost === null) !== ($proxyPort === null)) {
            throw new DatabaseSyncException('The injected Railway TCP proxy fields are incomplete.');
        }

        $host = $proxyHost ?? $this->firstValue(['MYSQLHOST', 'MYSQL_HOST']);
        $port = $proxyPort ?? $this->firstValue(['MYSQLPORT', 'MYSQL_PORT']);
        $user = $this->firstValue(['MYSQLUSER', 'MYSQL_USER']);
        $password = $this->firstValue(['MYSQLPASSWORD', 'MYSQL_PASSWORD']);
        $database = $this->firstValue(['MYSQLDATABASE', 'MYSQL_DATABASE']);

        if ($host === null || $port === null || $user === null || $password === null || $database === null) {
            throw new DatabaseSyncException('The injected MySQL connection fields are incomplete.');
        }

        if (filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            throw new DatabaseSyncException('The injected MySQL port is not valid.');
        }

        return [$this->dsn($host, (int) $port, $database), $user, $password];
    }

    private function dsn(string $host, int $port, string $database): string
    {
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
    }

    /**
     * @param  list<string>  $names
     */
    private function firstValue(array $names): ?string
    {
        foreach ($names as $name) {
            $value = $this->value($name);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function value(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
