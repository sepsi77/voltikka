<?php

namespace Tests\Feature;

use App\Services\ImportPayload\ImportPayloadReader;
use Carbon\Carbon;
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

        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'store_failed', 'sqlstate' => '23000', 'driver_code' => 19, 'table' => $failTable], $this->lastJsonLine($output));
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

        $payloads = glob(config('import_payloads.path').'/open-meteo/2026-11-01/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

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
     * S9 for the observed upserts and the outlook vintages: not date-bound, so an older payload of
     * the same day is accepted. Observed rows take the replayed values and their fetch time (an
     * upsert, as a manual replay is meant to); an outlook vintage that exists adds no row.
     */
    public function test_an_older_same_day_payload_is_accepted_for_observed_rows_and_outlooks(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 5, 0, 0, 'UTC'));
        $this->fakeApi();
        Artisan::call('weather:fetch', ['--point' => ['helsinki', 'ee01_hiiumaa']]);
        $older = $this->lastJsonLine()['payload']['path'];

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 7, 0, 0, 'UTC'));
        $this->fakeApi(value: 9.5);
        Artisan::call('weather:fetch', ['--point' => ['helsinki', 'ee01_hiiumaa']]);
        $this->assertSame(2, DB::table('weather_outlook_runs')->count());

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 9, 0, 0, 'UTC'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('weather:fetch', ['--point' => ['helsinki', 'ee01_hiiumaa'], '--from-payload' => $older]));

        $this->assertSame(0, $this->lastJsonLine()['counts']['outlook_runs_stored']);
        $this->assertSame(2, DB::table('weather_outlook_runs')->count());
        $daily = DB::table('weather_daily_observations')->where('date', '2026-10-31')->first();
        $this->assertEqualsWithDelta(self::SENTINEL, $daily->temperature_2m_mean, 0.0001);
        $this->assertSame('2026-11-01 05:00:00', $daily->fetched_at);
        $this->assertSame(['2026-11-01 05:00:00'], DB::table('weather_hourly_observations')->pluck('fetched_at')->unique()->values()->all());
    }

    /**
     * S9 for the hourly forecast vintages: an older same-day payload is accepted. A vintage that was
     * only fetched is stored later with its own fetched_at and issued_at, so readers that select by
     * those times (SForecastReadiness) see the true chronology.
     */
    public function test_an_older_same_day_hourly_forecast_payload_is_stored_with_its_fetch_time(): void
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
        $this->assertSame(0, Artisan::call('weather:fetch', $options + ['--from-payload' => $older]));

        $runs = DB::table('hourly_forecast_runs')->orderBy('fetched_at')->get(['fetched_at', 'issued_at']);
        $this->assertSame([
            ['2026-11-01 05:00:00', '2026-10-31 18:00:00'],
            ['2026-11-01 07:00:00', '2026-11-01 00:00:00'],
        ], $runs->map(fn ($run) => [$run->fetched_at, $run->issued_at])->all());
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
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($value, $failLatitude, $runAt) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if ($failLatitude !== null && ($query['latitude'] ?? null) === $failLatitude) {
                return Http::response(['error' => true, 'reason' => 'Parameter hourly is invalid'], 400);
            }

            return match (true) {
                str_contains($url, '/data/') => Http::response(['last_run_initialisation_time' => $runAt]),
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

    /** @return array<string, mixed> */
    private function lastJsonLine(?string $output = null): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output ?? Artisan::output()))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
