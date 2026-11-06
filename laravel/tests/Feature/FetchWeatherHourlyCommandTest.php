<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchWeatherHourlyCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-01 10:30 Helsinki = 08:30 UTC.
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 8, 30, 0, 'UTC'));
        config([
            'open_meteo.request_delay_seconds' => 0,
            'open_meteo.backfill_request_delay_seconds' => 0,
            'open_meteo.retry_sleep_ms' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  list<int>  $times
     * @param  array<string, list<float|null>>  $series
     */
    private function hourlyPayload(array $times, array $series, array $unitOverrides = [], int $utcOffset = 0): array
    {
        $units = ['time' => 'unixtime'];
        foreach (array_keys($series) as $variable) {
            $units[$variable] = $unitOverrides[$variable] ?? config('open_meteo.units')[$variable];
        }

        return [
            'latitude' => 58.875,
            'longitude' => 22.5,
            'utc_offset_seconds' => $utcOffset,
            'timezone' => 'GMT',
            'hourly_units' => $units,
            'hourly' => ['time' => $times, ...$series],
        ];
    }

    /** @return list<int> */
    private function hours(string $startUtc, int $count): array
    {
        $start = Carbon::parse($startUtc, 'UTC')->timestamp;

        return array_map(fn ($i) => $start + $i * 3600, range(0, $count - 1));
    }

    public function test_hourly_window_stores_utc_hours_and_skips_hours_without_data(): void
    {
        $times = $this->hours('2026-10-25 22:00', 3);

        Http::fake([
            'archive-api.open-meteo.com/*' => Http::sequence()
                ->push($this->hourlyPayload($times, ['wind_speed_10m' => [7.1, 6.0, null], 'wind_speed_100m' => [10.2, null, null]]))
                ->push($this->hourlyPayload($times, ['wind_speed_10m' => [7.3, 6.0, 5.0], 'wind_speed_100m' => [10.4, 9.0, 8.0]])),
        ]);

        $this->artisan('weather:fetch --only=observed_hourly --point=ee01_hiiumaa')->assertExitCode(0);

        $rows = DB::table('weather_hourly_observations')->orderBy('timestamp')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('ee01_hiiumaa', $rows[0]->point);
        $this->assertSame('era5', $rows[0]->model);
        $this->assertSame($times[0], (int) $rows[0]->timestamp);
        $this->assertSame('2026-10-25 22:00:00', $rows[0]->utc_datetime);
        $this->assertEqualsWithDelta(7.1, $rows[0]->wind_speed_10m, 0.0001);
        $this->assertEqualsWithDelta(10.2, $rows[0]->wind_speed_100m, 0.0001);
        $this->assertNull($rows[1]->wind_speed_100m);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'latitude=58.896')
            && str_contains($request->url(), 'start_date=2026-10-11')
            && str_contains($request->url(), 'end_date=2026-10-31')
            && str_contains($request->url(), 'hourly=wind_speed_10m%2Cwind_speed_100m')
            && str_contains($request->url(), 'models=era5')
            && str_contains($request->url(), 'timezone=GMT')
            && str_contains($request->url(), 'timeformat=unixtime')
            && str_contains($request->url(), 'wind_speed_unit=ms'));

        // A rerun replaces revised values and adds the newly available hour.
        $this->artisan('weather:fetch --only=observed_hourly --point=ee01_hiiumaa')->assertExitCode(0);
        $this->assertSame(3, DB::table('weather_hourly_observations')->count());
        $this->assertEqualsWithDelta(7.3, DB::table('weather_hourly_observations')->where('timestamp', $times[0])->value('wind_speed_10m'), 0.0001);
    }

    public function test_hourly_backfill_uses_year_pieces_and_a_failed_point_does_not_stop_the_others(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'latitude=56.426')) {
                return Http::response(['error' => true], 500);
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $start = Carbon::parse($query['start_date'], 'UTC')->timestamp;

            return Http::response($this->hourlyPayload([$start], ['wind_speed_10m' => [4.0], 'wind_speed_100m' => [6.0]]));
        });

        $this->artisan('weather:fetch --from=2023-12-31 --to=2025-01-01 --only=observed_hourly --point=dk01_vesterhav --point=se02_blakliden')
            ->assertExitCode(1);

        $stored = DB::table('weather_hourly_observations')->where('point', 'se02_blakliden')->orderBy('timestamp')->pluck('utc_datetime')->all();
        $this->assertSame(['2023-12-31 00:00:00', '2024-01-01 00:00:00', '2025-01-01 00:00:00'], $stored);
        $this->assertSame(0, DB::table('weather_hourly_observations')->where('point', 'dk01_vesterhav')->count());
        // Vesterhav stops after its first failed piece (3 tries); Blakliden needs three pieces.
        Http::assertSentCount(6);
        // The daily observed backfill is not run for these points.
        $this->assertSame(0, DB::table('weather_daily_observations')->count());
    }

    public function test_default_run_includes_the_hourly_window_but_not_the_solar_forecast(): void
    {
        config([
            'open_meteo.points' => ['helsinki' => config('open_meteo.points.helsinki')],
            'open_meteo.neighbour_wind_points' => ['se03_hajsberget' => config('open_meteo.neighbour_wind_points.se03_hajsberget')],
        ]);
        $daily = ['time' => ['2026-10-25']];
        $dailyUnits = ['time' => 'iso8601'];
        foreach (config('open_meteo.observed_variables') as $variable) {
            $daily[$variable] = [1.0];
            $dailyUnits[$variable] = config('open_meteo.units')[$variable];
        }
        $ensemble = ['time' => ['2026-11-01']];
        $ensembleUnits = ['time' => 'iso8601'];
        foreach (config('open_meteo.outlooks.ecmwf_ens.variables') as $variable) {
            $ensemble[$variable] = [1.0];
            $ensembleUnits[$variable] = config('open_meteo.units')[$variable];
        }

        Http::fake([
            'archive-api.open-meteo.com/*' => function ($request) use ($daily, $dailyUnits) {
                if (str_contains($request->url(), 'hourly=')) {
                    return Http::response($this->hourlyPayload($this->hours('2026-10-25 00:00', 1), ['wind_speed_10m' => [3.0], 'wind_speed_100m' => [5.0]]));
                }

                return Http::response(['latitude' => 60.25, 'longitude' => 25.0, 'daily_units' => $dailyUnits, 'daily' => $daily]);
            },
            'ensemble-api.open-meteo.com/data/*' => Http::response('not found', 404),
            'ensemble-api.open-meteo.com/v1/*' => Http::response(['latitude' => 60.25, 'longitude' => 25.0, 'daily_units' => $ensembleUnits, 'daily' => $ensemble]),
            'api.open-meteo.com/*' => Http::response('unexpected', 500),
        ]);

        $this->artisan('weather:fetch')->assertExitCode(0);

        $this->assertSame(1, DB::table('weather_daily_observations')->count());
        $this->assertSame('se03_hajsberget', DB::table('weather_hourly_observations')->value('point'));
        $this->assertSame(1, DB::table('weather_outlook_runs')->count());
        $this->assertSame(0, DB::table('hourly_forecast_runs')->count());
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://api.open-meteo.com/'));
    }

    public function test_solar_forecast_vintage_is_shifted_to_the_hour_start_and_deduplicated(): void
    {
        $times = $this->hours('2026-11-01 00:00', 240);
        $ghi = array_map(fn ($i) => (float) ($i % 24), range(0, 239));
        $changed = $ghi;
        $changed[12] = 99.0;

        Http::fake([
            'api.open-meteo.com/data/*' => Http::response(['last_run_initialisation_time' => 1793491200]), // 2026-11-01 00:00Z
            'api.open-meteo.com/v1/*' => Http::sequence()
                ->push($this->hourlyPayload($times, ['shortwave_radiation' => $ghi]))
                ->push($this->hourlyPayload($times, ['shortwave_radiation' => $ghi]))
                ->push($this->hourlyPayload($times, ['shortwave_radiation' => $changed])),
        ]);

        $this->artisan('weather:fetch --only=solar_ghi --point=101004')->assertExitCode(0);
        $this->artisan('weather:fetch --only=solar_ghi --point=101004')->assertExitCode(0);
        $this->assertSame(1, DB::table('hourly_forecast_runs')->count());

        $this->artisan('weather:fetch --only=solar_ghi --point=101004')->assertExitCode(0);
        $runs = DB::table('hourly_forecast_runs')->orderBy('id')->get();
        $this->assertCount(2, $runs);

        $run = $runs[0];
        $this->assertSame('open_meteo', $run->source);
        $this->assertSame('solar_ghi', $run->product);
        $this->assertSame('101004', $run->point);
        $this->assertSame('2026-11-01 00:00:00', $run->issued_at);
        // The API value at 00:00Z is the mean of 23:00-00:00, so index 0 is the 23:00 hour.
        $this->assertSame($times[0] - 3600, (int) $run->first_timestamp);
        $this->assertSame('2026-10-31 23:00:00', $run->first_utc_datetime);
        $this->assertSame(240, (int) $run->hour_count);
        $this->assertEqualsWithDelta(58.875, $run->grid_latitude, 0.0001);
        $values = json_decode($run->values, true);
        $this->assertSame(['shortwave_radiation'], array_keys($values));
        $this->assertEqualsWithDelta(12.0, $values['shortwave_radiation'][12], 0.0001);
        $this->assertSame(hash('sha256', json_encode([$times[0] - 3600, $values])), $run->payload_hash);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'latitude=60.20307')
            && str_contains($request->url(), 'models=ecmwf_ifs')
            && str_contains($request->url(), 'hourly=shortwave_radiation')
            && str_contains($request->url(), 'forecast_days=10')
            && str_contains($request->url(), 'timezone=GMT')
            && str_contains($request->url(), 'timeformat=unixtime'));
    }

    public function test_issued_at_is_null_when_the_model_run_changes_during_the_fetch(): void
    {
        $times = $this->hours('2026-11-01 00:00', 24);
        Http::fake([
            'api.open-meteo.com/data/*' => Http::sequence()
                ->push(['last_run_initialisation_time' => 1793469600])
                ->push(['last_run_initialisation_time' => 1793491200]),
            'api.open-meteo.com/v1/*' => Http::response($this->hourlyPayload($times, ['shortwave_radiation' => array_fill(0, 24, 0.0)])),
        ]);

        $this->artisan('weather:fetch --only=solar_ghi --point=100908 --point=102035')->assertExitCode(0);

        $this->assertSame(2, DB::table('hourly_forecast_runs')->count());
        $this->assertSame(0, DB::table('hourly_forecast_runs')->whereNotNull('issued_at')->count());
    }

    public function test_a_wrong_unit_or_a_non_utc_response_fails_the_point(): void
    {
        $times = $this->hours('2026-11-01 00:00', 24);
        Http::fake([
            'api.open-meteo.com/data/*' => Http::response('not found', 404),
            'api.open-meteo.com/v1/*' => Http::sequence()
                ->push($this->hourlyPayload($times, ['shortwave_radiation' => array_fill(0, 24, 1.0)], ['shortwave_radiation' => 'MJ/m²']))
                ->push($this->hourlyPayload($times, ['shortwave_radiation' => array_fill(0, 24, 1.0)], [], 7200))
                ->push($this->hourlyPayload($times, ['shortwave_radiation' => array_fill(0, 24, 1.0)])),
        ]);

        $this->artisan('weather:fetch --only=solar_ghi --point=100908 --point=100968 --point=101104')->assertExitCode(1);

        $this->assertSame(['101104'], DB::table('hourly_forecast_runs')->pluck('point')->all());
        $this->assertNull(DB::table('hourly_forecast_runs')->value('issued_at'));
    }

    public function test_points_must_belong_to_a_selected_part(): void
    {
        Http::fake();

        $this->artisan('weather:fetch --only=observed_hourly --point=helsinki')->assertExitCode(1);
        $this->artisan('weather:fetch --only=solar_ghi --point=ee01_hiiumaa')->assertExitCode(1);
        $this->artisan('weather:fetch --from=2023-01-01 --only=solar_ghi')->assertExitCode(1);
        $this->artisan('weather:fetch --point=999999')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_single_run_product_stores_the_00_utc_run_of_the_day_with_its_run_time(): void
    {
        $times = $this->hours('2026-11-01 00:00', 216);
        $series = [
            'temperature_2m' => array_fill(0, 216, 3.5),
            'wind_speed_10m' => array_fill(0, 216, 6.0),
            'wind_speed_100m' => array_fill(0, 216, 9.5),
            'shortwave_radiation' => array_fill(0, 216, 0.0),
        ];
        Http::fake(['single-runs-api.open-meteo.com/*' => Http::response($this->hourlyPayload($times, $series))]);

        $this->artisan('weather:fetch --only=ecmwf_00z --point=101004 --point=ee01_hiiumaa')->assertExitCode(0);

        $rows = DB::table('hourly_forecast_runs')->orderBy('point')->get();
        $this->assertSame(['101004', 'ee01_hiiumaa'], $rows->pluck('point')->all());
        $this->assertSame(['open_meteo'], $rows->pluck('source')->unique()->values()->all());
        $this->assertSame(['ecmwf_00z'], $rows->pluck('product')->unique()->values()->all());
        $this->assertSame('2026-11-01 00:00:00', $rows[0]->issued_at);
        // No shift: the training data keeps every value at the API time.
        $this->assertSame($times[0], (int) $rows[0]->first_timestamp);
        $this->assertSame(216, (int) $rows[0]->hour_count);
        $this->assertSame(['temperature_2m', 'wind_speed_10m', 'wind_speed_100m', 'shortwave_radiation'], array_keys(json_decode($rows[0]->values, true)));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'latitude=60.20307')
            && str_contains($request->url(), 'run=2026-11-01T00%3A00')
            && str_contains($request->url(), 'models=ecmwf_ifs')
            && str_contains($request->url(), 'forecast_days=9')
            && str_contains($request->url(), 'hourly=temperature_2m%2Cwind_speed_10m%2Cwind_speed_100m%2Cshortwave_radiation'));
        // No metadata request: the run time is the requested run.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'meta.json'));
        Http::assertSentCount(2);

        // A later tick sends nothing for points already stored for this run.
        $this->artisan('weather:fetch --only=ecmwf_00z --point=101004 --point=ee01_hiiumaa')->assertExitCode(0);
        Http::assertSentCount(2);
    }

    public function test_single_run_not_available_yet_is_a_quiet_wait(): void
    {
        Http::fake(['single-runs-api.open-meteo.com/*' => Http::response(
            ['error' => true, 'reason' => 'The requested model run is not available. Model: ecmwf_ifs, run: 2026-11-01T00:00Z'], 400)]);

        $this->artisan('weather:fetch --only=ecmwf_00z --point=101004 --point=ee01_hiiumaa')->assertExitCode(0);

        $this->assertSame(0, DB::table('hourly_forecast_runs')->count());
        // The first point answers "not available", so the others are not requested.
        Http::assertSentCount(1);
    }

    public function test_single_run_other_errors_fail_the_point_and_the_rest_continue(): void
    {
        $times = $this->hours('2026-11-01 00:00', 216);
        $series = array_fill_keys(['temperature_2m', 'wind_speed_10m', 'wind_speed_100m', 'shortwave_radiation'], array_fill(0, 216, 1.0));
        Http::fake(function ($request) use ($times, $series) {
            return str_contains($request->url(), 'latitude=60.20307')
                ? Http::response(['error' => true, 'reason' => 'Parameter hourly is invalid'], 400)
                : Http::response($this->hourlyPayload($times, $series));
        });

        $this->artisan('weather:fetch --only=ecmwf_00z --point=101004 --point=ee01_hiiumaa')->assertExitCode(1);

        $this->assertSame(['ee01_hiiumaa'], DB::table('hourly_forecast_runs')->pluck('point')->all());
    }

    public function test_single_run_product_covers_the_38_training_points(): void
    {
        Http::fake(['single-runs-api.open-meteo.com/*' => Http::response(
            ['error' => true, 'reason' => 'The requested model run is not available.'], 400)]);

        // Every FMI station and neighbour point key is accepted for the part.
        $points = [...array_map('strval', array_keys(config('fmi.weather_stations'))), ...array_keys(config('open_meteo.neighbour_wind_points'))];
        $this->assertCount(38, $points);
        $options = implode(' ', array_map(fn ($point) => "--point={$point}", $points));
        $this->artisan("weather:fetch --only=ecmwf_00z {$options}")->assertExitCode(0);
    }
}
