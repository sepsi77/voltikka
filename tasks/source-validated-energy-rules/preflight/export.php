<?php

declare(strict_types=1);

use App\Services\DevelopmentDatabase\ProductionMySqlConnection;

// This file can be required by offline tests. Only direct CLI execution connects.
function preflightIdentifier(string $value): string
{
    if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value)) {
        throw new RuntimeException('Invalid identifier.');
    }

    return '"'.$value.'"';
}

function preflightAffinity(string $type): string
{
    return match (strtolower($type)) {
        'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'year', 'bit' => 'INTEGER',
        'decimal', 'numeric', 'float', 'double', 'real' => 'NUMERIC',
        'binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob' => 'BLOB',
        default => 'TEXT', // In particular: JSON, dates and contract IDs.
    };
}

function preflightCreateTable(PDO $db, string $table, array $columns): void
{
    if ($columns === []) {
        throw new RuntimeException('Missing schema.');
    }
    $definitions = array_map(fn ($c) => preflightIdentifier($c['COLUMN_NAME']).' '.preflightAffinity($c['DATA_TYPE']), $columns);
    $db->exec('CREATE TABLE '.preflightIdentifier($table).' ('.implode(',', $definitions).')');
}

function preflightIndexSql(string $table, string $name, array $parts, array $columns): string
{
    if ($parts === []) {
        throw new RuntimeException('Missing source index.');
    }
    ksort($parts);
    if (! in_array(reset($parts)['NON_UNIQUE'] ?? null, [0, 1, '0', '1'], true)) {
        throw new RuntimeException('Missing source uniqueness metadata.');
    }
    $unique = (int) reset($parts)['NON_UNIQUE'] === 0;
    foreach ($parts as $sequence => $part) {
        $column = $columns[$part['COLUMN_NAME']] ?? null;
        if ($column === null || ! array_key_exists('COLLATION_NAME', $column)
            || ! array_key_exists('SUB_PART', $part) || $part['SUB_PART'] !== null || ($part['INDEX_TYPE'] ?? null) !== 'BTREE'
            || ! in_array($part['NON_UNIQUE'] ?? null, [0, 1, '0', '1'], true)
            || (int) $part['NON_UNIQUE'] !== ($unique ? 0 : 1)
            || ! empty($part['EXPRESSION']) || ! empty($part['WHERE'])) {
            throw new RuntimeException('Unsupported source index semantics.');
        }
    }
    if (array_keys($parts) !== range(1, count($parts))) {
        throw new RuntimeException('Incomplete source index.');
    }

    // Full-column lookup indexes only; source constraints remain manifest metadata.
    return 'CREATE INDEX '.preflightIdentifier('idx_'.$table.'_'.$name).' ON '.preflightIdentifier($table)
        .' ('.implode(',', array_map(fn ($i) => preflightIdentifier($i['COLUMN_NAME']), $parts)).')';
}

function preflightPrimaryKeyDuplicates(PDO $db, string $table, array $primary): int
{
    $keys = implode(',', array_map(fn ($i) => preflightIdentifier($i['COLUMN_NAME']), $primary));

    return (int) $db->query('SELECT COUNT(*) FROM (SELECT '.$keys.' FROM '.preflightIdentifier($table).' GROUP BY '.$keys.' HAVING COUNT(*)>1)')->fetchColumn();
}

