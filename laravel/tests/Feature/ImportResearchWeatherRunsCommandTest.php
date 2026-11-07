<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportResearchWeatherRunsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/voltikka-import-test-'.bin2hex(random_bytes(4));
        File::makeDirectory($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @return array<string, array<string, list<float|int>>> */
    private function runFile(int $points = 38, int $first = 1793750400): array
    {
        // Each run starts at its own run time, as the real answers do.
        $times = [$first, $first + 3600, $first + 7200];
        $block = ['time' => $times, 'temperature_2m' => [1.5, 2.0, 2.5], 'wind_speed_10m' => [5.0, 5.5, 6.0],
            'wind_speed_100m' => [8.0, 8.5, 9.0], 'shortwave_radiation' => [0.0, 0, 12.5]];
        $keys = [...array_map(fn ($id) => "fmi_{$id}", array_keys(config('fmi.weather_stations'))),
            ...array_keys(config('open_meteo.neighbour_wind_points'))];

        return array_fill_keys(array_slice($keys, 0, $points), $block);
    }

    public function test_single_runs_dry_run_then_apply_with_the_run_time_and_no_duplicates(): void
    {
        File::put("{$this->dir}/2026-10-30.json", json_encode($this->runFile()));
        File::put("{$this->dir}/2026-10-31.json", json_encode($this->runFile(38, 1793836800)));

        $this->artisan("weather:import-research-runs ecmwf_00z {$this->dir}")
            ->expectsOutputToContain('76 payloads from 2 files, 2 runs 2026-10-30 00:00:00 .. 2026-10-31 00:00:00')
            ->assertExitCode(0);
        $this->assertSame(0, DB::table('hourly_forecast_runs')->count());

        $this->artisan("weather:import-research-runs ecmwf_00z {$this->dir} --apply")->assertExitCode(0);
        $rows = DB::table('hourly_forecast_runs')->where('product', 'ecmwf_00z')->get();
        $this->assertCount(76, $rows);
        $this->assertSame(['2026-10-30 00:00:00', '2026-10-31 00:00:00'], $rows->pluck('issued_at')->unique()->sort()->values()->all());
        // Point keys as the live collector stores them (FMISID strings, neighbour keys).
        $this->assertContains('101004', $rows->pluck('point')->all());
        $this->assertContains('ee01_hiiumaa', $rows->pluck('point')->all());
        $this->assertSame(1793750400, (int) $rows[0]->first_timestamp);
        $this->assertEquals([1.5, 2.0, 2.5], json_decode($rows[0]->values, true)['temperature_2m']);

        $this->artisan("weather:import-research-runs ecmwf_00z {$this->dir} --apply")->assertExitCode(0);
        $this->assertSame(76, DB::table('hourly_forecast_runs')->count());
    }

    public function test_a_run_file_without_all_38_points_stops_the_import(): void
    {
        File::put("{$this->dir}/2026-10-30.json", json_encode($this->runFile(37)));

        $this->artisan("weather:import-research-runs ecmwf_00z {$this->dir} --apply")->assertExitCode(1);
        $this->assertSame(0, DB::table('hourly_forecast_runs')->count());
    }

    public function test_previous_runs_store_only_the_lead_one_temperature_of_the_southern_stations(): void
    {
        $answer = ['utc_offset_seconds' => 0, 'latitude' => 60.25, 'longitude' => 25.0,
            'hourly_units' => ['time' => 'unixtime', 'temperature_2m_previous_day1' => '°C', 'wind_speed_10m_previous_day1' => 'm/s'],
            'hourly' => ['time' => [1793750400, 1793754000], 'temperature_2m_previous_day1' => [3.1, 3.4],
                'wind_speed_10m_previous_day1' => [4.0, 4.2]]];
        File::put("{$this->dir}/fmi_101004_2026.json", json_encode($answer));
        File::put("{$this->dir}/fmi_101986_2026.json", json_encode($answer)); // not a southern station

        $this->artisan("weather:import-research-runs ecmwf_prev_day1 {$this->dir} --apply")->assertExitCode(0);

        $row = DB::table('hourly_forecast_runs')->sole();
        $this->assertSame(['101004', 'ecmwf_prev_day1'], [$row->point, $row->product]);
        $this->assertNull($row->issued_at);
        $this->assertSame(['temperature_2m_previous_day1'], array_keys(json_decode($row->values, true)));
    }

    public function test_other_products_are_rejected(): void
    {
        $this->artisan("weather:import-research-runs solar_ghi {$this->dir}")->assertExitCode(1);
    }
}
