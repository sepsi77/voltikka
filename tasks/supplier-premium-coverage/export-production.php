<?php

declare(strict_types=1);

use App\Services\DevelopmentDatabase\ProductionMySqlConnection;
use App\Services\RetailPremium\RetailPremiumObservationService;
use App\Services\RetailPremium\RetailPremiumHistoryBackfillService;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_time_limit(240);
set_error_handler(static function (int $severity): bool {
    if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) return true;
    throw new RuntimeException('Export failed.');
});
$directory = __DIR__.'/export-'.gmdate('Ymd\THis\Z');
$source = $connection = null;
$stage = 'initialization';
$exit = 0;
$bytes = 0;
$manifest = [
    'status' => 'incomplete', 'started_at_utc' => gmdate('c'),
    'project_id' => '6d8cae01-1006-409f-8108-1d51f1abc676',
    'environment_id' => '9245cef8-41d0-486e-862f-193726511dba',
    'service_id' => 'beb2ba12-4a7b-416b-b4b1-596434dc3215',
    'transaction' => 'REPEATABLE READ; START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY; ROLLBACK',
    'bounds' => ['from' => '2026-04-08', 'to' => '2026-09-13', 'premiums' => 'observed period overlaps bounds', 'forecasts' => 'forecast_date within bounds'],
    'limits' => ['rows_per_query' => 100000, 'total_data_bytes' => 104857600, 'query_timeout_ms' => 20000, 'php_timeout_seconds' => 240],
    'json_columns' => 'Database JSON strings preserved in full.', 'queries' => [],
];
try {
    require dirname(__DIR__, 2).'/laravel/vendor/autoload.php';
    mkdir($directory, 0700);
    $connection = new ProductionMySqlConnection;
    $stage = 'connection';
    $source = $connection->connect();
    $connection->beginReadOnlyConsistentTransaction($source);
    $source->exec('SET SESSION MAX_EXECUTION_TIME=20000');
    $export = function (string $name, string $body, string $order = '') use (&$source, &$manifest, &$bytes, &$stage, $directory): array {
        $stage = $name.'_count';
        $countSql = 'SELECT /*+ MAX_EXECUTION_TIME(20000) */ COUNT(*) AS row_count FROM (SELECT 1 '.$body.' LIMIT 100001) AS bounded_rows';
        $statement = $source->query($countSql);
        $expected = (int) $statement->fetchColumn();
        $statement->closeCursor();
        if ($expected > 100000) throw new RuntimeException('Count exceeds limit.');
        $manifest['queries'][$name] = ['count_sql' => $countSql, 'count_sql_sha256' => hash('sha256', $countSql), 'expected_count' => $expected];
        $stage = $name;
        $select = '*';
        if ($name === 'snapshots') $select = 'id, snapshot_date, contract_id, company_name, contract_name, pricing_model, contract_type, fixed_time_range, metering, segment_key, energy_price_cents_per_kwh, monthly_fee_eur, has_discount, includes_spot_price, pricing_basis, created_at, updated_at';
        $sql = 'SELECT /*+ MAX_EXECUTION_TIME(20000) */ '.$select.' '.$body.' '.$order.' LIMIT 100001';
        $statement = $source->query($sql);
        $file = fopen($directory.'/'.$name.'.json', 'xb');
        $rows = [];
        $count = 0;
        try {
            fwrite($file, "[\n");
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                if (++$count > 100000) throw new RuntimeException('Row limit.');
                $json = ($count > 1 ? ",\n" : '').json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $bytes += strlen($json);
                if ($bytes + 5 * (count($manifest['queries']) + 1) > 104857600 || fwrite($file, $json) !== strlen($json)) throw new RuntimeException('Byte limit.');
                if (in_array($name, ['version_metadata', 'schemas'], true)) $rows[] = $row;
            }
            fwrite($file, "\n]\n");
        } finally {
            fclose($file);
            $statement->closeCursor();
        }
        if ($count !== $expected) throw new RuntimeException('Count mismatch.');
        $manifest['queries'][$name] += ['sql' => $sql, 'sql_sha256' => hash('sha256', $sql), 'row_count' => $count, 'file' => $name.'.json', 'bytes' => filesize($directory.'/'.$name.'.json'), 'sha256' => hash_file('sha256', $directory.'/'.$name.'.json')];
        echo $name.': '.$count." rows\n";
        return $rows;
    };
    $schemas = $export('schemas', "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('retail_premium_observations','contract_price_snapshots','contract_price_daily_statistics','fixed_contract_price_forecasts','electricity_futures_eod_prices')", 'ORDER BY TABLE_NAME, ORDINAL_POSITION');
    $versions = $export('version_metadata', "FROM (SELECT method_version, reference_kind, contract_type, quality, vat_basis, COUNT(*) AS observation_rows, MIN(first_observed_date) AS first_date, MAX(last_observed_date) AS last_date, SUM(JSON_EXTRACT(source_metadata, '$.duration_months') IS NOT NULL) AS duration_metadata_rows FROM retail_premium_observations WHERE first_observed_date <= '2026-09-13' AND last_observed_date >= '2026-04-08' GROUP BY method_version, reference_kind, contract_type, quality, vat_basis) AS metadata", 'ORDER BY method_version, reference_kind, contract_type, quality, vat_basis');
    $pair = [RetailPremiumObservationService::METHOD_VERSION, RetailPremiumHistoryBackfillService::METHOD_VERSION];
    foreach ($pair as &$version) {
        preg_match('/^(.*-v)(\d+)$/', $version, $floor);
        foreach ($versions as $row) {
            if (preg_match('/^'.preg_quote($floor[1], '/').'(\d+)$/', $row['method_version'], $match) && (int) $match[1] > (int) substr($version, strrpos($version, 'v') + 1)) $version = $row['method_version'];
        }
    }
    unset($version);
    $manifest['method_pair'] = $pair;
    $quoted = implode(',', array_map(fn ($v) => $source->quote($v), $pair));
    $export('futures', "FROM electricity_futures_eod_prices WHERE area='FI' AND product='Base' AND trade_date BETWEEN '2026-04-08' AND '2026-09-13'", 'ORDER BY trade_date, id');
    $export('statistics', "FROM contract_price_daily_statistics WHERE metric_key='energy_price' AND consumption_kwh IS NULL AND method_version='unit_statistics_v1' AND segment_key IN ('fixed_term_6','fixed_term_12','fixed_term_24') AND stat_date BETWEEN '2026-04-08' AND '2026-09-13'", 'ORDER BY stat_date, id');
    $export('forecasts', "FROM fixed_contract_price_forecasts WHERE forecast_date BETWEEN '2026-04-08' AND '2026-09-13'", 'ORDER BY forecast_date, id');
    $export('snapshots', "FROM contract_price_snapshots WHERE contract_type='FixedTerm' AND fixed_time_range IN ('Fixed6','Fixed12','Fixed24') AND snapshot_date BETWEEN '2026-04-08' AND '2026-09-13'", 'ORDER BY snapshot_date, id');
    $export('premiums', "FROM retail_premium_observations WHERE contract_type='FixedTerm' AND reference_kind='term_strip' AND method_version IN ($quoted) AND first_observed_date <= '2026-09-13' AND last_observed_date >= '2026-04-08'", 'ORDER BY company_name, lineage_key, first_observed_date, id');
    $manifest['status'] = 'complete';
} catch (Throwable) {
    $exit = 1;
    $manifest['status'] = 'failed';
    $manifest['failed_stage'] = $stage;
    fwrite(STDERR, 'Export failed at safe stage: '.$stage.". Details suppressed.\n");
} finally {
    if ($source !== null && $connection !== null) $connection->rollBackReadOnlyTransaction($source);
    $source = null;
}
try {
    $manifest['completed_at_utc'] = gmdate('c');
    $manifest['script_sha256'] = hash_file('sha256', __FILE__);
    file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    file_put_contents($directory.'/manifest.sha256', hash_file('sha256', $directory.'/manifest.json')."  manifest.json\n");
    echo 'Local export: '.$directory."\n";
} catch (Throwable) {
    fwrite(STDERR, "Manifest write failed. Details suppressed.\n");
    $exit = 1;
}
exit($exit);
