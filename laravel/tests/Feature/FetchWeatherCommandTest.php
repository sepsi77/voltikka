<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchWeatherCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-01 01:30 Helsinki: yesterday is 2026-10-31 local, although UTC is still 10-31.
        Carbon::setTestNow(Carbon::create(2026, 10, 31, 23, 30, 0, 'UTC'));
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
     * @param  list<string>  $dates
     * @param  array<string, list<float|null>>  $overrides
     */
    private function archivePayload(array $dates, array $overrides = []): array
    {
        $daily = ['time' => $dates];
        $units = ['time' => 'iso8601'];
        foreach (config('open_meteo.observed_variables') as $variable) {
            $daily[$variable] = $overrides[$variable] ?? array_fill(0, count($dates), 1.5);
            $units[$variable] = config('open_meteo.units')[$variable];
        }

        return ['latitude' => 60.25, 'longitude' => 25.0, 'daily_units' => $units, 'daily' => $daily];
    }

    private function outlookPayload(string $productKey, int $days, int $members, float $base = 2.0): array
    {
        $daily = ['time' => array_map(fn ($d) => Carbon::create(2026, 11, 1)->addDays($d)->format('Y-m-d'), range(0, $days - 1))];
        $units = ['time' => 'iso8601'];
        foreach (config("open_meteo.outlooks.{$productKey}.variables") as $variable) {
            for ($m = 0; $m <= $members; $m++) {
                $key = $m === 0 ? $variable : sprintf('%s_member%02d', $variable, $m);
                $daily[$key] = array_map(fn ($d) => $base + $m + $d / 10, range(0, $days - 1));
                $units[$key] = config('open_meteo.units')[$variable];
            }
        }

        return ['latitude' => 60.25, 'longitude' => 25.0, 'daily_units' => $units, 'daily' => $daily];
    }

    public function test_daily_window_upserts_observed_days_and_skips_days_without_data(): void
    {
        $nulls = array_fill_keys(config('open_meteo.observed_variables'), [3.0, null]);
        $nulls['temperature_2m_mean'] = [-4.2, null];

        Http::fake([
            'archive-api.open-meteo.com/*' => Http::sequence()
                ->push($this->archivePayload(['2026-10-25', '2026-10-26'], $nulls))
                ->push($this->archivePayload(['2026-10-25', '2026-10-26'], ['temperature_2m_mean' => [-4.0, 1.0]])),
        ]);

        $this->artisan('weather:fetch --only=observed --point=helsinki')->assertExitCode(0);

        $rows = DB::table('weather_daily_observations')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('helsinki', $rows[0]->point);
        $this->assertSame('era5', $rows[0]->model);
        $this->assertSame('2026-10-25', $rows[0]->date);
        $this->assertEqualsWithDelta(-4.2, $rows[0]->temperature_2m_mean, 0.0001);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'start_date=2026-10-11')
            && str_contains($request->url(), 'end_date=2026-10-31')
            && str_contains($request->url(), 'models=era5')
            && str_contains($request->url(), 'wind_speed_unit=ms')
            && str_contains($request->url(), 'timezone=Europe%2FHelsinki'));

        // A rerun replaces revised values and adds the newly available day.
        $this->artisan('weather:fetch --only=observed --point=helsinki')->assertExitCode(0);
        $this->assertSame(2, DB::table('weather_daily_observations')->count());
        $this->assertEqualsWithDelta(-4.0, DB::table('weather_daily_observations')->where('date', '2026-10-25')->value('temperature_2m_mean'), 0.0001);
    }

    public function test_backfill_uses_calendar_year_pieces_and_a_failed_point_does_not_stop_the_others(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'latitude=60.17')) {
                return Http::response(['error' => true, 'reason' => 'boom'], 500);
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response($this->archivePayload([$query['start_date'], $query['end_date']]));
        });

        $this->artisan('weather:fetch --from=1991-12-30 --to=1993-01-02 --point=helsinki --point=oulu')
            ->assertExitCode(1);

        $oulu = DB::table('weather_daily_observations')->where('point', 'oulu')->orderBy('date')->pluck('date')->all();
        $this->assertSame(['1991-12-30', '1991-12-31', '1992-01-01', '1992-12-31', '1993-01-01', '1993-01-02'], $oulu);
        $this->assertSame(0, DB::table('weather_daily_observations')->where('point', 'helsinki')->count());
        // Helsinki stops after its first failed piece (3 tries); Oulu needs three pieces.
        Http::assertSentCount(6);
    }

    public function test_outlook_runs_are_append_only_and_deduplicated(): void
    {
        Http::fake([
            'ensemble-api.open-meteo.com/data/*' => Http::response(['last_run_initialisation_time' => 1793469600]),
            'ensemble-api.open-meteo.com/v1/*' => Http::sequence()
                ->push($this->outlookPayload('ecmwf_ens', 15, 50))
                ->push($this->outlookPayload('ecmwf_ens', 15, 50))
                ->push($this->outlookPayload('ecmwf_ens', 15, 50, 3.0)),
        ]);

        $this->artisan('weather:fetch --only=ecmwf_ens --point=tampere')->assertExitCode(0);
        $this->artisan('weather:fetch --only=ecmwf_ens --point=tampere')->assertExitCode(0);
        $this->assertSame(1, DB::table('weather_outlook_runs')->count());

        $this->artisan('weather:fetch --only=ecmwf_ens --point=tampere')->assertExitCode(0);
        $runs = DB::table('weather_outlook_runs')->orderBy('id')->get();
        $this->assertCount(2, $runs);

        $run = $runs[0];
        $this->assertSame('ecmwf_ens', $run->product);
        $this->assertSame('ecmwf_ifs025', $run->model);
        $this->assertSame('2026-10-31 18:00:00', $run->model_run_at);
        $this->assertSame('2026-11-01', $run->first_date);
        $this->assertSame(15, (int) $run->day_count);
        $this->assertSame(51, (int) $run->member_count);

        $members = json_decode($run->members, true);
        $this->assertSame(config('open_meteo.outlooks.ecmwf_ens.variables'), array_keys($members));
        $this->assertCount(51, $members['temperature_2m_mean']);
        $this->assertEqualsWithDelta(2.0, $members['temperature_2m_mean'][0][0], 0.0001); // control
        $this->assertEqualsWithDelta(52.1, $members['temperature_2m_mean'][50][1], 0.0001); // member50, day 2
    }

    public function test_default_run_fetches_observed_and_the_ensemble_but_not_seasonal(): void
    {
        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response($this->archivePayload(['2026-10-25'])),
            'ensemble-api.open-meteo.com/data/*' => Http::response('not found', 404),
            'ensemble-api.open-meteo.com/v1/*' => Http::response($this->outlookPayload('ecmwf_ens', 15, 2)),
            'seasonal-api.open-meteo.com/*' => Http::response('unexpected', 500),
        ]);

        $this->artisan('weather:fetch --point=kuopio')->assertExitCode(0);

        $this->assertSame(1, DB::table('weather_daily_observations')->count());
        $run = DB::table('weather_outlook_runs')->first();
        $this->assertSame('ecmwf_ens', $run->product);
        $this->assertNull($run->model_run_at); // metadata is best effort
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'seasonal-api'));
    }

    public function test_a_wrong_unit_fails_instead_of_storing_km_per_hour(): void
    {
        $payload = $this->archivePayload(['2026-10-25']);
        $payload['daily_units']['wind_speed_10m_mean'] = 'km/h';
        Http::fake(['archive-api.open-meteo.com/*' => Http::response($payload)]);

        $this->artisan('weather:fetch --only=observed --point=turku')->assertExitCode(1);
        $this->assertSame(0, DB::table('weather_daily_observations')->count());
    }

    public function test_invalid_options_are_rejected(): void
    {
        Http::fake();

        $this->artisan('weather:fetch --point=nowhere')->assertExitCode(1);
        $this->artisan('weather:fetch --only=rain')->assertExitCode(1);
        $this->artisan('weather:fetch --from=2026-01-01 --only=ecmwf_ens')->assertExitCode(1);
        $this->artisan('weather:fetch --from=1939-12-31')->assertExitCode(1);
        Http::assertNothingSent();
    }
}
