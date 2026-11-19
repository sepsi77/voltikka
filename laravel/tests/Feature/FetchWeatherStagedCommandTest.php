<?php

namespace Tests\Feature;

use App\Services\ImportPayload\ImportPayloadReader;
use Carbon\Carbon;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * weather:fetch as a staged import (tasks/staged-api-imports/): fetch saves every Open-Meteo
 * answer in one payload file, store parses the file. Covers D1, D2, D6, the ecmwf_00z plan (S19)
 * and the S9 statement: the command is not date-bound, and every vintage keeps its fetch time.
 */
class FetchWeatherStagedCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SENTINEL = 4.321;

    private const TABLES = ['weather_daily_observations', 'weather_hourly_observations', 'weather_outlook_runs', 'hourly_forecast_runs'];

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-01 10:30 Helsinki.
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 8, 30, 0, 'UTC'));
        config([
            'open_meteo.request_delay_seconds' => 0,
            'open_meteo.backfill_request_delay_seconds' => 0,
            'open_meteo.retry_sleep_ms' => 0,
            'open_meteo.points' => [
                'helsinki' => config('open_meteo.points.helsinki'),
                'turku' => config('open_meteo.points.turku'),
            ],
            'open_meteo.neighbour_wind_points' => ['ee01_hiiumaa' => config('open_meteo.neighbour_wind_points.ee01_hiiumaa')],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> options, table of the forced store failure */
    public static function scenarios(): array
    {
        return [
            'default set' => [[], 'weather_outlook_runs'],
            'solar_ghi' => [['--only' => ['solar_ghi'], '--point' => ['101004', '100968']], 'hourly_forecast_runs'],
            'ecmwf_00z' => [['--only' => ['ecmwf_00z'], '--point' => ['101004', 'ee01_hiiumaa']], 'hourly_forecast_runs'],
            'ecmwf_prev_day1' => [['--only' => ['ecmwf_prev_day1']], 'hourly_forecast_runs'],
            // Weekly and monthly outlooks (Seasonal API): one model each, the same endpoint.
            'ecmwf_ec46' => [['--only' => ['ecmwf_ec46']], 'weather_outlook_runs'],
            'ecmwf_seas5' => [['--only' => ['ecmwf_seas5']], 'weather_outlook_runs'],
        ];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('scenarios')]
    public function test_default_mode_and_fetch_only_then_from_payload_write_the_same_rows(array $options): void
    {
        $this->fakeApi();
        $this->assertSame(0, Artisan::call('weather:fetch', $options));
        $this->assertSame('stored', $this->lastJsonLine()['status']);
        $defaultRows = $this->storedRows();
        $this->assertNotSame([], array_merge(...array_values($defaultRows)));

        $this->truncate();
        $this->assertSame(0, Artisan::call('weather:fetch', $options + ['--fetch-only' => true]));
        $payload = $this->lastJsonLine()['payload'];
        $this->assertTrue($payload['complete']);
        $this->assertSame([], array_merge(...array_values($this->storedRows())), 'Fetch only writes no rows.');

        // The store runs on the next Helsinki day and keeps every fetch-time fact (S5).
        Carbon::setTestNow(Carbon::create(2026, 11, 2, 9, 0, 0, 'UTC'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('weather:fetch', $options + [
            '--from-payload' => $payload['path'], '--payload-sha256' => $payload['sha256'],
        ]));

        $this->assertSame($defaultRows, $this->storedRows());
        foreach (self::TABLES as $table) {
            foreach (DB::table($table)->pluck('fetched_at') as $fetchedAt) {
                $this->assertSame('2026-11-01 08:30:00', $fetchedAt, "{$table} keeps the fetch time.");
            }
        }
        $issuedAt = DB::table('hourly_forecast_runs')->pluck('issued_at')->unique()->values()->all();
        $expectedIssuedAt = match ($options['--only'][0] ?? null) {
            'solar_ghi', 'ecmwf_00z' => ['2026-11-01 00:00:00'], // metadata before = after; the fetch day's 00 UTC run
            'ecmwf_prev_day1' => [null],
            default => [],
        };
        $this->assertSame($expectedIssuedAt, $issuedAt);

        // Outlook vintages keep their product, model, own model run time and member count.
        $outlooks = DB::table('weather_outlook_runs')->orderBy('point')->get()
            ->map(fn ($run) => [$run->product, $run->model, $run->point, $run->model_run_at, $run->day_count, $run->member_count])->all();
        $expectedOutlooks = match ($options['--only'][0] ?? null) {
            'ecmwf_ec46' => [['ecmwf_ec46', 'ecmwf_ec46', 'helsinki', '2026-10-31 00:00:00', 46, 4], ['ecmwf_ec46', 'ecmwf_ec46', 'turku', '2026-10-31 00:00:00', 46, 4]],
            'ecmwf_seas5' => [['ecmwf_seas5', 'ecmwf_seas5', 'helsinki', '2026-10-01 00:00:00', 183, 4], ['ecmwf_seas5', 'ecmwf_seas5', 'turku', '2026-10-01 00:00:00', 183, 4]],
            null => [['ecmwf_ens', 'ecmwf_ifs025', 'helsinki', '2026-11-01 00:00:00', 2, 3], ['ecmwf_ens', 'ecmwf_ifs025', 'turku', '2026-11-01 00:00:00', 2, 3]],
            default => [],
        };
        $this->assertSame($expectedOutlooks, $outlooks);
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('scenarios')]
    public function test_store_failure_writes_no_row_keeps_the_payload_reports_safe_sql_facts_and_replays(array $options, string $failTable): void
    {
        $this->fakeApi();
        Log::spy();
        // The second insert fails, after the first rows of the run were written in the same transaction.
        DB::statement("CREATE TRIGGER fail_store BEFORE INSERT ON {$failTable} WHEN (SELECT COUNT(*) FROM {$failTable}) >= 1 BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->assertSame(1, Artisan::call('weather:fetch', $options));
        $output = Artisan::output();
        $payloads = glob(config('import_payloads.path').'/open-meteo/2026-11-01/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'store_failed',
            'payload_path' => $payloads[0], 'payload_sha256' => hash_file('sha256', $payloads[0]),
            'sqlstate' => '23000', 'driver_code' => 19, 'table' => $failTable,
        ], $this->lastJsonLine($output));
        foreach (['forced failure', (string) self::SENTINEL] as $binding) {
            $this->assertStringNotContainsString($binding, $output);
        }
        $this->assertSame([], array_merge(...array_values($this->storedRows())), 'A failed store writes no row of the run (S21).');
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($failTable): bool {
            $this->assertSame('Import failed: weather:fetch', $message);
            $this->assertSame(['store', 'store_failed', '23000', 19, $failTable], [$context['step'], $context['error'], $context['sqlstate'], $context['driver_code'], $context['table']]);
            $this->assertStringNotContainsString('forced failure', json_encode($context));
            $this->assertStringNotContainsString((string) self::SENTINEL, json_encode($context));

            return true;
        });

        DB::statement('DROP TRIGGER fail_store'); // the fix
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('weather:fetch', $options + ['--from-payload' => $payloads[0]]));
        $this->assertSame('stored', $this->lastJsonLine()['status']);
        $this->assertGreaterThan(1, DB::table($failTable)->count());
    }

    public function test_ecmwf_00z_fetch_plans_only_points_missing_from_the_database(): void
    {
        $this->insertStoredRun('101004', '2026-11-01 00:00:00');
        $this->fakeApi();

        $this->assertSame(0, Artisan::call('weather:fetch', ['--only' => ['ecmwf_00z'], '--point' => ['101004', 'ee01_hiiumaa'], '--fetch-only' => true]));
        $reference = $this->lastJsonLine()['payload'];
        $this->assertSame(1, $reference['requests']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'latitude=58.896'));
        $this->assertSame(1, DB::table('hourly_forecast_runs')->count(), 'The fetch step reads the database but writes no row (S19).');

        $this->assertSame(['ecmwf_00z' => [
            'run_date' => '2026-11-01',
            'issued_at' => '2026-11-01 00:00:00',
            'already_stored' => ['101004'],
            'requested' => ['ee01_hiiumaa'],
        ]], ImportPayloadReader::open($reference['path'])->fact('single_runs'));

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('weather:fetch', ['--only' => ['ecmwf_00z'], '--point' => ['101004', 'ee01_hiiumaa'], '--from-payload' => $reference['path']]));
        $this->assertSame(['101004', 'ee01_hiiumaa'], DB::table('hourly_forecast_runs')->orderBy('point')->pluck('point')->all());
        $this->assertSame(['forecast_runs_stored' => 1], array_intersect_key($this->lastJsonLine()['counts'], ['forecast_runs_stored' => 0]));
    }

    public function test_ecmwf_00z_not_available_answer_is_saved_and_stays_a_quiet_wait_in_every_mode(): void
    {
        Http::fake(['single-runs-api.open-meteo.com/*' => Http::response(
            ['error' => true, 'reason' => 'The requested model run is not available. Model: ecmwf_ifs, run: 2026-11-01T00:00Z'], 400)]);
        $options = ['--only' => ['ecmwf_00z'], '--point' => ['101004', 'ee01_hiiumaa']];

        $this->assertSame(0, Artisan::call('weather:fetch', $options + ['--fetch-only' => true]));
        $reference = $this->lastJsonLine()['payload'];
        Http::assertSentCount(1); // the other point is not requested
        $this->assertTrue($reference['complete'], 'A run that is not ready is not a failed unit.');

        $reader = ImportPayloadReader::open($reference['path']);
        $requests = iterator_to_array($reader->requests(), false);
        $this->assertCount(1, $requests);
        $this->assertSame(400, $requests[0]['status']);
        $this->assertStringContainsString('not available', (string) ImportPayloadReader::body($requests[0]));

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('weather:fetch', $options + ['--from-payload' => $reference['path']]));
        $line = $this->lastJsonLine();
        $this->assertSame(['stored', true, 0, 0], [$line['status'], $line['complete'], $line['counts']['forecast_runs_stored'], $line['counts']['failed_points']]);
        $this->assertSame(0, DB::table('hourly_forecast_runs')->count());
    }

    public function test_a_failed_point_is_saved_as_a_failed_unit_and_keeps_exit_1_after_storing_the_rest(): void
    {
        $this->fakeApi(failLatitude: '60.20307'); // 101004
        $options = ['--only' => ['ecmwf_00z'], '--point' => ['101004', 'ee01_hiiumaa']];

        $this->assertSame(0, Artisan::call('weather:fetch', $options + ['--fetch-only' => true]));
        $reference = $this->lastJsonLine()['payload'];
        $this->assertSame([2, 1, false], [$reference['requests'], $reference['failed_units'], $reference['complete']]);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(1, Artisan::call('weather:fetch', $options + ['--from-payload' => $reference['path']]));
        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'fetch', 'fetch_incomplete', false, 1], [$line['status'], $line['step'], $line['error'], $line['complete'], $line['counts']['failed_points']]);
        $this->assertSame(['ee01_hiiumaa'], DB::table('hourly_forecast_runs')->pluck('point')->all());
    }

    /**
     * A replay stores only the newest payload: for the observed upserts and the outlook
     * vintages, an older same-day payload is refused as superseded and writes nothing, so it
     * cannot put older observed values back.
     */
    public function test_an_older_same_day_payload_is_refused_for_observed_rows_and_outlooks(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 5, 0, 0, 'UTC'));
        $this->fakeApi();
        Artisan::call('weather:fetch', ['--point' => ['helsinki', 'ee01_hiiumaa']]);
        $older = $this->lastJsonLine()['payload']['path'];

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 7, 0, 0, 'UTC'));
        $this->fakeApi(value: 9.5);
        Artisan::call('weather:fetch', ['--point' => ['helsinki', 'ee01_hiiumaa']]);
        $before = $this->storedRows();

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 9, 0, 0, 'UTC'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(1, Artisan::call('weather:fetch', ['--point' => ['helsinki', 'ee01_hiiumaa'], '--from-payload' => $older]));

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => 'superseded',
            'payload_path' => $older, 'payload_sha256' => hash_file('sha256', $older),
        ], $this->lastJsonLine());
        $this->assertSame($before, $this->storedRows());
        $daily = DB::table('weather_daily_observations')->where('date', '2026-10-31')->first();
        $this->assertEqualsWithDelta(9.5, $daily->temperature_2m_mean, 0.0001);
        $this->assertSame('2026-11-01 07:00:00', $daily->fetched_at);
    }

    /**
     * The same rule for the hourly forecast vintages: a fetched-only payload is refused once a
     * newer payload of the same options exists, so no vintage is added out of order.
     */
    public function test_an_older_same_day_hourly_forecast_payload_is_refused_as_superseded(): void
    {
        $options = ['--only' => ['solar_ghi'], '--point' => ['101004']];
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 5, 0, 0, 'UTC'));
        $this->fakeApi(runAt: 1793469600); // 2026-10-31 18:00Z
        Artisan::call('weather:fetch', $options + ['--fetch-only' => true]);
        $older = $this->lastJsonLine()['payload']['path'];

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 7, 0, 0, 'UTC'));
        $this->fakeApi(value: 9.5);
        Artisan::call('weather:fetch', $options);

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 9, 0, 0, 'UTC'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(1, Artisan::call('weather:fetch', $options + ['--from-payload' => $older]));

        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'store', 'payload_invalid', 'superseded'], [$line['status'], $line['step'], $line['error'], $line['reason']]);
        $runs = DB::table('hourly_forecast_runs')->get(['fetched_at', 'issued_at']);
        $this->assertSame([['2026-11-01 07:00:00', '2026-11-01 00:00:00']], $runs->map(fn ($run) => [$run->fetched_at, $run->issued_at])->all());
    }

    /** A failed request after a parser error in the same point is still counted (fetch_incomplete). */
    public function test_a_failed_piece_after_an_invalid_piece_of_the_same_point_is_still_counted(): void
    {
        config(['open_meteo.points.oulu' => ['name' => 'Oulu', 'latitude' => 65.01, 'longitude' => 25.47, 'group' => 'fi', 'weight' => 0.131]]);
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            // The first year piece answers with a body the store rejects, the second fails.
            return $query['start_date'] === '2024-12-30'
                ? Http::response(['daily' => ['time' => ['2024-12-30']], 'daily_units' => []])
                : Http::response(['error' => true, 'reason' => 'boom'], 400);
        });

        $this->assertSame(1, Artisan::call('weather:fetch', ['--from' => '2024-12-30', '--to' => '2025-01-02', '--point' => ['oulu']]));

        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'fetch', 'fetch_incomplete'], [$line['status'], $line['step'], $line['error']]);
        $this->assertSame([1, 1], [$line['counts']['failed_points'], $line['counts']['invalid_points']]);
        $this->assertSame(0, DB::table('weather_daily_observations')->count());
    }

    /** The store keeps the model identity of the fetch (S5): a config change does not relabel saved answers. */
    public function test_the_store_uses_the_model_identities_recorded_at_fetch_time(): void
    {
        $this->fakeApi();
        $options = ['--point' => ['helsinki', 'ee01_hiiumaa']];
        $this->assertSame(0, Artisan::call('weather:fetch', $options + ['--fetch-only' => true]));
        $path = $this->lastJsonLine()['payload']['path'];
        $observedModel = config('open_meteo.observed_model');
        $observedHourlyModel = config('open_meteo.observed_hourly_model');
        $this->assertSame(['observed', 'observed_hourly', 'ecmwf_ens'], array_keys(ImportPayloadReader::open($path)->fact('models')));

        config([
            'open_meteo.observed_model' => 'changed_model',
            'open_meteo.observed_hourly_model' => 'changed_model',
            'open_meteo.outlooks.ecmwf_ens.model' => 'changed_model',
        ]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('weather:fetch', $options + ['--from-payload' => $path]));

        $this->assertSame([$observedModel], DB::table('weather_daily_observations')->distinct()->pluck('model')->all());
        $this->assertSame([$observedHourlyModel], DB::table('weather_hourly_observations')->distinct()->pluck('model')->all());
        $this->assertSame(['ecmwf_ifs025'], DB::table('weather_outlook_runs')->distinct()->pluck('model')->all());
    }

    /** A payload written before the models were recorded is refused, never stored with the current config. */
    public function test_a_payload_without_recorded_models_is_refused_as_incomplete(): void
    {
        $this->fakeApi();
        $this->assertSame(0, Artisan::call('weather:fetch', ['--fetch-only' => true]));
        $path = $this->lastJsonLine()['payload']['path'];
        $this->removeHeaderFacts($path, ['models']);

        $this->assertSame(1, Artisan::call('weather:fetch', ['--from-payload' => $path]));

        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'store', 'payload_invalid', 'incomplete'], [$line['status'], $line['step'], $line['error'], $line['reason']]);
        $this->assertSame([], array_merge(...array_values($this->storedRows())));
    }

    public function test_a_backfill_store_commits_per_piece_and_its_replay_never_sleeps(): void
    {
        $this->fakeApi();
        $options = ['--from' => '2024-12-30', '--to' => '2026-01-02', '--point' => ['oulu']];
        config(['open_meteo.points.oulu' => ['name' => 'Oulu', 'latitude' => 65.01, 'longitude' => 25.47, 'group' => 'fi', 'weight' => 0.131]]);

        $this->assertSame(0, Artisan::call('weather:fetch', $options + ['--fetch-only' => true]));
        $reference = $this->lastJsonLine()['payload'];
        $this->assertSame(3, $reference['requests']); // three calendar-year pieces
        $this->assertSame([['2024-12-30', '2024-12-31'], ['2025-01-01', '2025-12-31'], ['2026-01-01', '2026-01-02']], ImportPayloadReader::open($reference['path'])->fact('windows'));

        // One commit per point and year piece (S21): a failure in the third piece keeps the first two.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        DB::statement("CREATE TRIGGER fail_piece BEFORE INSERT ON weather_daily_observations WHEN (SELECT COUNT(*) FROM weather_daily_observations) >= 4 BEGIN SELECT RAISE(ABORT, 'forced failure'); END");
        $this->assertSame(1, Artisan::call('weather:fetch', $options + ['--from-payload' => $reference['path']]));
        $line = $this->lastJsonLine();
        $this->assertSame(['store_failed', 'weather_daily_observations'], [$line['error'], $line['table']]);
        $this->assertSame(4, DB::table('weather_daily_observations')->count());
        DB::statement('DROP TRIGGER fail_piece');

        // The 30 s backfill pause belongs to the fetch step only.
        config(['open_meteo.backfill_request_delay_seconds' => 30]);
        $started = microtime(true);
        $this->assertSame(0, Artisan::call('weather:fetch', $options + ['--from-payload' => $reference['path']]));
        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertSame(6, $this->lastJsonLine()['counts']['daily_rows']);
    }

    private function fakeApi(float $value = self::SENTINEL, ?string $failLatitude = null, int $runAt = 1793491200): void
    {
        // Seasonal API metadata: EC46 runs daily (2026-10-31 00Z), SEAS5 monthly (2026-10-01 00Z).
        $seasonalRunAt = ['ecmwf_ec46' => 1793404800, 'ecmwf_seas5' => 1790812800];
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($value, $failLatitude, $runAt, $seasonalRunAt) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if ($failLatitude !== null && ($query['latitude'] ?? null) === $failLatitude) {
                return Http::response(['error' => true, 'reason' => 'Parameter hourly is invalid'], 400);
            }

            return match (true) {
                str_contains($url, 'seasonal-api') && str_contains($url, '/data/') => Http::response(['last_run_initialisation_time' => $seasonalRunAt[explode('/', (string) parse_url($url, PHP_URL_PATH))[2]]]),
                str_contains($url, '/data/') => Http::response(['last_run_initialisation_time' => $runAt]),
                str_contains($url, 'seasonal-api') => $this->seasonalResponse($query, $value),
                str_contains($url, 'archive-api') && isset($query['hourly']) => Http::response($this->hourlyPayload(config('open_meteo.observed_hourly_variables'), 3, $value)),
                str_contains($url, 'archive-api') => Http::response($this->dailyPayload(config('open_meteo.observed_variables'), [$query['start_date'], $query['end_date']], $value)),
                str_contains($url, 'ensemble-api') => Http::response($this->dailyPayload(config('open_meteo.outlooks.ecmwf_ens.variables'), ['2026-11-01', '2026-11-02'], $value, 2)),
                str_contains($url, 'single-runs-api') => Http::response($this->hourlyPayload(config('open_meteo.hourly_forecasts.ecmwf_00z.variables'), 24, $value)),
                str_contains($url, 'previous-runs-api') => Http::response($this->hourlyPayload(config('open_meteo.hourly_forecasts.ecmwf_prev_day1.variables'), 24, $value)),
                str_contains($url, 'https://api.open-meteo.com/v1/') => Http::response($this->hourlyPayload(config('open_meteo.hourly_forecasts.solar_ghi.variables'), 24, $value)),
                default => Http::response('unexpected', 500),
            };
        });
    }

    /**
     * The Seasonal API answer of one model: control + 3 members per variable, as the real API
     * (control key without a suffix, then "_memberNN"). A request whose model, variables, length
     * or wind unit differs from the configured product gets an error answer, so the scenario fails.
     *
     * @param  array<string, mixed>  $query
     */
    private function seasonalResponse(array $query, float $value): PromiseInterface
    {
        $product = config('open_meteo.outlooks.'.($query['models'] ?? ''));
        if (! is_array($product)
            || $product['url'] !== 'https://seasonal-api.open-meteo.com/v1/seasonal'
            || $query['daily'] !== implode(',', $product['variables'])
            || (int) $query['forecast_days'] !== $product['forecast_days']
            || $query['wind_speed_unit'] !== 'ms'
            || $query['timezone'] !== 'Europe/Helsinki') {
            return Http::response(['error' => true, 'reason' => 'unexpected seasonal request'], 400);
        }
        $dates = array_map(fn ($d) => Carbon::create(2026, 11, 1)->addDays($d)->toDateString(), range(0, $product['forecast_days'] - 1));

        return Http::response($this->dailyPayload($product['variables'], $dates, $value, 3));
    }

    /**
     * @param  list<string>  $variables
     * @param  list<string>  $dates
     * @return array<string, mixed>
     */
    private function dailyPayload(array $variables, array $dates, float $value, int $members = 0): array
    {
        $daily = ['time' => $dates];
        $units = ['time' => 'iso8601'];
        foreach ($variables as $variable) {
            for ($m = 0; $m <= $members; $m++) {
                $key = $m === 0 ? $variable : sprintf('%s_member%02d', $variable, $m);
                $daily[$key] = array_fill(0, count($dates), $value);
                $units[$key] = config('open_meteo.units')[$variable];
            }
        }

        return ['latitude' => 60.25, 'longitude' => 25.0, 'daily_units' => $units, 'daily' => $daily];
    }

    /**
     * @param  list<string>  $variables
     * @return array<string, mixed>
     */
    private function hourlyPayload(array $variables, int $hours, float $value): array
    {
        $start = Carbon::parse('2026-10-25 00:00', 'UTC')->timestamp;
        $hourly = ['time' => array_map(fn ($i) => $start + $i * 3600, range(0, $hours - 1))];
        $units = ['time' => 'unixtime'];
        foreach ($variables as $variable) {
            $hourly[$variable] = array_fill(0, $hours, $value);
            $units[$variable] = config('open_meteo.units')[$variable];
        }

        return ['latitude' => 58.875, 'longitude' => 22.5, 'utc_offset_seconds' => 0, 'timezone' => 'GMT', 'hourly_units' => $units, 'hourly' => $hourly];
    }

    private function insertStoredRun(string $point, string $issuedAt): void
    {
        DB::table('hourly_forecast_runs')->insert([
            'source' => 'open_meteo', 'product' => 'ecmwf_00z', 'point' => $point, 'payload_hash' => str_repeat('a', 64),
            'issued_at' => $issuedAt, 'fetched_at' => '2026-11-01 07:00:00', 'first_timestamp' => 1793491200,
            'first_utc_datetime' => '2026-11-01 00:00:00', 'hour_count' => 1, 'values' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function truncate(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)->delete();
        }
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function storedRows(): array
    {
        $strip = fn ($row) => array_diff_key((array) $row, array_flip(['id', 'created_at', 'updated_at']));
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = DB::table($table)->orderBy('point')->orderBy('id')->get()->map($strip)->all();
        }

        return $rows;
    }

    /**
     * Rewrite a payload file as one written before these header facts existed (a valid file with
     * a new .sha256), to test how the store treats older payloads.
     *
     * @param  list<string>  $facts
     */
    private function removeHeaderFacts(string $path, array $facts): void
    {
        $lines = explode("\n", rtrim((string) gzdecode((string) file_get_contents($path)), "\n"));
        $header = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        $header['facts'] = (object) array_diff_key($header['facts'], array_flip($facts));
        $lines[0] = json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($path, gzencode(implode("\n", $lines)."\n"));
        file_put_contents(substr($path, 0, -strlen('.ndjson.gz')).'.sha256', hash_file('sha256', $path)."\n");
    }

    /** @return array<string, mixed> */
    private function lastJsonLine(?string $output = null): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output ?? Artisan::output()))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
