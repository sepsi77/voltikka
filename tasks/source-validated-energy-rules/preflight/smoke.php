<?php

declare(strict_types=1);
use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Bus\QueueingDispatcher;

// Synthetic-only SQLite fixture. Never reads the active database or an export.
require __DIR__.'/compare.php';

umask(0077);
$root = sys_get_temp_dir().'/voltikka-compare-smoke-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$app = realpath(__DIR__.'/../../../laravel');
$pdo = new PDO('sqlite:'.$root.'/snapshot.sqlite');
$pdo->exec('CREATE TABLE electricity_contracts (id TEXT PRIMARY KEY, name TEXT, company_name TEXT, target_group TEXT, availability_is_national INTEGER, pricing_model TEXT, contract_type TEXT, metering TEXT, fixed_time_range TEXT, canonical_pricing JSON, canonical_calculation JSON, canonical_source_consistency JSON, published_interpretation_id INTEGER, current_source_observation_id INTEGER)');
$pdo->exec('CREATE TABLE active_contracts (id TEXT PRIMARY KEY)');
$pdo->exec('CREATE TABLE spot_price_averages (id INTEGER PRIMARY KEY, region TEXT, period_type TEXT, period_end TEXT)');
$pdo->exec('CREATE TABLE contract_interpretations (id INTEGER PRIMARY KEY, schema_version TEXT, prompt_version TEXT, validator_version TEXT, status TEXT)');
// These tables are intentionally unused by this exact fixed-price fixture, not substitutes for exported inputs.
foreach (['contract_source_observations', 'contract_source_snapshots', 'contract_price_snapshots', 'price_components', 'electricity_futures_eod_prices', 'contract_price_daily_statistics'] as $table) {
    $pdo->exec('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY)');
}
$pricing = ['phases' => [['label' => 'PRIVATE SOURCE MUST NOT APPEAR', 'phase_kind' => 'current_structured', 'starts' => ['kind' => 'contract_start'], 'ends' => ['kind' => 'after_months', 'value' => '12'], 'components' => [['component_type' => 'energy_general', 'unit' => 'cents_per_kwh', 'price_role' => 'current', 'amount' => 10, 'normal_amount' => null], ['component_type' => 'monthly_fee', 'unit' => 'eur_per_month', 'price_role' => 'current', 'amount' => 5, 'normal_amount' => null]]]]];
$insert = $pdo->prepare('INSERT INTO electricity_contracts (id,name,company_name,target_group,availability_is_national,pricing_model,contract_type,metering,fixed_time_range,canonical_pricing,canonical_calculation) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$insert->execute(['fixed', 'Synthetic fixed', 'Fixture', 'Household', 1, 'FixedPrice', 'FixedTerm', 'General', 'Fixed12', json_encode($pricing), '{"status":"exact"}']);
$insert->execute(['missing', 'Synthetic missing', 'Fixture', 'Household', 1, 'FixedPrice', 'FixedTerm', 'General', 'Fixed12', null, null]);
$pdo->exec("INSERT INTO active_contracts VALUES ('fixed'), ('missing'), ('orphan')");
$pdo = null;
$flags = ['checked_at_utc' => 'synthetic-only', 'app_timezone' => 'UTC', 'canonical_enabled' => true, 'reset_enabled' => true, 'beta' => 1, 'max_curve_age_days' => 14, 'absurdity_band' => ['floor_cents_per_kwh' => 0, 'ceiling_cents_per_kwh' => 60], 'vat_multiplier' => 1.255, 'area' => 'FI', 'seasonal_index' => ['enabled' => true, 'lookback_years' => 4, 'min_years_per_month' => 2], 'annual_method' => 'annual_cost_as_of_v2', 'schema' => 'schema-v4', 'prompt' => 'prompt-v19', 'validator' => 'validator-v17'];
privateJson($root.'/flags.json', $flags);
$manifest = ['status' => 'complete', 'integrity_check' => 'ok', 'scope_contract_count_verified' => true,
    'files' => ['snapshot.sqlite' => ['sha256' => hash_file('sha256', $root.'/snapshot.sqlite')]],
    'queries' => ['active_contracts' => ['rows' => 3]]];
privateJson($root.'/manifest.json', $manifest);
try {
    foreach (['missing_manifest', 'failed_status', 'integrity', 'scope', 'hash', 'row_count'] as $case) {
        $directory = $root.'/'.$case;
        mkdir($directory, 0700);
        copy($root.'/snapshot.sqlite', $directory.'/snapshot.sqlite');
        $invalid = $manifest;
        match ($case) {
            'failed_status' => $invalid['status'] = 'failed',
            'integrity' => $invalid['integrity_check'] = 'failed',
            'scope' => $invalid['scope_contract_count_verified'] = false,
            'hash' => $invalid['files']['snapshot.sqlite']['sha256'] = str_repeat('0', 64),
            'row_count' => $invalid['queries']['active_contracts']['rows'] = 4,
            default => null,
        };
        if ($case !== 'missing_manifest') {
            privateJson($directory.'/manifest.json', $invalid);
        }
        $rejected = false;
        try {
            verifyExportSnapshot($directory.'/snapshot.sqlite');
        } catch (RuntimeException $e) {
            $rejected = true;
        }
        requireThat($rejected, 'Invalid export was accepted: '.$case);
    }
    evaluateTree(['app' => $app, 'database' => $root.'/snapshot.sqlite', 'output' => $root.'/result.json', 'flags' => $root.'/flags.json', 'as-of' => '2026-09-16']);
    $result = json_decode(file_get_contents($root.'/result.json'), true, 512, JSON_THROW_ON_ERROR);
    requireThat($result['export_verification']['raw_active_count'] === 3, 'Raw active count mismatch.');
    requireThat($result['export_verification']['expected_joined_active_count'] === 2, 'Joined active count mismatch.');
    requireThat($result['export_verification']['orphan_active_count'] === 1, 'Orphan active count mismatch.');
    foreach (['5000_cold' => 560, '5000_warm' => 560, '2000' => 260, '18000' => 1860] as $phase => $total) {
        requireThat(abs($result['phases'][$phase]['rows']['fixed']['cost']['total_cost'] - $total) < 0.00001, 'Fixture total mismatch: '.$phase);
        requireThat(! $result['phases'][$phase]['rows']['missing']['listed'], 'Missing fixture must be excluded.');
    }
    requireThat(! str_contains(file_get_contents($root.'/result.json'), 'PRIVATE SOURCE'), 'Raw source leaked.');
    requireThat(queryShape("select * from t where id in (123, 456) and text = 'PRIVATE SOURCE' and x = ?") === 'select * from t where id in (?, ?) and text = ? and x = ?', 'SQL literal leaked.');
    requireThat(queryShape("select 'PRIVATE -- SOURCE', 'PRIVATE /* SOURCE */' -- PRIVATE SOURCE") === 'select ?, ? ', 'SQL comment marker inside literal leaked.');
    foreach (array_merge([$result['load_metrics'], $result['audit_metrics']], array_column($result['phases'], 'metrics')) as $metrics) {
        requireThat(count($metrics['queries']) === $metrics['sql_count'], 'Query profile coverage mismatch.');
        requireThat(abs(array_sum(array_column($metrics['queries'], 'ms')) - $metrics['sql_ms']) < 0.001, 'Query profile timing mismatch.');
        foreach ($metrics['queries'] as $query) {
            requireThat(! isset($query['bindings']) && strlen($query['shape']) <= 65536, 'Unsafe query profile.');
        }
    }
    foreach (['queue', Dispatcher::class, QueueingDispatcher::class] as $binding) {
        $blocked = false;
        try {
            app($binding);
        } catch (RuntimeException $e) {
            $blocked = $e->getMessage() === 'Dispatch is forbidden in preflight.';
        }
        requireThat($blocked, 'Dispatch binding was not blocked.');
    }
    foreach (ClassLoader::getRegisteredLoaders() as $loader) {
        foreach (array_keys($loader->getClassMap()) as $class) {
            requireThat(! str_starts_with($class, 'App\\') && ! str_starts_with($class, 'Database\\'), 'Application class remains in vendor class map.');
        }
    }
    require __DIR__.'/report.php';
    $variant = $result;
    $variant['database_sha256'] = str_repeat('0', 64);
    $rejectedVariant = false;
    try {
        compareResults($result, $variant);
    } catch (RuntimeException) {
        $rejectedVariant = true;
    }
    requireThat($rejectedVariant, 'Diagnostic variant accepted as identical pricing input.');
    requireThat(transportReady($result), 'Complete raw transport validation did not pass.');
    foreach ($result['phases'] as $record) {
        requireThat($record['transport_validation']['checked_count'] === 2 && $record['transport_validation']['valid_count'] === 2, 'Transport count must include excluded rows.');
    }
    // Full raw calculator payload: do not reconstruct missing labels from the audit projection.
    $raw = app(CanonicalContractPriceCalculator::class)->calculate(
        (new CanonicalPricingParser)->parse($pricing, ['status' => 'exact'], null),
        new ContractContext('FixedPrice', 'FixedTerm', 'General', 'Fixed12', 'Household'),
        new EnergyUsage(total: 5000, basicLiving: 5000),
        new SpotAssumptions(null, null),
        CarbonImmutable::parse('2026-09-16', 'Europe/Helsinki'),
    )->toCalculatedCostArray();
    $validation = emptyTransportValidation();
    validateTransport($raw, 'valid', $validation);
    requireThat($validation['valid_count'] === 1, 'Valid raw payload rejected.');
    unset($raw['phase_breakdown'][0]['label']);
    for ($i = 0; $i < 105; $i++) {
        validateTransport($raw, 'invalid', $validation);
    }
    requireThat($validation['checked_count'] === 106 && $validation['error_count'] === 105 && count($validation['errors']) === 100, 'Transport errors not bounded or counted.');
    requireThat(! str_contains(json_encode($validation), 'PRIVATE SOURCE'), 'Validation leaked source.');
    requireThat(array_keys($validation['errors'][0]) === ['id', 'reason', 'class'], 'Unsafe validation detail.');
    $invalidTransport = $result;
    $invalidTransport['phases']['5000_cold']['transport_validation'] = $validation;
    $transportDiff = compareResults($invalidTransport, $result);
    requireThat($transportDiff['candidate_transport_ready'], 'Invalid baseline blocked valid candidate diagnostics.');
    requireThat($transportDiff['phases']['5000_cold']['transport_validation_before']['error_count'] === 105, 'Baseline errors lost.');
    requireThat($transportDiff['phases']['5000_cold']['changed_raw_total_count'] === 0, 'Transport changed financial counts.');
    requireThat(! compareResults($result, $invalidTransport)['candidate_transport_ready'], 'Invalid candidate passed transport gate.');
    $unverified = $result;
    unset($unverified['phases']['2000']['transport_validation']);
    requireThat(! transportReady($unverified), 'Old unverified artifact passed transport gate.');
    $same = compareResults($result, $result);
    requireThat($same['phases']['5000_cold']['changes'] === [], 'Identical fixture changed.');
    $changed = $result;
    foreach (['5000_cold', '5000_warm'] as $phase) {
        $changed['phases'][$phase]['rows']['fixed']['cost']['total_cost'] += 25;
    }
    $diff = compareResults($result, $changed);
    requireThat($diff['phases']['5000_cold']['max_absolute_delta_eur'] === 25.0, 'Difference calculation failed.');
    requireThat($diff['phases']['5000_cold']['changed_raw_total_count'] === 1, 'Raw changed count failed.');
    requireThat($diff['phases']['5000_cold']['changed_displayed_total_count'] === 1, 'Displayed changed count failed.');
    $termBaseline = $result;
    foreach (['5000_cold', '5000_warm'] as $phase) {
        $termBaseline['phases'][$phase]['rows']['fixed']['cost']['contract_term'] = ['months' => 6, 'total_cost' => 100, 'base_total_cost' => 100, 'discount_savings_total' => 0];
    }
    $termCandidate = $termBaseline;
    foreach (['5000_cold', '5000_warm'] as $phase) {
        $termCandidate['phases'][$phase]['rows']['fixed']['cost']['contract_term']['total_cost'] = 125;
        $termCandidate['phases'][$phase]['rows']['fixed']['cost']['contract_term']['base_total_cost'] = 125;
        $termCandidate['phases'][$phase]['rows']['fixed']['cost']['total_cost'] += 0.000000001;
    }
    $termDiff = compareResults($termBaseline, $termCandidate)['phases']['5000_cold'];
    requireThat($termDiff['changed_benefit_count'] === 0, 'Term-total-only change became a benefit change.');
    requireThat($termDiff['changed_term_metadata_count'] === 1, 'Term metadata change was lost.');
    requireThat($termDiff['changed_raw_total_count'] === 1 && $termDiff['changed_displayed_total_count'] === 0, 'Summation noise became a displayed price change.');
    requireThat($termDiff['changes']['fixed']['annual_displayed_delta_eur'] === 0.0, 'Displayed price delta was not zero.');
    requireThat(in_array('contract_term', $termDiff['changes']['fixed']['changed_fields'], true), 'Complete term changed_fields was lost.');
    foreach (['5000_cold', '5000_warm'] as $phase) {
        $termCandidate['phases'][$phase]['rows']['fixed']['cost']['contract_term']['discount_savings_total'] = 5;
    }
    requireThat(compareResults($termBaseline, $termCandidate)['phases']['5000_cold']['changed_benefit_count'] === 1, 'Real term savings change was lost.');
    foreach (['annual', 'term'] as $scope) {
        foreach ([
            'floating noise' => [5.95, 5.950000000000001, false],
            'one cent' => [5.95, 5.96, true],
            'rounding boundary' => [5.954, 5.956, true],
            'null to zero' => [null, 0, true],
            'zero to null' => [0, null, true],
            'missing to zero' => [null, 0, true],
            'eligibility' => [5.95, 5.950000000000001, true],
        ] as $case => [$oldSavings, $newSavings, $expected]) {
            $benefitBaseline = $termBaseline;
            $benefitCandidate = $termBaseline;
            foreach (['5000_cold', '5000_warm'] as $phase) {
                $oldCost = &$benefitBaseline['phases'][$phase]['rows']['fixed']['cost'];
                $newCost = &$benefitCandidate['phases'][$phase]['rows']['fixed']['cost'];
                $oldCost['includes_discounts'] = true;
                $newCost['includes_discounts'] = $case !== 'eligibility';
                if ($scope === 'term') {
                    $oldCost = &$oldCost['contract_term'];
                    $newCost = &$newCost['contract_term'];
                }
                $oldCost['discount_savings_total'] = $oldSavings;
                $newCost['discount_savings_total'] = $newSavings;
                if ($case === 'missing to zero') {
                    unset($oldCost['discount_savings_total']);
                }
                unset($oldCost, $newCost);
            }
            $benefitDiff = compareResults($benefitBaseline, $benefitCandidate)['phases']['5000_cold'];
            requireThat($benefitDiff['changed_benefit_count'] === (int) $expected, 'Benefit count failed: '.$scope.' '.$case);
            requireThat($benefitDiff['changes']['fixed']['benefit_changed'] === $expected, 'Benefit flag failed: '.$scope.' '.$case);
            requireThat(in_array($scope === 'term' ? 'contract_term' : 'discount_savings_total', $benefitDiff['changes']['fixed']['changed_fields'], true), 'Raw benefit diagnostic lost: '.$scope.' '.$case);
            requireThat($benefitDiff['changed_term_metadata_count'] === 0, 'Savings became term metadata: '.$scope.' '.$case);
        }
    }
    echo "PASS: raw transport, bounded errors, separate baseline/candidate gate; displayed benefit rounding, cent boundaries, null/missing and eligibility; six invalid manifests rejected; raw/joined/orphan counts verified; four exact totals, exclusions, read-only proof, source privacy, cold/warm parity, report identity/delta. Private fixture: $root\n";
} catch (Throwable $e) {
    // Safe here: all inputs in this script are synthetic.
    fwrite(STDERR, $e::class.': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
}