function preflightPublicationFacts(PDO $db): array
{
    $from = ' FROM electricity_contracts c JOIN contract_source_observations o ON o.id=c.current_source_observation_id JOIN contract_interpretations i ON i.id=c.published_interpretation_id';
    $select = 'SELECT c.id,c.current_source_observation_id,c.published_interpretation_id,o.source_snapshot_id AS current_snapshot,i.source_snapshot_id AS published_snapshot,i.analysis_source_observation_id';

    return [
        'current_publication_source_mismatches' => $db->query($select.$from.' WHERE o.source_snapshot_id IS NOT i.source_snapshot_id OR (i.analysis_source_observation_id IS NOT NULL AND c.current_source_observation_id IS NOT i.analysis_source_observation_id)')->fetchAll(PDO::FETCH_ASSOC),
        'current_publication_null_analysis_source_pointers' => $db->query($select.$from.' WHERE i.analysis_source_observation_id IS NULL')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function preflightGraph(array $rows, array $active): array
{
    $graph = $reverse = [];
    foreach ($rows as $row) {
        $id = (string) $row['id'];
        $graph[$id] = $row['replaced_by_contract_id'];
        if ($row['replaced_by_contract_id'] !== null) {
            $reverse[(string) $row['replaced_by_contract_id']][] = $id;
        }
    }
    $scope = [];
    $queue = array_values($active);
    for ($i = 0; $i < count($queue); $i++) {
        $id = (string) $queue[$i];
        if (isset($scope[$id]) || ! array_key_exists($id, $graph)) {
            continue;
        }
        $scope[$id] = true;
        foreach ($reverse[$id] ?? [] as $old) {
            $queue[] = $old;
        }
    }
    $missing = $cycles = $done = [];
    foreach ($graph as $id => $next) {
        if ($next !== null && ! array_key_exists((string) $next, $graph)) {
            $missing[] = ['id' => $id, 'replaced_by_contract_id' => $next];
        }
        $path = [];
        $cursor = (string) $id;
        while (array_key_exists($cursor, $graph) && ! isset($done[$cursor])) {
            if (isset($path[$cursor])) {
                $keys = array_keys($path);
                $cycles[] = array_slice($keys, array_search($cursor, array_map('strval', $keys), true));
                break;
            }
            $path[$cursor] = true;
            if ($graph[$cursor] === null) {
                break;
            }
            $cursor = (string) $graph[$cursor];
        }
        $done += $path;
    }

    return ['scope' => array_keys($scope), 'missing_replacements' => $missing, 'cycles' => $cycles,
        'missing_active_ids' => array_values(array_filter($active, fn ($id) => ! array_key_exists((string) $id, $graph)))];
}

function preflightRun(array $argv): int
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    ini_set('memory_limit', '512M');
    umask(0077);
    set_error_handler(static function (int $severity): bool {
        if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            return true;
        }
        throw new RuntimeException('Export failed.');
    });
    $stage = 'initialization';
    $source = $connection = $db = null;
    $directory = null;
    $started = microtime(true);
    $manifest = ['status' => 'incomplete', 'started_at_utc' => gmdate('c'),
        'expected_production_commit' => '917de212fdc4ef4862903268c3d33ba5cd886e73',
        'expected_production_profile' => 'schema-v4/prompt-v19/validator-v17',
        'expected_calculated_cost_schema' => 17, 'candidate_calculated_cost_schema' => 19,
        'deployment_identity' => 'Operator-supplied context, not verified by database queries.',
        'transaction' => 'REPEATABLE READ; WITH CONSISTENT SNAPSHOT, READ ONLY; ROLLBACK',
        'limits' => ['rows_per_query' => 250000, 'total_rows' => 1000000, 'data_bytes' => 268435456,
            'query_timeout_ms' => 20000, 'wall_seconds' => 240], 'queries' => []];
    $rowsTotal = $bytesTotal = 0;
    $check = static function () use ($started): void {
        if (microtime(true) - $started >= 240) {
            throw new RuntimeException('Deadline.');
        }
    };
    try {
        if (count($argv) !== 2 || ! preg_match('~^--target=(/tmp/[A-Za-z0-9][A-Za-z0-9._-]*)$~D', $argv[1], $match)) {
            throw new RuntimeException('Target required.');
        }
        if (! extension_loaded('pdo_mysql') || ! extension_loaded('pdo_sqlite') || ! function_exists('pcntl_alarm')) {
            throw new RuntimeException('Missing extension.');
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {
            throw new RuntimeException('Deadline.');
        });
        pcntl_alarm(240);
        if (file_exists($match[1]) || is_link($match[1]) || ! mkdir($match[1], 0700)) {
            throw new RuntimeException('Target exists.');
        }
        $directory = $match[1];
        require dirname(__DIR__, 3).'/laravel/vendor/autoload.php';
        $stage = 'connection';
        $connection = new ProductionMySqlConnection;
        $source = $connection->connect();
        $connection->beginReadOnlyConsistentTransaction($source);
        $source->exec('SET SESSION MAX_EXECUTION_TIME=20000');
        $source->exec('SET SESSION lock_wait_timeout=20');
        $db = new PDO('sqlite:'.$directory.'/snapshot.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA page_size=4096; PRAGMA max_page_count=65536; PRAGMA journal_mode=DELETE');
        $db->beginTransaction();
        $read = function (string $name, string $select, string $body, callable $consume) use ($source, &$stage, &$manifest, &$rowsTotal, &$bytesTotal, $check): void {
            $check();
            $stage = 'source_count';
            $countSql = 'SELECT /*+ MAX_EXECUTION_TIME(20000) */ COUNT(*) FROM (SELECT 1 '.$body.' LIMIT 250001) AS bounded_rows';
            $s = $source->query($countSql);
            try {
                $expected = (int) $s->fetchColumn();
            } finally {
                $s->closeCursor();
            }
            if ($expected > 250000 || $rowsTotal + $expected > 1000000) {
                throw new RuntimeException('Row bound.');
            }
            $stage = 'source_read';
            $sql = 'SELECT /*+ MAX_EXECUTION_TIME(20000) */ '.$select.' '.$body.' LIMIT 250001';
            $manifest['queries'][$name] = ['count_sql' => $countSql, 'count_sha256' => hash('sha256', $countSql),
                'sql' => $sql, 'sql_sha256' => hash('sha256', $sql), 'expected_rows' => $expected];
            $s = $source->query($sql);
            $count = $bytes = 0;
            $hash = hash_init('sha256');
            try {
                while ($row = $s->fetch(PDO::FETCH_ASSOC)) {
                    $check();
                    if (++$count > $expected || ++$rowsTotal > 1000000) {
                        throw new RuntimeException('Row bound.');
                    }
                    $json = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                    $bytes += strlen($json);
                    $bytesTotal += strlen($json);
                    if ($bytesTotal > 268435456) {
                        throw new RuntimeException('Byte bound.');
                    }
                    hash_update($hash, $json);
                    $consume($row);
                }
            } finally {
                $s->closeCursor();
            }
            if ($count !== $expected) {
                throw new RuntimeException('Count mismatch.');
            }
            $manifest['queries'][$name] += ['rows' => $count, 'bytes' => $bytes, 'row_stream_sha256' => hash_final($hash)];
        };
        $collect = function (string $name, string $select, string $body) use ($read): array {
            $result = [];
            $read($name, $select, $body, static function ($row) use (&$result): void {
                $result[] = $row;
            });

            return $result;
        };
        // The complete compact replacement graph is read before business-table data.
        $graph = $collect('replacement_graph', 'id,replaced_by_contract_id', 'FROM electricity_contracts');
        $tables = ['active_contracts', 'electricity_contracts', 'companies', 'contract_source_observations',
            'contract_source_snapshots', 'contract_interpretations', 'price_components', 'contract_price_snapshots',
            'electricity_futures_eod_prices', 'spot_price_averages', 'spot_prices_hour', 'contract_price_daily_statistics'];
        $quotedTables = implode(',', array_map(fn ($t) => $source->quote($t), $tables));
        $tableMetadata = $collect('source_tables', 'TABLE_NAME,ENGINE,TABLE_COLLATION',
            'FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$quotedTables.')');
        if (count($tableMetadata) !== count($tables) || array_filter($tableMetadata, fn ($t) => $t['ENGINE'] !== 'InnoDB')) {
            throw new RuntimeException('Transactional table required.');
        }
        $manifest['source_tables'] = $tableMetadata;
        $columns = $collect('source_columns', 'TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLUMN_KEY,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION',
            'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$quotedTables.')');
        $indexes = $collect('source_indexes', 'TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE',
            'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$quotedTables.')');
        $manifest['source_columns'] = $columns;
        $manifest['source_indexes'] = $indexes;
        $interpretationColumns = ['id', 'contract_id', 'source_snapshot_id', 'analysis_source_observation_id', 'analysis_fingerprint',
            'status', 'schema_version', 'prompt_version', 'validator_version', 'output', 'validation_errors',
            'published_fields', 'relational_pricing_published', 'started_at', 'completed_at', 'published_at', 'created_at', 'updated_at'];
        $schemas = [];
        foreach ($tables as $table) {
            $selected = array_values(array_filter($columns, fn ($c) => $c['TABLE_NAME'] === $table
                && ($table !== 'contract_interpretations' || in_array($c['COLUMN_NAME'], $interpretationColumns, true))));
            usort($selected, fn ($a, $b) => $a['ORDINAL_POSITION'] <=> $b['ORDINAL_POSITION']);
            preflightCreateTable($db, $table, $selected);
            $schemas[$table] = array_column($selected, 'COLUMN_NAME');
        }
        if (array_diff($interpretationColumns, $schemas['contract_interpretations'])) {
            throw new RuntimeException('Missing proof columns.');
        }
        $manifest['export_columns'] = $schemas;
        $export = function (string $table, string $where = '1=1') use ($db, $schemas, $read): void {
            $names = $schemas[$table];
            $insert = $db->prepare('INSERT INTO '.preflightIdentifier($table).' ('.implode(',', array_map('preflightIdentifier', $names)).') VALUES ('.implode(',', array_fill(0, count($names), '?')).')');
            $select = implode(',', array_map(fn ($n) => '`'.$n.'`', $names));
            $read($table, $select, 'FROM `'.$table.'` WHERE '.$where, static function ($row) use ($insert): void {
                $insert->execute(array_values($row));
            });
        };
        $values = fn (string $sql) => $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        $in = function (array $ids) use ($source): string {
            $ids = array_values(array_unique(array_filter($ids, fn ($v) => $v !== null)));

            return $ids === [] ? '(NULL)' : '('.implode(',', array_map(fn ($v) => $source->quote((string) $v), $ids)).')';
        };
        $export('active_contracts');
        $scope = preflightGraph($graph, $values('SELECT id FROM active_contracts'));
        $manifest['replacement_graph'] = $graph;
        $manifest['graph_validation'] = $scope;
        $scopeIds = $in($scope['scope']);
        $export('electricity_contracts', 'id IN '.$scopeIds);
        $export('companies');
        $published = $in($values('SELECT published_interpretation_id FROM electricity_contracts'));
        $pointed = $in($values('SELECT current_source_observation_id FROM electricity_contracts'));
        $export('contract_interpretations', 'contract_id IN '.$scopeIds.' OR id IN '.$published
            .' OR source_snapshot_id IN (SELECT id FROM contract_source_snapshots WHERE contract_id IN '.$scopeIds.')'
            .' OR analysis_source_observation_id IN (SELECT id FROM contract_source_observations WHERE contract_id IN '.$scopeIds.' OR id IN '.$pointed.')');
        $analysis = $in($values('SELECT analysis_source_observation_id FROM contract_interpretations'));
        $export('contract_source_observations', 'contract_id IN '.$scopeIds.' OR id IN '.$pointed.' OR id IN '.$analysis);
        $snapshots = $in(array_merge($values('SELECT source_snapshot_id FROM contract_interpretations'), $values('SELECT source_snapshot_id FROM contract_source_observations')));
        $export('contract_source_snapshots', 'contract_id IN '.$scopeIds.' OR id IN '.$snapshots);
        $export('price_components', 'electricity_contract_id IN '.$scopeIds);
        $export('contract_price_snapshots', 'contract_id IN '.$scopeIds);
        $export('electricity_futures_eod_prices', "area='FI' AND product='Base'");
        $export('spot_price_averages', "region='FI'");
        $export('spot_prices_hour', "region='FI'");
        $export('contract_price_daily_statistics', "metric_key='energy_price' AND consumption_kwh IS NULL");
        $stage = 'local_validation';
        $indexGroups = [];
        foreach ($indexes as $index) {
            $indexGroups[$index['TABLE_NAME']][$index['INDEX_NAME']][(int) $index['SEQ_IN_INDEX']] = $index;
        }
        $manifest['sqlite_indexes'] = [];
        foreach ($indexGroups as $table => $groups) {
            foreach ($groups as $name => $parts) {
                ksort($parts);
                $selectedColumns = array_column(array_filter($columns, fn ($c) => $c['TABLE_NAME'] === $table && in_array($c['COLUMN_NAME'], $schemas[$table], true)), null, 'COLUMN_NAME');
                $manifest['index_validation'][$table][$name] = ['status' => 'checking'];
                try {
                    $sql = preflightIndexSql($table, $name, $parts, $selectedColumns);
                } catch (RuntimeException) {
                    $manifest['index_validation'][$table][$name] = ['status' => 'unsupported_semantics'];
                    throw new RuntimeException('Unsupported source index.');
                }
                $db->exec($sql);
                $manifest['index_validation'][$table][$name]['status'] = 'lookup_index_created';
                $manifest['sqlite_indexes'][] = $sql;
            }
        }
        $facts = [];
        // A preserved evidence row can have an owner outside the contract scope.
        // Distinguish that case from an owner that does not exist in the full source graph.
        $graphIds = array_fill_keys(array_column($graph, 'id'), true);
        foreach (['contract_interpretations', 'contract_source_observations', 'contract_source_snapshots'] as $table) {
            $owners = $values('SELECT DISTINCT contract_id FROM '.preflightIdentifier($table));
            $facts[$table]['missing_owner_ids_in_full_graph'] = array_values(array_filter($owners, fn ($id) => ! isset($graphIds[$id])));
            $facts[$table]['owner_ids_outside_export_scope'] = array_values(array_diff($owners, $scope['scope']));
        }
        foreach ([['active_contracts', 'id', 'electricity_contracts', 'id'],
            ['electricity_contracts', 'company_name', 'companies', 'name'],
            ['electricity_contracts', 'current_source_observation_id', 'contract_source_observations', 'id'],
            ['electricity_contracts', 'published_interpretation_id', 'contract_interpretations', 'id'],
            ['contract_source_observations', 'source_snapshot_id', 'contract_source_snapshots', 'id'],
            ['contract_interpretations', 'source_snapshot_id', 'contract_source_snapshots', 'id'],
            ['contract_interpretations', 'analysis_source_observation_id', 'contract_source_observations', 'id'],
            ['price_components', 'electricity_contract_id', 'electricity_contracts', 'id'],
            ['contract_price_snapshots', 'contract_id', 'electricity_contracts', 'id']] as [$from, $pointer, $to, $key]) {
            $label = $from.'.'.$pointer;
            $facts[$label]['missing_targets'] = $db->query('SELECT a.id,a.'.preflightIdentifier($pointer).' AS pointer FROM '.preflightIdentifier($from).' a LEFT JOIN '.preflightIdentifier($to).' b ON a.'.preflightIdentifier($pointer).'=b.'.preflightIdentifier($key).' WHERE a.'.preflightIdentifier($pointer).' IS NOT NULL AND b.'.preflightIdentifier($key).' IS NULL')->fetchAll(PDO::FETCH_ASSOC);
            if (in_array('contract_id', $schemas[$to], true)) {
                $owner = $from === 'electricity_contracts' ? 'id' : 'contract_id';
                $facts[$label]['ownership_mismatches'] = $db->query('SELECT a.id,a.'.preflightIdentifier($pointer).' AS pointer,b.contract_id AS target_owner FROM '.preflightIdentifier($from).' a JOIN '.preflightIdentifier($to).' b ON a.'.preflightIdentifier($pointer).'=b.'.preflightIdentifier($key).' WHERE a.'.preflightIdentifier($owner).'<>b.contract_id')->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        $facts += preflightPublicationFacts($db);
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $db->query('SELECT COUNT(*) FROM '.preflightIdentifier($table))->fetchColumn();
            if ($counts[$table] !== $manifest['queries'][$table]['rows']) {
                throw new RuntimeException('Import count mismatch.');
            }
            $primary = $indexGroups[$table]['PRIMARY'] ?? [];
            if ($primary !== []) {
                $facts[$table]['duplicate_primary_key_groups'] = preflightPrimaryKeyDuplicates($db, $table, $primary);
            }
        }
        $all = fn (string $sql) => $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $summary = ['export_utc' => gmdate('c'), 'export_helsinki' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki')))->format('c'),
            'counts' => $counts, 'graph_facts' => $facts,
            'active_audience_nationality' => $all('SELECT c.target_group,c.availability_is_national,COUNT(*) AS rows FROM active_contracts a JOIN electricity_contracts c ON c.id=a.id GROUP BY c.target_group,c.availability_is_national'),
            'interpretation_profiles_states' => $all('SELECT schema_version,prompt_version,validator_version,status,relational_pricing_published,COUNT(*) AS rows FROM contract_interpretations GROUP BY schema_version,prompt_version,validator_version,status,relational_pricing_published'),
            'active_publication_profiles_states' => $all('SELECT i.schema_version,i.prompt_version,i.validator_version,i.status,i.relational_pricing_published,COUNT(*) AS rows FROM active_contracts a JOIN electricity_contracts c ON c.id=a.id LEFT JOIN contract_interpretations i ON i.id=c.published_interpretation_id GROUP BY i.schema_version,i.prompt_version,i.validator_version,i.status,i.relational_pricing_published'),
            'source_freshness' => $all('SELECT MIN(first_observed_at) AS first_observed,MAX(last_observed_at) AS last_observed FROM contract_source_observations'),
            'active_source_freshness' => $all('SELECT MIN(o.last_observed_at) AS oldest_current,MAX(o.last_observed_at) AS newest_current,COUNT(o.id) AS pointed_rows FROM active_contracts a JOIN electricity_contracts c ON c.id=a.id LEFT JOIN contract_source_observations o ON o.id=c.current_source_observation_id'),
            'price_snapshot_dates' => $all('SELECT MIN(snapshot_date) AS first_date,MAX(snapshot_date) AS last_date FROM contract_price_snapshots'),
            'statistics_dates' => $all('SELECT segment_key,MIN(stat_date) AS first_date,MAX(stat_date) AS last_date,COUNT(*) AS rows FROM contract_price_daily_statistics GROUP BY segment_key'),
            'futures_freshness' => $all('SELECT MIN(trade_date) AS first_date,MAX(trade_date) AS last_date FROM electricity_futures_eod_prices'),
            'spot_freshness' => $all('SELECT period_type,MIN(period_start) AS first_date,MAX(period_end) AS last_date,COUNT(*) AS rows FROM spot_price_averages GROUP BY period_type'),
            'spot_hours_freshness' => $all('SELECT MIN(utc_datetime) AS first_utc,MAX(utc_datetime) AS last_utc,COUNT(*) AS rows FROM spot_prices_hour')];
        // Only this newly created private file is writable. Seal after statistics exist.
        $db->exec('ANALYZE');
        $manifest['sqlite_analyze'] = true;
        $manifest['sqlite_schema'] = $all('SELECT type,name,tbl_name,sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY type,name');
        $db->commit();
        if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('Integrity check.');
        }
        $manifest['integrity_check'] = 'ok';
        $manifest['scope_contract_count_verified'] = count($scope['scope']) === $counts['electricity_contracts'];
        if (! $manifest['scope_contract_count_verified']) {
            throw new RuntimeException('Scope mismatch.');
        }
        $db = null;
        $check();
        file_put_contents($directory.'/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        $manifest['files'] = [];
        foreach (['snapshot.sqlite', 'summary.json'] as $file) {
            $manifest['files'][$file] = ['bytes' => filesize($directory.'/'.$file), 'sha256' => hash_file('sha256', $directory.'/'.$file)];
        }
        $manifest['total_rows_read'] = $rowsTotal;
        $manifest['total_data_bytes'] = $bytesTotal;
        $manifest['script_sha256'] = hash_file('sha256', __FILE__);
        $manifest['status'] = 'complete';
    } catch (Throwable) {
        $manifest['status'] = 'failed';
        $manifest['failed_stage'] = $stage;
        fwrite(STDERR, 'Export failed at safe stage: '.$stage.". Details suppressed.\n");
    } finally {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
        if ($source !== null && $connection !== null) {
            $connection->rollBackReadOnlyTransaction($source);
        }
        $source = null;
        if ($db !== null && $db->inTransaction()) {
            $db->rollBack();
        }
        $db = null;
    }
    try {
        if ($directory !== null) {
            $manifest['completed_at_utc'] = gmdate('c');
            $manifest['elapsed_seconds'] = round(microtime(true) - $started, 3);
            file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        }
    } catch (Throwable) {
        fwrite(STDERR, "Export failed at safe stage: manifest. Details suppressed.\n");

        return 1;
    }

    return $manifest['status'] === 'complete' ? 0 : 1;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(preflightRun($argv));
}
