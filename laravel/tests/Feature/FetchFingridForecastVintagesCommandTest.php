<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FetchFingridForecastVintagesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-01 12:45 Helsinki = 10:45 UTC: the first full future hour is 11:00 UTC.
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 10, 45, 0, 'UTC'));
        config([
            'fingrid.api_key' => 'test-key',
            'fingrid.request_delay_seconds' => 0,
            'fingrid.retry_sleep_ms' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, float|null>  $quarters  startTime => value
     */
    private function payload(array $quarters): array
    {
        $rows = [];
        foreach ($quarters as $start => $value) {
            $rows[] = ['datasetId' => 245, 'startTime' => $start, 'endTime' => $start, 'value' => $value];
        }

        return ['data' => $rows, 'pagination' => ['nextPage' => null]];
    }

    private function fullHour(string $hour, float $base): array
    {
        return [
            "{$hour}:00:00.000Z" => $base,
            "{$hour}:15:00.000Z" => $base + 10,
            "{$hour}:30:00.000Z" => $base + 20,
            "{$hour}:45:00.000Z" => $base + 30,
        ];
    }

    public function test_stores_hourly_means_from_the_next_full_hour_and_nulls_partial_hours(): void
    {
        $quarters = [
            // The current hour is outside the requested window and is ignored if returned.
            '2026-11-01T10:45:00.000Z' => 5000.0,
            ...$this->fullHour('2026-11-01T11', 1000.0),
            // 12:00 has only three quarters: stored as null.
            '2026-11-01T12:00:00.000Z' => 900.0,
            '2026-11-01T12:15:00.000Z' => 900.0,
            '2026-11-01T12:30:00.000Z' => null,
            '2026-11-01T12:45:00.000Z' => 900.0,
            ...$this->fullHour('2026-11-01T13', 2000.0),
            // A partial last hour is trimmed.
            '2026-11-01T14:00:00.000Z' => 2100.0,
        ];

        Http::fake([
            'data.fingrid.fi/api/datasets/245/data*' => Http::sequence()
                ->push($this->payload($quarters))
                ->push($this->payload($quarters)),
        ]);

        $this->artisan('fingrid:fetch-forecast-vintages')->assertExitCode(0);

        $run = DB::table('hourly_forecast_runs')->first();
        $this->assertSame('fingrid', $run->source);
        $this->assertSame('245', $run->product);
        $this->assertSame('', $run->point);
        $this->assertNull($run->issued_at);
        $this->assertSame('2026-11-01 10:45:00', $run->fetched_at);
        $this->assertSame(Carbon::create(2026, 11, 1, 11, 0, 0, 'UTC')->timestamp, (int) $run->first_timestamp);
        $this->assertSame('2026-11-01 11:00:00', $run->first_utc_datetime);
        $this->assertSame(3, (int) $run->hour_count);
        $this->assertNull($run->grid_latitude);

        $values = json_decode($run->values, true);
        $this->assertSame(['wind_power_mw'], array_keys($values));
        $this->assertEqualsWithDelta(1015.0, $values['wind_power_mw'][0], 0.0001);
        $this->assertNull($values['wind_power_mw'][1]);
        $this->assertEqualsWithDelta(2015.0, $values['wind_power_mw'][2], 0.0001);
        $this->assertSame(hash('sha256', json_encode([(int) $run->first_timestamp, $values])), $run->payload_hash);

        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'test-key')
            && str_contains($request->url(), 'startTime=2026-11-01T11%3A00%3A00Z')
            && str_contains($request->url(), 'endTime=2026-11-04T11%3A00%3A00Z'));

        // The same payload in the same hour adds no row.
        $this->artisan('fingrid:fetch-forecast-vintages --dataset=245')->assertExitCode(0);
        $this->assertSame(1, DB::table('hourly_forecast_runs')->count());
    }

    public function test_a_revised_forecast_adds_a_new_vintage(): void
    {
        Http::fake([
            'data.fingrid.fi/api/datasets/245/data*' => Http::sequence()
                ->push($this->payload($this->fullHour('2026-11-01T11', 1000.0)))
                ->push($this->payload($this->fullHour('2026-11-01T11', 1100.0))),
        ]);

        $this->artisan('fingrid:fetch-forecast-vintages')->assertExitCode(0);
        $this->artisan('fingrid:fetch-forecast-vintages')->assertExitCode(0);

        $this->assertSame(2, DB::table('hourly_forecast_runs')->where('source', 'fingrid')->count());
    }

    public function test_a_response_without_a_complete_hour_fails(): void
    {
        Http::fake([
            // Hourly-resolution rows (one per hour) are not four quarters.
            'data.fingrid.fi/api/datasets/245/data*' => Http::response($this->payload([
                '2026-11-01T11:00:00.000Z' => 1000.0,
                '2026-11-01T12:00:00.000Z' => 1000.0,
            ])),
        ]);

        $this->assertSame(1, Artisan::call('fingrid:fetch-forecast-vintages'));
        $this->assertSame(0, DB::table('hourly_forecast_runs')->count());
        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'store', 'no_complete_hour'], [$line['status'], $line['step'], $line['error']]);
    }

    public function test_unknown_dataset_is_rejected(): void
    {
        Http::fake();

        $this->artisan('fingrid:fetch-forecast-vintages --dataset=246')->assertExitCode(1);
        Http::assertNothingSent();
    }

    private function fakeVintage(float $base): void
    {
        Http::swap(new Factory);
        Http::fake(['data.fingrid.fi/api/datasets/245/data*' => Http::response($this->payload([
            ...$this->fullHour('2026-11-01T11', $base),
            ...$this->fullHour('2026-11-01T12', $base + 100),
        ]))]);
    }

    public function test_default_mode_and_fetch_only_then_from_payload_write_the_same_rows(): void
    {
        $this->fakeVintage(1000.0);
        $this->assertSame(0, Artisan::call('fingrid:fetch-forecast-vintages'));
        $this->assertSame(['vintages_inserted' => 1], $this->lastJsonLine()['counts']);
        $defaultRows = $this->storedRows();

        DB::table('hourly_forecast_runs')->delete();
        $this->assertSame(0, Artisan::call('fingrid:fetch-forecast-vintages', ['--fetch-only' => true]));
        $payload = $this->lastJsonLine()['payload'];
        $this->assertSame(0, DB::table('hourly_forecast_runs')->count(), 'Fetch only writes no rows.');
        $facts = $this->payloadLines($payload['path'])[0]['facts'];
        $this->assertSame(['first_hour' => '2026-11-01T11:00:00Z', 'hours' => ['245' => 72]], $facts);

        // Two hours later the store keeps the fetch-time vintage: first hour 11:00, fetched_at 10:45 (S5).
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 12, 45, 0, 'UTC'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();
        $this->assertSame(0, Artisan::call('fingrid:fetch-forecast-vintages', ['--from-payload' => $payload['path'], '--payload-sha256' => $payload['sha256']]));
        Http::assertNothingSent();

        $this->assertSame($defaultRows, $this->storedRows());
        $run = DB::table('hourly_forecast_runs')->sole();
        $this->assertSame(['2026-11-01 10:45:00', '2026-11-01 11:00:00'], [$run->fetched_at, $run->first_utc_datetime]);
        $this->assertNull($run->issued_at);
    }

    public function test_store_failure_writes_no_row_keeps_the_payload_reports_safe_sql_facts_and_replays(): void
    {
        $this->fakeVintage(1000.0);
        Log::spy();
        DB::statement("CREATE TRIGGER fail_runs BEFORE INSERT ON hourly_forecast_runs BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->assertSame(1, Artisan::call('fingrid:fetch-forecast-vintages'));
        $output = Artisan::output();

        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'store_failed', 'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'hourly_forecast_runs'], $this->lastJsonLine($output));
        foreach (['forced failure', 'wind_power_mw', '1015'] as $binding) {
            $this->assertStringNotContainsString($binding, $output);
        }
        $this->assertSame(0, DB::table('hourly_forecast_runs')->count());
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('Import failed: fingrid:fetch-forecast-vintages', $message);
            $this->assertSame(['store', '23000', 19, 'hourly_forecast_runs'], [$context['step'], $context['sqlstate'], $context['driver_code'], $context['table']]);
            foreach (['forced failure', 'wind_power_mw', '1015', 'test-key'] as $binding) {
                $this->assertStringNotContainsString($binding, json_encode($context));
            }

            return true;
        });

        $payloads = glob(config('import_payloads.path').'/fingrid/2026-11-01/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

        DB::statement('DROP TRIGGER fail_runs'); // the fix
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();
        $this->assertSame(0, Artisan::call('fingrid:fetch-forecast-vintages', ['--from-payload' => $payloads[0]]));
        Http::assertNothingSent();
        $this->assertSame('2026-11-01 10:45:00', DB::table('hourly_forecast_runs')->sole()->fetched_at);
    }

    public function test_the_api_key_never_reaches_the_payload(): void
    {
        $this->fakeVintage(1000.0);

        $this->assertSame(0, Artisan::call('fingrid:fetch-forecast-vintages', ['--fetch-only' => true]));

        $path = $this->lastJsonLine()['payload']['path'];
        $this->assertStringNotContainsString('test-key', (string) gzdecode((string) file_get_contents($path)));
        $this->assertSame(['dataset' => 245, 'page' => 1], $this->payloadLines($path)[1]['unit']);
    }

    /**
     * S9: not date-bound. An older payload from the same day is accepted. It inserts its vintage
     * with its own fetch time when that vintage is not stored yet (for example after a failed
     * store); a vintage that is already stored adds nothing (unique payload hash).
     */
    public function test_an_older_same_day_payload_is_accepted_and_adds_only_a_missing_vintage(): void
    {
        $this->fakeVintage(1000.0);
        Artisan::call('fingrid:fetch-forecast-vintages', ['--fetch-only' => true]);
        $older = $this->lastJsonLine()['payload']['path'];

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 10, 55, 0, 'UTC'));
        $this->fakeVintage(1100.0);
        $this->assertSame(0, Artisan::call('fingrid:fetch-forecast-vintages'));

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 13, 0, 0, 'UTC'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('fingrid:fetch-forecast-vintages', ['--from-payload' => $older]));
        $this->assertSame(['vintages_inserted' => 1], $this->lastJsonLine()['counts']);
        $this->assertSame(['2026-11-01 10:45:00', '2026-11-01 10:55:00'], DB::table('hourly_forecast_runs')->orderBy('fetched_at')->pluck('fetched_at')->all());

        $this->assertSame(0, Artisan::call('fingrid:fetch-forecast-vintages', ['--from-payload' => $older]));
        $this->assertSame(['vintages_inserted' => 0], $this->lastJsonLine()['counts']);
        $this->assertSame(2, DB::table('hourly_forecast_runs')->count());
    }

    public function test_a_failed_request_prints_fetch_incomplete(): void
    {
        Http::fake(['data.fingrid.fi/api/datasets/245/data*' => Http::response(['message' => 'boom'], 503)]);

        $this->assertSame(1, Artisan::call('fingrid:fetch-forecast-vintages'));

        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'fetch', 'fetch_incomplete', false], [$line['status'], $line['step'], $line['error'], $line['complete']]);
        $this->assertSame(0, DB::table('hourly_forecast_runs')->count());
    }

    /** @return list<array<string, mixed>> */
    private function storedRows(): array
    {
        $strip = fn ($row) => array_diff_key((array) $row, array_flip(['id', 'created_at', 'updated_at']));

        return DB::table('hourly_forecast_runs')->orderBy('id')->get()->map($strip)->all();
    }

    /** @return list<array<string, mixed>> */
    private function payloadLines(string $path): array
    {
        $lines = array_filter(explode("\n", (string) gzdecode((string) file_get_contents($path))));

        return array_values(array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
    }

    /** @return array<string, mixed> */
    private function lastJsonLine(?string $output = null): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output ?? Artisan::output()))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
