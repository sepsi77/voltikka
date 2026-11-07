<?php

namespace Tests\Feature;

use App\Models\DataFreshnessCheckpoint;
use App\Services\Orchestration\SForecastReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrchestrationReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['orchestration.s_forecast.inputs' => [
            ['key' => 'day_ahead_d1', 'view' => 'afternoon', 'type' => 'day_complete', 'day_offset' => 1,
                'table' => 'spot_prices_hour', 'key_column' => 'region', 'keys' => ['FI', 'EE']],
            ['key' => 'jao_capacity_d', 'view' => 'morning', 'type' => 'day_complete', 'day_offset' => 0,
                'table' => 'jao_max_border_flows', 'key_column' => 'border', 'keys' => ['SE1_FI']],
            ['key' => 'fingrid_morning', 'view' => 'morning', 'type' => 'fingrid_through', 'datasets' => [74, 75], 'through_local' => '09:00'],
            ['key' => 'weather_ecmwf_00z', 'view' => 'afternoon', 'type' => 'forecast_run', 'source' => 'open_meteo',
                'product' => 'ecmwf_00z', 'run_hour_utc' => 0, 'points_from' => ['test.stations', 'test.neighbours']],
            ['key' => 'eex_futures', 'view' => 'morning', 'type' => 'checkpoint_ready', 'checkpoint' => 'eex_futures'],
        ]]);
        config(['test.stations' => [101004 => ['name' => 'a']], 'test.neighbours' => ['se01' => ['latitude' => 1]]]);
    }

    public function test_day_ahead_requires_every_helsinki_hour_of_the_next_day_including_dst(): void
    {
        // D = 2026-10-24; D+1 = 2026-10-25 has 25 Helsinki hours (DST ends).
        $start = CarbonImmutable::parse('2026-10-25 00:00', 'Europe/Helsinki')->utc();
        $this->spotHours('FI', $start, 25);
        $this->spotHours('EE', $start, 24);

        $input = $this->input('2026-10-24', 'afternoon', 'day_ahead_d1');
        $this->assertFalse($input['ready']);
        $this->assertSame('incomplete 2026-10-25: EE 24/25', $input['detail']);

        $this->spotHours('EE', $start->addHours(24), 1);
        $this->assertTrue($this->input('2026-10-24', 'afternoon', 'day_ahead_d1')['ready']);
    }

    public function test_fingrid_requires_each_dataset_at_the_local_hour(): void
    {
        // 09:00 Helsinki on 2026-11-05 = 07:00 UTC.
        $hour = CarbonImmutable::parse('2026-11-05 07:00', 'UTC');
        $this->fingridRow(74, $hour);
        $this->fingridRow(75, $hour->subHour());

        $input = $this->input('2026-11-05', 'morning', 'fingrid_morning');
        $this->assertFalse($input['ready']);
        $this->assertSame('missing datasets at 09:00: 75', $input['detail']);

        $this->fingridRow(75, $hour);
        $this->assertTrue($this->input('2026-11-05', 'morning', 'fingrid_morning')['ready']);
    }

    public function test_jao_capacity_requires_every_hour_of_the_day(): void
    {
        $start = CarbonImmutable::parse('2026-11-05 00:00', 'Europe/Helsinki')->utc();
        for ($i = 0; $i < 23; $i++) {
            $this->jaoRow('SE1_FI', $start->addHours($i));
        }
        $this->assertSame('incomplete 2026-11-05: SE1_FI 23/24', $this->input('2026-11-05', 'morning', 'jao_capacity_d')['detail']);

        $this->jaoRow('SE1_FI', $start->addHours(23));
        $this->assertTrue($this->input('2026-11-05', 'morning', 'jao_capacity_d')['ready']);
    }

    public function test_weather_needs_the_exact_00z_run_of_d_at_every_configured_point(): void
    {
        $this->forecastRun('open_meteo', 'ecmwf_00z', '101004', '2026-11-05 00:00:00', '2026-11-05 07:15:00');
        $this->forecastRun('open_meteo', 'ecmwf_00z', 'se01', '2026-11-04 00:00:00', '2026-11-04 07:15:00'); // previous day
        $this->forecastRun('open_meteo', 'ecmwf_00z', 'se01', '2026-11-05 12:00:00', '2026-11-05 19:15:00', '12z');

        $input = $this->input('2026-11-05', 'afternoon', 'weather_ecmwf_00z');
        $this->assertFalse($input['ready']);
        $this->assertSame('2026-11-05 00:00 UTC run: 1 of 2 points missing: se01', $input['detail']);

        $this->forecastRun('open_meteo', 'ecmwf_00z', 'se01', '2026-11-05 00:00:00', '2026-11-05 13:15:00', 'late');
        $this->assertTrue($this->input('2026-11-05', 'afternoon', 'weather_ecmwf_00z')['ready']);
    }

    public function test_checkpoint_must_be_ready_before_the_cut(): void
    {
        // Morning cut 11:30 Helsinki = 09:30 UTC; Laravel writes updated_at in the app time zone.
        $at = fn (string $utc) => CarbonImmutable::parse($utc, 'UTC')->setTimezone((string) config('app.timezone'));

        CarbonImmutable::setTestNow($at('2026-11-05 02:00:00'));
        $checkpoint = DataFreshnessCheckpoint::query()->create([
            'key' => 'eex_futures', 'effective_date' => '2026-11-05', 'status' => 'running',
            'metadata' => [], 'recorded_at' => CarbonImmutable::now('Europe/Helsinki'),
        ]);
        $this->assertSame('checkpoint status running', $this->input('2026-11-05', 'morning', 'eex_futures')['detail']);

        CarbonImmutable::setTestNow($at('2026-11-05 09:31:00'));
        $checkpoint->update(['status' => 'ready']);
        $this->assertSame('checkpoint ready only after the cut', $this->input('2026-11-05', 'morning', 'eex_futures')['detail']);

        CarbonImmutable::setTestNow($at('2026-11-05 03:43:00'));
        $checkpoint->update(['status' => 'ready', 'metadata' => ['rewritten' => true]]);
        CarbonImmutable::setTestNow();
        $this->assertTrue($this->input('2026-11-05', 'morning', 'eex_futures')['ready']);
    }

    public function test_command_prints_one_json_line_and_separates_not_ready_from_errors(): void
    {
        $this->assertSame(0, Artisan::call('orchestration:readiness', [
            'pipeline' => 's-forecast', '--issue-date' => '2026-11-05', '--view' => 'morning',
        ]));
        $result = json_decode(trim(Artisan::output()), true);
        $this->assertFalse($result['ready']);
        $this->assertSame('2026-11-05T09:30:00Z', $result['cut_utc']);
        $this->assertSame(['jao_capacity_d', 'fingrid_morning', 'eex_futures'], array_column($result['inputs'], 'key'));

        $this->assertSame(1, Artisan::call('orchestration:readiness', [
            'pipeline' => 's-forecast', '--issue-date' => '2026-11-05', '--view' => 'evening',
        ]));
        $this->assertSame(1, Artisan::call('orchestration:readiness', [
            'pipeline' => 's-forecast', '--issue-date' => '2026-02-30', '--view' => 'morning',
        ]));
        $this->assertSame(1, Artisan::call('orchestration:readiness', ['pipeline' => 'other', '--issue-date' => '2026-11-05', '--view' => 'morning']));
    }

    /** @return array{key: string, ready: bool, detail: string} */
    private function input(string $date, string $view, string $key): array
    {
        $inputs = app(SForecastReadiness::class)->check($date, $view)['inputs'];

        return collect($inputs)->firstWhere('key', $key);
    }

    private function spotHours(string $region, CarbonImmutable $start, int $hours): void
    {
        for ($i = 0; $i < $hours; $i++) {
            $hour = $start->addHours($i);
            DB::table('spot_prices_hour')->insert([
                'region' => $region, 'timestamp' => $hour->getTimestamp(),
                'utc_datetime' => $hour->format('Y-m-d H:i:s'), 'price_without_tax' => 1.0, 'vat_rate' => 0,
            ]);
        }
    }

    private function jaoRow(string $border, CarbonImmutable $hour): void
    {
        DB::table('jao_max_border_flows')->insert([
            'border' => $border, 'timestamp' => $hour->getTimestamp(), 'utc_datetime' => $hour->format('Y-m-d H:i:s'),
            'capacity_mw' => 1.0, 'min_capacity_mw' => 1.0, 'sample_count' => 4,
            'fetched_at' => '2026-11-04 10:40:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fingridRow(int $datasetId, CarbonImmutable $hour): void
    {
        DB::table('fingrid_hourly_values')->insert([
            'dataset_id' => $datasetId, 'series_key' => '', 'timestamp' => $hour->getTimestamp(),
            'utc_datetime' => $hour->format('Y-m-d H:i:s'), 'value' => 1.0, 'sample_count' => 20,
            'fetched_at' => '2026-11-05 08:20:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function forecastRun(string $source, string $product, string $point, ?string $issuedAt, string $fetchedAt, string $hash = 'x'): void
    {
        DB::table('hourly_forecast_runs')->insert([
            'source' => $source, 'product' => $product, 'point' => $point,
            'payload_hash' => hash('sha256', $hash.$point.$fetchedAt), 'issued_at' => $issuedAt,
            'fetched_at' => $fetchedAt, 'first_timestamp' => 0, 'first_utc_datetime' => '2026-11-05 00:00:00',
            'hour_count' => 1, 'values' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
