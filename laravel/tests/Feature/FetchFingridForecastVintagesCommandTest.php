<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

        $this->artisan('fingrid:fetch-forecast-vintages')->assertExitCode(1);
        $this->assertSame(0, DB::table('hourly_forecast_runs')->count());
    }

    public function test_unknown_dataset_is_rejected(): void
    {
        Http::fake();

        $this->artisan('fingrid:fetch-forecast-vintages --dataset=246')->assertExitCode(1);
        Http::assertNothingSent();
    }
}
