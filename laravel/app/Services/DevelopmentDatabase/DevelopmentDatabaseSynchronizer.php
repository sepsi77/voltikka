<?php

namespace App\Services\DevelopmentDatabase;

use PDO;
use Throwable;

class DevelopmentDatabaseSynchronizer
{
    public const LOCALLY_DERIVED_TABLE = 'contract_source_observations';

    /** @var list<string> */
    public const EXCLUDED_TABLES = [
        'users',
        'password_reset_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'spot_social_publications',
        'data_freshness_checkpoints',
    ];

    public function __construct(
        private readonly ContractSourceObservationRebuilder $observationRebuilder = new ContractSourceObservationRebuilder,
    ) {}

    /**
     * @return array<string, int>
     */
    public function copy(PDO $source, PDO $target): array
    {
        $this->requireDriver($source, ['mysql', 'sqlite'], 'source');
        $this->requireDriver($target, ['sqlite'], 'target');

        $sourceTables = array_fill_keys($this->tableNames($source), true);
        $targetTables = $this->tableNames($target);

        if (! in_array('migrations', $targetTables, true)) {
            throw new DatabaseSyncException('The target database is not migrated.');
        }

        $tables = array_values(array_filter(
            $targetTables,
            fn (string $table): bool => $table !== 'migrations'
                && ! in_array($table, self::EXCLUDED_TABLES, true)
        ));
        sort($tables);

        $missingDerivedTable = false;

        foreach ($tables as $table) {
            if (isset($sourceTables[$table])) {
                continue;
            }

            if ($table === self::LOCALLY_DERIVED_TABLE) {
                $missingDerivedTable = true;

                continue;
            }

            throw new DatabaseSyncException("The production database does not contain target table [{$table}].");
        }

        if ($missingDerivedTable) {
            $tables = array_values(array_filter(
                $tables,
                fn (string $table): bool => $table !== self::LOCALLY_DERIVED_TABLE
            ));
        }

        $targetTableSet = array_fill_keys($targetTables, true);
        $sourceOnlyTables = array_values(array_filter(
            array_keys($sourceTables),
            fn (string $table): bool => $table !== 'migrations'
                && ! in_array($table, self::EXCLUDED_TABLES, true)
                && ! isset($targetTableSet[$table])
        ));
        sort($sourceOnlyTables);

        if ($sourceOnlyTables !== []) {
            throw new DatabaseSyncException(
                'The target database does not contain production application tables: '.implode(', ', $sourceOnlyTables).'.'
            );
        }

        $expectedCounts = [];
        $target->exec('PRAGMA foreign_keys = OFF');

        try {
            $target->beginTransaction();

            foreach ($tables as $table) {
                $expectedCounts[$table] = $this->copyTable($source, $target, $table);
            }

            $target->commit();
        } catch (Throwable $exception) {
            if ($target->inTransaction()) {
                $target->rollBack();
            }

            if ($exception instanceof DatabaseSyncException) {
                throw $exception;
            }

            throw new DatabaseSyncException('The database copy failed.', previous: $exception);
        } finally {
            $target->exec('PRAGMA foreign_keys = ON');
        }

        if ($missingDerivedTable) {
            $expectedCounts[self::LOCALLY_DERIVED_TABLE] = $this->observationRebuilder->rebuild();
            ksort($expectedCounts);
        }

        $this->validateTarget($target, $expectedCounts);

        return $expectedCounts;
    }

    /**
     * @param  array<string, int>  $expectedCounts
     */
    public function validateTarget(PDO $target, array $expectedCounts): void
    {
        $this->requireDriver($target, ['sqlite'], 'target');

        foreach ($expectedCounts as $table => $sourceCount) {
            $targetCount = $this->rowCount($target, $table);

            if ($sourceCount !== $targetCount) {
                throw new DatabaseSyncException(
                    "Row count validation failed for [{$table}]: source {$sourceCount}, target {$targetCount}."
                );
            }
        }

        $foreignKeyError = $target->query('PRAGMA foreign_key_check')->fetch(PDO::FETCH_ASSOC);

        if ($foreignKeyError !== false) {
            $table = (string) ($foreignKeyError['table'] ?? 'unknown');

            throw new DatabaseSyncException("Foreign key validation failed for [{$table}].");
        }

        $integrityResults = $target->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);

