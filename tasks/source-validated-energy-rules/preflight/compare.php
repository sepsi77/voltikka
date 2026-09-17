<?php

declare(strict_types=1);
use App\Models\ElectricityContract;
use App\Providers\AppServiceProvider;
use App\Services\CalculatedCostPayloadSchema;
use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\CurrentSourcePromotionEvidence;
use App\Services\ContractPricing\ContractPricingViewData;
use Carbon\CarbonImmutable;
use Composer\Autoload\ClassLoader;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Monolog\Handler\NullHandler;
use Pdo\Sqlite;

// Local, read-only engine evaluation. This does not boot the application kernel.
const DEPLOYED_COMMIT = '917de212fdc4ef4862903268c3d33ba5cd886e73';

function requireThat(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

function privateJson(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $file = fopen($path, 'x');
    requireThat($file !== false, 'Output must be a new file.');
    try {
        requireThat(fwrite($file, $json."\n") === strlen($json) + 1, 'Incomplete output.');
    } finally {
        fclose($file);
    }
}

function validateTransport(array $payload, string $id, array &$validation): void
{
    $validation['checked_count']++;
    try {
        ContractPricingViewData::fromArray($payload);
        $validation['valid_count']++;
    } catch (Throwable $e) {
        $validation['error_count']++;
        if (count($validation['errors']) < 100) {
            // Never record exception messages, source values, or traces.
            $validation['errors'][] = ['id' => $id, 'reason' => 'read_model_rejected', 'class' => $e instanceof InvalidArgumentException ? 'InvalidArgumentException' : 'Throwable'];
        }
    }
}

function emptyTransportValidation(): array
{
    return ['checked_count' => 0, 'valid_count' => 0, 'error_count' => 0, 'errors' => [], 'error_detail_limit' => 100];
}

function withoutProse(array $data): array
{
    foreach ($data as $key => $value) {
        if (is_string($key) && preg_match('/quote|summary|label|description|source_text/i', $key)) {
            unset($data[$key]);
        } elseif (is_array($value)) {
            $data[$key] = withoutProse($value);
        }
    }

    return $data;
}

// Strip literals as well as bindings: whereIntegerInRaw can embed identifiers.
function queryShape(string $sql): string
{
    $sql = preg_replace_callback("~'(?:''|[^'])*'|/\\*.*?\\*/|--[^\\r\\n]*~s", static fn ($match) => str_starts_with($match[0], "'") ? '?' : '', $sql);
    $sql = preg_replace('/\b[0-9]+(?:\.[0-9]+)?\b/', '?', $sql);

    return substr($sql, 0, 65536);
}

function verifyExportSnapshot(string $database): array
{
    $manifestPath = dirname($database).'/manifest.json';
    requireThat(is_file($manifestPath), 'Export manifest is required.');
    $manifestBytes = file_get_contents($manifestPath);
    $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
    requireThat(($manifest['status'] ?? null) === 'complete', 'Export is not complete.');
    requireThat(($manifest['integrity_check'] ?? null) === 'ok', 'Export integrity proof is missing.');
    requireThat(($manifest['scope_contract_count_verified'] ?? null) === true, 'Export scope count is not verified.');
    $hash = hash_file('sha256', $database);
    requireThat(($manifest['files']['snapshot.sqlite']['sha256'] ?? null) === $hash, 'Export snapshot hash mismatch.');
    $expectedRaw = $manifest['queries']['active_contracts']['rows'] ?? null;
    requireThat(is_int($expectedRaw) && $expectedRaw >= 0, 'Export active row count is missing or invalid.');
    $openFlags = PHP_VERSION_ID >= 80400 ? Sqlite::ATTR_OPEN_FLAGS : PDO::SQLITE_ATTR_OPEN_FLAGS;
    $readOnly = PHP_VERSION_ID >= 80400 ? Sqlite::OPEN_READONLY : PDO::SQLITE_OPEN_READONLY;
    $pdo = new PDO('sqlite:'.$database, null, null, [$openFlags => $readOnly]);
    $pdo->exec('PRAGMA query_only=ON');
    $raw = (int) $pdo->query('SELECT COUNT(*) FROM active_contracts')->fetchColumn();
    requireThat($raw === $expectedRaw, 'Export active row count mismatch.');
    $joined = (int) $pdo->query('SELECT COUNT(*) FROM electricity_contracts c WHERE EXISTS (SELECT 1 FROM active_contracts a WHERE a.id=c.id)')->fetchColumn();
    $orphans = (int) $pdo->query('SELECT COUNT(*) FROM active_contracts a WHERE NOT EXISTS (SELECT 1 FROM electricity_contracts c WHERE c.id=a.id)')->fetchColumn();

    // Summary values and dates are not used: counts come from the hash-verified snapshot.
    return ['manifest_sha256' => hash('sha256', $manifestBytes), 'snapshot_sha256' => $hash,
        'raw_active_count' => $raw, 'expected_joined_active_count' => $joined, 'orphan_active_count' => $orphans];
}

function evaluateTree(array $options, ?callable $queryDiagnostic = null): void
{
    umask(0077);
    $started = hrtime(true);
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    $appPath = realpath($options['app'] ?? '');
    $database = realpath($options['database'] ?? '');
    $output = $options['output'] ?? '';
    $date = $options['as-of'] ?? '';
    requireThat($appPath !== false && is_file($appPath.'/vendor/autoload.php'), 'Invalid Laravel tree.');
    requireThat($database !== false && is_file($database) && basename($database) === 'snapshot.sqlite', 'Use the fresh isolated snapshot.sqlite.');
    requireThat(! file_exists($output) && ! is_link($output) && is_dir(dirname($output)), 'Output must be new.');
    requireThat((fileperms(dirname($output)) & 0077) === 0, 'Output directory must be private (0700).');
    requireThat((fileperms($database) & 0077) === 0, 'Snapshot must be private (0600).');
    requireThat((fileperms(dirname($database)) & 0077) === 0, 'Snapshot directory must be private (0700).');
    foreach (['-wal', '-shm', '-journal'] as $suffix) {
        requireThat(! file_exists($database.$suffix), 'Snapshot must be closed and have no SQLite sidecars.');
    }
    foreach ([$appPath.'/database/database.sqlite', dirname(__DIR__, 3).'/laravel/database/database.sqlite'] as $active) {
        if (is_file($active)) {
            $a = stat($active);
            $b = stat($database);
            requireThat($a['dev'] !== $b['dev'] || $a['ino'] !== $b['ino'], 'Active local database is forbidden.');
        }
    }
    $exportVerification = verifyExportSnapshot($database);
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    requireThat($parsedDate !== false && $parsedDate->format('Y-m-d') === $date, 'Invalid as-of date.');
    $flagsPath = $options['flags'] ?? '';
    $flags = json_decode(file_get_contents($flagsPath), true, 512, JSON_THROW_ON_ERROR);
    foreach (['checked_at_utc', 'app_timezone', 'canonical_enabled', 'reset_enabled', 'beta', 'seasonal_index', 'annual_method', 'schema', 'prompt', 'validator', 'max_curve_age_days', 'absurdity_band', 'vat_multiplier', 'area'] as $key) {
        requireThat(array_key_exists($key, $flags), 'Missing verified flag: '.$key);
    }
    requireThat(is_bool($flags['canonical_enabled']) && is_bool($flags['reset_enabled']) && $flags['canonical_enabled'], 'Canonical production flags must be typed booleans and enabled.');
    requireThat($flags['schema'] === 'schema-v4' && $flags['prompt'] === 'prompt-v19' && $flags['validator'] === 'validator-v17', 'This run is limited to the default-V4 release.');

    // Remove all inherited credentials before loading Composer or any Laravel file.
    foreach (array_keys(getenv()) as $key) {
        putenv($key);
    }
    $_ENV = [];
    $_SERVER = [];
    $temp = sys_get_temp_dir().'/voltikka-compare-'.bin2hex(random_bytes(10));
    requireThat(mkdir($temp, 0700), 'Cannot create private temporary directory.');
    foreach (['APP_CONFIG_CACHE' => $temp.'/absent-config.php', 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SENTRY_DSN' => '', 'SENTRY_LARAVEL_DSN' => ''] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
    }

    // This loader precedes Composer's optimized class map, including shared-vendor maps.
    // Missing selected-tree classes throw: they can NEVER fall through to current App code.
    spl_autoload_register(static function (string $class) use ($appPath): void {
        foreach (['App\\' => '/app/', 'Database\\Factories\\' => '/database/factories/', 'Database\\Seeders\\' => '/database/seeders/', 'Database\\' => '/database/'] as $prefix => $directory) {
            if (str_starts_with($class, $prefix)) {
                $file = $appPath.$directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                requireThat(is_file($file) && str_starts_with(realpath($file), $appPath.'/'), 'Selected-tree class is absent or escapes tree: '.$class);
                require $file;

                return;
            }
        }
    }, true, true);
    // Do not execute vendor/autoload.php: it prepends the old optimized map before
    // eager helper files run. Build a vendor-only loader, behind the namespace guard.
    $vendor = realpath($appPath.'/vendor');
    require_once $vendor.'/composer/ClassLoader.php';
    $loader = new ClassLoader($vendor);
    $applicationNamespace = static fn (string $name): bool => str_starts_with($name, 'App\\') || str_starts_with($name, 'Database\\') || str_starts_with($name, 'Tests\\');
    foreach (require $vendor.'/composer/autoload_psr4.php' as $prefix => $paths) {
        if ($applicationNamespace($prefix)) {
            continue;
        }
        $paths = array_values(array_filter($paths, 'is_dir'));
        foreach ($paths as $path) {
            requireThat(str_starts_with((string) realpath($path), $vendor.'/'), 'Non-vendor PSR-4 helper needs review.');
        }
        $loader->setPsr4($prefix, $paths);
    }
    foreach (require $vendor.'/composer/autoload_namespaces.php' as $prefix => $paths) {
        $paths = array_values(array_filter($paths, 'is_dir'));
        foreach ($paths as $path) {
            requireThat(str_starts_with((string) realpath($path), $vendor.'/'), 'Non-vendor PSR-0 helper needs review.');
        }
        $loader->set($prefix, $paths);
    }
    foreach (require $vendor.'/composer/autoload_classmap.php' as $class => $file) {
        if ($applicationNamespace($class)) {
            continue;
        }
        requireThat(str_starts_with((string) realpath($file), $vendor.'/'), 'Non-vendor class map helper needs review.');
        $loader->addClassMap([$class => $file]);
    }
    $loader->register(false);
    foreach (require $vendor.'/composer/autoload_files.php' as $id => $file) {
        requireThat(str_starts_with((string) realpath($file), $vendor.'/'), 'Non-vendor eager helper needs review.');
        if (empty($GLOBALS['__composer_autoload_files'][$id])) {
            $GLOBALS['__composer_autoload_files'][$id] = true;
            require $file;
        }
    }
    foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $class) {
        if (str_starts_with($class, 'App\\') || str_starts_with($class, 'Database\\')) {
            requireThat(str_starts_with((string) (new ReflectionClass($class))->getFileName(), $appPath.'/'), 'Composer loaded a foreign application class.');
        }
    }
    $app = new Application($appPath);
    $app->useEnvironmentPath($temp)->loadEnvironmentFrom('absent.env');
    $app->useStoragePath($temp);
    $config = new Repository;
    $app->instance('config', $config);
    Facade::setFacadeApplication($app);
    foreach (['canonical_pricing', 'price_forecasting', 'contract_interpretation', 'contract_statistics'] as $name) {
        requireThat(is_file($appPath.'/config/'.$name.'.php'), 'Missing selected-tree config: '.$name);
        $config->set($name, require $appPath.'/config/'.$name.'.php');
    }
    $config->set([
        'app.env' => 'testing', 'app.timezone' => $flags['app_timezone'],
        'canonical_pricing.enabled' => $flags['canonical_enabled'],
        'canonical_pricing.reset_forward_shift.enabled' => $flags['reset_enabled'],
        'canonical_pricing.reset_forward_shift.beta' => $flags['beta'],
        'canonical_pricing.reset_forward_shift.seasonal_index' => $flags['seasonal_index'],
        'canonical_pricing.reset_forward_shift.max_curve_age_days' => $flags['max_curve_age_days'],
        'canonical_pricing.reset_forward_shift.absurdity_band' => $flags['absurdity_band'],
        'price_forecasting.fixed_term.vat_multiplier' => $flags['vat_multiplier'],
        'price_forecasting.fixed_term.area' => $flags['area'],
        'contract_statistics.annual_cost.active_method_version' => $flags['annual_method'],
        'cache.default' => 'array', 'cache.stores.array' => ['driver' => 'array', 'serialize' => false],
        'logging.default' => 'null', 'logging.channels.null' => ['driver' => 'monolog', 'handler' => NullHandler::class],
        'queue.default' => 'forbidden', 'sentry.dsn' => null,
        'database.default' => 'sqlite',
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true, 'options' => [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]],
    ]);
    foreach (['schema' => 'schema_version', 'prompt' => 'prompt_version', 'validator' => 'validator_version'] as $flag => $key) {
        requireThat($config->get('contract_interpretation.'.$key) === $flags[$flag], 'Selected-tree default profile differs from production.');
    }
    date_default_timezone_set($flags['app_timezone']);
    $asOf = CarbonImmutable::parse($date, 'Europe/Helsinki')->startOfDay();
    // Noon keeps the as-of calendar date equal in Helsinki and production UTC.
    $clock = $asOf->setTime(12, 0);
    Carbon\Carbon::setTestNow($clock);
    CarbonImmutable::setTestNow($clock);
    Illuminate\Support\Carbon::setTestNow($clock);
    $app->register(DatabaseServiceProvider::class);
    $app->register(CacheServiceProvider::class);
    $app->make('db')->setDefaultConnection('sqlite');
    Model::setConnectionResolver($app->make('db'));
    Model::setEventDispatcher($app->make('events'));
    $http = new Factory;
    $http->preventStrayRequests();
    $app->instance(Factory::class, $http);
    $deny = static function () {
        throw new RuntimeException('Dispatch is forbidden in preflight.');
    };
    foreach (['queue', 'queue.connection', Dispatcher::class, QueueingDispatcher::class] as $binding) {
        $app->bind($binding, $deny);
    }
    // Use the selected release's actual bindings, but NEVER boot its provider/kernel/routes.
    (new AppServiceProvider($app))->register();
    $reflections = [];
    foreach ([CanonicalContractPriceCalculator::class, CanonicalContractPricingService::class, ContractPricingViewData::class, CalculatedCostPayloadSchema::class, ElectricityContract::class] as $class) {
        $file = realpath((new ReflectionClass($class))->getFileName());
        requireThat(str_starts_with($file, $appPath.'/'), 'Reflection failed selected-tree proof.');
        $reflections[$class] = ['file' => $file, 'sha256' => hash_file('sha256', $file)];
    }
    $connection = $app->make('db')->connection();
    $pdo = $connection->getPdo();
    requireThat($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'Not SQLite.');
    $files = $pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
    requireThat(count($files) === 1 && $files[0]['name'] === 'main' && realpath($files[0]['file']) === $database, 'Wrong SQLite mainfile.');
    // Independently prove READONLY open mode before enabling the second query_only guard.
    try {
        $pdo->exec('UPDATE electricity_contracts SET id = id WHERE 0');
        throw new RuntimeException('SQLite READONLY open mode was not enforced.');
    } catch (PDOException $e) {
        requireThat(($e->errorInfo[1] ?? null) === 8, 'Unexpected read-only probe failure.');
    }
    $pdo->exec('PRAGMA query_only=ON');
    requireThat((int) $pdo->query('PRAGMA query_only')->fetchColumn() === 1, 'query_only was not enabled.');
    $hash = hash_file('sha256', $database);
    requireThat($hash === $exportVerification['snapshot_sha256'], 'Snapshot changed after manifest verification.');
    $required = ['electricity_contracts', 'active_contracts', 'contract_source_observations', 'contract_source_snapshots', 'contract_interpretations', 'contract_price_snapshots', 'price_components', 'electricity_futures_eod_prices', 'spot_price_averages', 'contract_price_daily_statistics'];
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    requireThat(array_diff($required, $tables) === [], 'Missing input tables: '.implode(', ', array_diff($required, $tables)));
    $queryCount = 0;
    $queryMs = 0.0;
    $queryProfiles = [];
    $connection->listen(static function ($query) use (&$queryCount, &$queryMs, &$queryProfiles, $pdo, $queryDiagnostic): void {
        $queryCount++;
        $queryMs += $query->time;
        requireThat($queryCount <= 256, 'Query profile limit exceeded.');
        $profile = ['sequence' => $queryCount, 'ms' => $query->time, 'shape' => queryShape($query->sql)];
        if ($query->time >= 100) {
            $explain = $pdo->prepare('EXPLAIN QUERY PLAN '.$query->sql);
            $explain->execute($query->connection->prepareBindings($query->bindings));
            $plan = $explain->fetchAll(PDO::FETCH_ASSOC);
            requireThat(count($plan) <= 512, 'Query plan limit exceeded.');
            $profile['plan'] = array_map(static fn ($row) => ['id' => $row['id'], 'parent' => $row['parent'], 'detail' => queryShape($row['detail'])], $plan);
            // Optional private diagnostic hook. Bindings exist only in this process.
            if ($queryDiagnostic !== null) {
                $profile['diagnostic'] = $queryDiagnostic($query);
            }
        }
        $queryProfiles[] = $profile;
    });
    $measure = static function (callable $work) use (&$queryCount, &$queryMs, &$queryProfiles): array {
        $q = $queryCount;
        $ms = $queryMs;
        $time = hrtime(true);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $value = $work();

        return [$value, ['queries' => array_slice($queryProfiles, $q), 'sql_count' => $queryCount - $q, 'sql_ms' => $queryMs - $ms, 'wall_ms' => (hrtime(true) - $time) / 1e6, 'peak_memory_bytes' => memory_get_peak_usage(true)]];
    };
    [$contracts, $loadMetrics] = $measure(fn () => ElectricityContract::query()->active()->orderBy('id')->get());
    requireThat($contracts->count() === $exportVerification['expected_joined_active_count'], 'Loaded active contract count mismatch.');
    requireThat($contracts->isNotEmpty(), 'No active contracts: refuse empty evidence.');
    $service = $app->make(CanonicalContractPricingService::class);
    $phases = [];
    foreach (['5000_cold' => 5000, '5000_warm' => 5000, '2000' => 2000, '18000' => 18000] as $name => $kwh) {
        [$outcomes, $metrics] = $measure(fn () => $service->outcomesForContractsAtConsumptions($contracts, [$kwh], $service->spotAssumptions(), $asOf));
        $rows = [];
        $transportValidation = emptyTransportValidation();
        foreach ($contracts as $contract) {
            requireThat(isset($outcomes[$contract->id][$kwh]), 'Missing contract outcome.');
            $outcome = $outcomes[$contract->id][$kwh];
            $rawCost = $outcome->toCalculatedCostArray();
            validateTransport($rawCost, (string) $contract->id, $transportValidation);
            $rows[$contract->id] = ['listed' => $outcome->isListed(), 'cost' => withoutProse($rawCost)];
        }
        $eligible = array_filter($rows, fn ($row, $id) => $row['listed'] && is_numeric($row['cost']['total_cost']) && $contracts->firstWhere('id', $id)->availability_is_national === true && in_array($contracts->firstWhere('id', $id)->target_group, ['Household', 'Both', null], true), ARRAY_FILTER_USE_BOTH);
        uksort($eligible, fn ($a, $b) => ($rows[$a]['cost']['total_cost'] <=> $rows[$b]['cost']['total_cost']) ?: strcmp((string) $a, (string) $b));
        $phases[$name] = ['kwh' => $kwh, 'metrics' => $metrics, 'transport_validation' => $transportValidation, 'rows' => $rows, 'recomputed_engine_order' => array_keys($eligible)];
        unset($outcomes);
    }
    [$audit, $auditMetrics] = $measure(function () use ($connection, $contracts, $asOf, $appPath) {
        // This staged helper does not exist in the deployed release. Never import it there.
        $hasSourceProof = is_file($appPath.'/app/Services/CanonicalPricing/CurrentSourcePromotionEvidence.php');
        $sourceProof = $hasSourceProof ? (new CurrentSourcePromotionEvidence)->forContracts($contracts, $asOf) : [];
        $profiles = $connection->table('contract_interpretations')->selectRaw('schema_version, prompt_version, validator_version, status, COUNT(*) AS count')->groupBy('schema_version', 'prompt_version', 'validator_version', 'status')->get()->all();
        $published = $connection->table('contract_interpretations')->whereIn('id', $contracts->pluck('published_interpretation_id')->filter())->get(['id', 'schema_version', 'prompt_version', 'validator_version', 'status'])->keyBy('id');
        $metadata = [];
        foreach ($contracts as $c) {
            $metadata[$c->id] = ['name' => $c->name, 'company' => $c->company_name, 'audience' => $c->target_group, 'national' => $c->availability_is_national, 'source_observation_id' => $c->current_source_observation_id, 'published_interpretation_id' => $c->published_interpretation_id, 'profile' => $published[$c->published_interpretation_id] ?? null, 'source_proof' => $sourceProof[$c->id] ?? null, 'source_proof_helper_available' => $hasSourceProof, 'stored_calculation_status' => $c->canonical_calculation['status'] ?? null, 'stored_issue_codes' => $c->canonical_source_consistency['issue_codes'] ?? []];
        }

        return ['stored_profiles_export_scope' => $profiles, 'contracts' => $metadata, 'whole_source_grammar' => 'Not evaluated: no V5 interpretation was constructed. Current V4 facts do not measure V5 producer accuracy or impact.'];
    });
    requireThat(hash_file('sha256', $database) === $hash, 'Snapshot changed during evaluation.');
    foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $class) {
        if (str_starts_with($class, 'App\\') || str_starts_with($class, 'Database\\')) {
            requireThat(str_starts_with((string) (new ReflectionClass($class))->getFileName(), $appPath.'/'), 'Foreign application class after evaluation.');
        }
    }
    $loadedHashes = [];
    foreach (get_included_files() as $file) {
        if (str_starts_with($file, $appPath.'/app/') || str_starts_with($file, $appPath.'/config/')) {
            $loadedHashes[substr($file, strlen($appPath) + 1)] = hash_file('sha256', $file);
        }
    }
    ksort($loadedHashes);
    privateJson($output, ['profiled_at_utc' => gmdate('c'), 'loaded_code_sha256' => $loadedHashes, 'runner_sha256' => hash_file('sha256', __FILE__), 'format' => 1, 'baseline_commit_required' => DEPLOYED_COMMIT, 'app' => $appPath, 'as_of' => $date, 'database_sha256' => $hash, 'export_verification' => $exportVerification, 'flags_sha256' => hash_file('sha256', $flagsPath), 'reflections' => $reflections, 'payload_schema' => CalculatedCostPayloadSchema::VERSION, 'effective_pricing_config' => $config->get('canonical_pricing'), 'effective_market_config' => ['vat_multiplier' => $flags['vat_multiplier'], 'area' => $flags['area']], 'flags' => array_intersect_key($flags, array_flip(['checked_at_utc', 'app_timezone', 'canonical_enabled', 'reset_enabled', 'beta', 'seasonal_index', 'annual_method', 'schema', 'prompt', 'validator'])), 'read_only' => ['driver' => 'sqlite', 'mainfile' => $database, 'pdo_readonly_probe' => true, 'query_only' => true], 'load_metrics' => $loadMetrics, 'audit_metrics' => $auditMetrics, 'audit' => $audit, 'phases' => $phases, 'wall_ms' => (hrtime(true) - $started) / 1e6, 'limits' => ['RECOMPUTED engine order, not observed live cached ranking. Public cache and listing guards can differ.', 'Staged-code recalculation only. V5 re-analysis impact and producer accuracy remain unknown.', 'Non-exported runtime/auth/cache inputs are not used. No app kernel or provider boot.']]);
    echo "Private result written.\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        evaluateTree(getopt('', ['app:', 'database:', 'output:', 'as-of:', 'flags:']));
    } catch (Throwable $e) {
        // No SQL bindings, source text, stack traces, or connection credentials on stdout.
        fwrite(STDERR, 'Preflight stopped: '.$e::class.'. '.(get_class($e) === RuntimeException::class ? $e->getMessage() : 'No exception details are printed.')." No result is valid.\n");
        exit(1);
    }
}
