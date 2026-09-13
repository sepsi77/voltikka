<?php

declare(strict_types=1);

use App\Services\DevelopmentDatabase\ProductionMySqlConnection;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (int $severity): bool {
    if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        return true;
    }
    throw new RuntimeException('Export operation failed.');
});

$source = null;
$connection = null;
$stage = 'initialization';
$exitCode = 0;
$started = gmdate('Y-m-d\TH:i:s\Z');
$directory = __DIR__.'/export-'.gmdate('Ymd\THis\Z');
$manifest = [
    'status' => 'incomplete',
    'started_at_utc' => $started,
    'project_id' => '6d8cae01-1006-409f-8108-1d51f1abc676',
    'environment_id' => '9245cef8-41d0-486e-862f-193726511dba',
    'service_id' => 'beb2ba12-4a7b-416b-b4b1-596434dc3215',
    'transaction' => 'REPEATABLE READ; START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY; ROLLBACK',
    'limits' => ['rows_per_query' => 20000, 'bytes_total' => 104857600, 'query_timeout_ms' => 20000],
    'json_columns' => 'Preserved as database JSON strings, without decoding or removing fields.',
    'queries' => [],
];
$bytes = 0;

try {
    require dirname(__DIR__, 2).'/laravel/vendor/autoload.php';
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Local directory creation failed.');
    }
    $connection = new ProductionMySqlConnection;
    $stage = 'connection';
    $source = $connection->connect();
    $stage = 'read_only_transaction';
    $connection->beginReadOnlyConsistentTransaction($source);

    $queries = [
        'database_clock' => 'SELECT /*+ MAX_EXECUTION_TIME(20000) */ CURRENT_DATE AS `current_date`, CURRENT_TIMESTAMP AS `current_timestamp`, UTC_TIMESTAMP() AS `utc_timestamp`, @@session.time_zone AS session_time_zone, @@session.transaction_isolation AS transaction_isolation',
        'forecast_table_bounds' => 'SELECT /*+ MAX_EXECUTION_TIME(20000) */ COUNT(*) AS row_count, MIN(forecast_date) AS min_forecast_date, MAX(forecast_date) AS max_forecast_date FROM fixed_contract_price_forecasts',
        'schemas' => "SELECT /*+ MAX_EXECUTION_TIME(20000) */ TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('fixed_contract_price_forecasts', 'electricity_futures_eod_prices', 'contract_price_daily_statistics') ORDER BY TABLE_NAME, ORDINAL_POSITION LIMIT 20001",
        'forecasts' => "SELECT /*+ MAX_EXECUTION_TIME(20000) */ * FROM fixed_contract_price_forecasts WHERE forecast_date BETWEEN '2026-06-13' AND '2026-09-13' ORDER BY forecast_date, id LIMIT 20001",
        'futures' => "SELECT /*+ MAX_EXECUTION_TIME(20000) */ * FROM electricity_futures_eod_prices WHERE area = 'FI' AND product = 'Base' AND trade_date BETWEEN '2026-06-01' AND '2026-09-13' ORDER BY trade_date, id LIMIT 20001",
        'statistics' => "SELECT /*+ MAX_EXECUTION_TIME(20000) */ * FROM contract_price_daily_statistics WHERE metric_key = 'energy_price' AND segment_key IN ('fixed_term_6', 'fixed_term_12', 'fixed_term_24') AND stat_date BETWEEN '2026-06-01' AND '2026-09-13' ORDER BY stat_date, id LIMIT 20001",
    ];

    foreach ($queries as $name => $sql) {
        $stage = $name;
        $queryStarted = gmdate('Y-m-d\TH:i:s\Z');
        $statement = $source->query($sql);
        $path = $directory.'/'.$name.'.json';
        $file = fopen($path, 'xb');
        $count = 0;
        try {
            fwrite($file, "[\n");
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                if (++$count > 20000) {
                    throw new RuntimeException('Row limit exceeded.');
                }
                $json = ($count > 1 ? ",\n" : '').json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $bytes += strlen($json);
                if ($bytes > 104857600 || fwrite($file, $json) !== strlen($json)) {
                    throw new RuntimeException('Export size or write limit failed.');
                }
            }
            fwrite($file, "\n]\n");
        } finally {
            fclose($file);
            $statement->closeCursor();
        }
        $manifest['queries'][$name] = [
            'sql' => $sql,
            'started_at_utc' => $queryStarted,
            'completed_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'file' => basename($path),
            'row_count' => $count,
            'bytes' => filesize($path),
            'sha256' => hash_file('sha256', $path),
        ];
        echo $name.': '.$count." rows\n";
    }
    $manifest['status'] = 'complete';
} catch (Throwable) {
    $exitCode = 1;
    $manifest['status'] = 'failed';
    $manifest['failed_stage'] = $stage;
    fwrite(STDERR, 'Export failed at safe stage: '.$stage.". Exception details suppressed.\n");
} finally {
    if ($source !== null && $connection !== null) {
        $connection->rollBackReadOnlyTransaction($source);
        $source = null;
    }
}

try {
    $manifest['completed_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
    $manifest['script_sha256'] = hash_file('sha256', __FILE__);
    if (is_dir($directory)) {
        $manifestPath = $directory.'/manifest.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents($directory.'/manifest.sha256', hash_file('sha256', $manifestPath)."  manifest.json\n");
        echo 'Local export: '.$directory."\n";
    }
} catch (Throwable) {
    fwrite(STDERR, "Local manifest write failed. Exception details suppressed.\n");
    $exitCode = 1;
}

exit($exitCode);