        if ($integrityResults !== ['ok']) {
            throw new DatabaseSyncException('SQLite integrity validation failed.');
        }
    }

    private function copyTable(PDO $source, PDO $target, string $table): int
    {
        $sourceColumns = $this->columns($source, $table);
        $targetColumns = $this->columns($target, $table);
        $sourceColumnNames = array_column($sourceColumns, 'name');
        $targetColumnNames = array_column($targetColumns, 'name');

        $missingRequired = [];

        foreach ($targetColumns as $column) {
            if ($column['required'] && ! in_array($column['name'], $sourceColumnNames, true)) {
                $missingRequired[] = $column['name'];
            }
        }

        if ($missingRequired !== []) {
            throw new DatabaseSyncException(
                "Production table [{$table}] does not contain required target columns: ".implode(', ', $missingRequired).'.'
            );
        }

        $sharedColumns = array_values(array_intersect($targetColumnNames, $sourceColumnNames));
        $sourceCount = $this->rowCount($source, $table);

        if ($sharedColumns === [] && $sourceCount > 0) {
            throw new DatabaseSyncException("Table [{$table}] has rows but no shared columns.");
        }

        if ($sourceCount === 0) {
            return 0;
        }

        $sourceColumnSql = implode(', ', array_map(
            fn (string $column): string => $this->quoteIdentifier($source, $column),
            $sharedColumns
        ));
        $targetColumnSql = implode(', ', array_map(
            fn (string $column): string => $this->quoteIdentifier($target, $column),
            $sharedColumns
        ));

        $select = $source->query(sprintf(
            'SELECT %s FROM %s',
            $sourceColumnSql,
            $this->quoteIdentifier($source, $table)
        ));
        $insert = $target->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($target, $table),
            $targetColumnSql,
            implode(', ', array_fill(0, count($sharedColumns), '?'))
        ));

        $copied = 0;

        while (($row = $select->fetch(PDO::FETCH_ASSOC)) !== false) {
            $insert->execute(array_map(fn (string $column): mixed => $row[$column], $sharedColumns));
            $copied++;
        }

        $select->closeCursor();

        if ($copied !== $sourceCount) {
            throw new DatabaseSyncException(
                "Source row count changed while [{$table}] was copied: expected {$sourceCount}, read {$copied}."
            );
        }

        $targetCount = $this->rowCount($target, $table);

        if ($targetCount !== $sourceCount) {
            throw new DatabaseSyncException(
                "Row count validation failed for [{$table}]: source {$sourceCount}, target {$targetCount}."
            );
        }

        return $sourceCount;
    }

    /**
     * @return list<string>
     */
    private function tableNames(PDO $database): array
    {
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $statement = $database->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            );
        } else {
            $statement = $database->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        }

        return array_values(array_map(
            static fn (array $row): string => (string) $row[0],
            $statement->fetchAll(PDO::FETCH_NUM)
        ));
    }

    /**
     * @return list<array{name: string, required: bool}>
     */
    private function columns(PDO $database, string $table): array
    {
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $rows = $database->query(
                'PRAGMA table_info('.$this->quoteIdentifier($database, $table).')'
            )->fetchAll(PDO::FETCH_ASSOC);

            return array_values(array_map(static fn (array $row): array => [
                'name' => (string) $row['name'],
                'required' => (int) $row['pk'] > 0
                    || ((int) $row['notnull'] === 1 && $row['dflt_value'] === null),
            ], $rows));
        }

        $rows = $database->query(
            'SHOW COLUMNS FROM '.$this->quoteIdentifier($database, $table)
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(static fn (array $row): array => [
            'name' => (string) $row['Field'],
            'required' => false,
        ], $rows));
    }

    private function rowCount(PDO $database, string $table): int
    {
        $statement = $database->query(
            'SELECT COUNT(*) FROM '.$this->quoteIdentifier($database, $table)
        );
        $count = (int) $statement->fetchColumn();
        $statement->closeCursor();

        return $count;
    }

    private function quoteIdentifier(PDO $database, string $identifier): string
    {
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            return '`'.str_replace('`', '``', $identifier).'`';
        }

        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * @param  list<string>  $allowed
     */
    private function requireDriver(PDO $database, array $allowed, string $name): void
    {
        $driver = (string) $database->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (! in_array($driver, $allowed, true)) {
            throw new DatabaseSyncException("The {$name} PDO driver [{$driver}] is not supported.");
        }
    }
}
