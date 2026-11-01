<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchNveReservoirsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const WEEKS_URL = 'biapi.nve.no/magasinstatistikk/api/Magasinstatistikk/HentOffentligData';

    private const REFERENCES_URL = 'biapi.nve.no/magasinstatistikk/api/Magasinstatistikk/HentOffentligDataMinMaxMedian';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 7, 30, 0, 'UTC'));
        config([
            'nve.request_delay_seconds' => 0,
            'nve.retry_sleep_ms' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Shape of a real HentOffentligData row (NO total, week 43 of 2026: 69.5 %). */
    private function week(int $year, float $ratio, string $type = 'NO', int $no = 0): array
    {
        return [
            'dato_Id' => "{$year}-10-25",
            'omrType' => $type,
            'omrnr' => $no,
            'iso_aar' => $year,
            'iso_uke' => 43,
            'fyllingsgrad' => $ratio,
            'kapasitet_TWh' => 87.43758,
            'fylling_TWh' => round($ratio * 87.43758, 6),
            'neste_Publiseringsdato' => '2026-11-04T13:00:00',
            'fyllingsgrad_forrige_uke' => $ratio - 0.0028,
            'endring_fyllingsgrad' => 0.0028,
        ];
    }

    /** History 2004-2026 for NO week 43, a VASS row that must be ignored. */
    private function history(): array
    {
        $rows = [];
        foreach (range(2004, 2025) as $year) {
            $rows[] = $this->week($year, 0.60 + ($year - 2004) * 0.01);
        }
        $rows[] = $this->week(2026, 0.69537306);
        $rows[] = $this->week(2026, 0.6404667, 'VASS', 1);

        return $rows;
    }

    /** Reference for 2006-2025: min 0.62, median (0.71 + 0.72) / 2, max 0.81. */
    private function references(float $median = 0.715): array
    {
        return [
            ['omrType' => 'NO', 'omrnr' => 0, 'iso_uke' => 43, 'minFyllingsgrad' => 0.62, 'minFyllingTWH' => 54.2, 'medianFyllingsGrad' => $median, 'medianFylling_TWH' => 62.5, 'maxFyllingsgrad' => 0.81, 'maxFyllingTWH' => 70.8],
            ['omrType' => 'NO', 'omrnr' => 0, 'iso_uke' => 53, 'minFyllingsgrad' => 0.43962312, 'minFyllingTWH' => 38.439583, 'medianFyllingsGrad' => 0.6715162, 'medianFylling_TWH' => 58.71575, 'maxFyllingsgrad' => 0.823581, 'maxFyllingTWH' => 72.01193],
            ['omrType' => 'VASS', 'omrnr' => 1, 'iso_uke' => 43, 'minFyllingsgrad' => 0.5, 'minFyllingTWH' => 1.0, 'medianFyllingsGrad' => 0.6, 'medianFylling_TWH' => 1.0, 'maxFyllingsgrad' => 0.7, 'maxFyllingTWH' => 1.0],
        ];
    }

    public function test_default_run_stores_recent_weeks_and_the_proved_reference(): void
    {
        Http::fake([
            self::REFERENCES_URL => Http::response($this->references()),
            self::WEEKS_URL => Http::response($this->history()),
        ]);

        $this->artisan('nve:fetch-reservoirs')->assertExitCode(0);

        $this->assertSame([2025, 2026], DB::table('nve_reservoir_weeks')->orderBy('iso_year')->pluck('iso_year')->map(fn ($y) => (int) $y)->all());
        $latest = DB::table('nve_reservoir_weeks')->where('iso_year', 2026)->first();
        $this->assertSame('NO', $latest->area_type);
        $this->assertSame(43, (int) $latest->iso_week);
        $this->assertSame('2026-10-25', $latest->week_end_date);
        $this->assertEqualsWithDelta(0.69537306, $latest->filling_ratio, 0.0000001);

        $references = DB::table('nve_reservoir_week_references')->orderBy('iso_week')->get();
        $this->assertCount(2, $references);
        $this->assertSame([2006, 2025], [(int) $references[0]->reference_from_year, (int) $references[0]->reference_to_year]);
        $this->assertEqualsWithDelta(0.715, $references[0]->median_ratio, 0.0000001);
        $this->assertSame(53, (int) $references[1]->iso_week);
    }

    public function test_reference_is_not_stored_when_its_period_cannot_be_proved(): void
    {
        Http::fake([
            self::REFERENCES_URL => Http::response($this->references(0.70)),
            self::WEEKS_URL => Http::response($this->history()),
        ]);

        $this->artisan('nve:fetch-reservoirs --all')->assertExitCode(1);

        $this->assertSame(23, DB::table('nve_reservoir_weeks')->count());
        $this->assertSame(0, DB::table('nve_reservoir_week_references')->count());
    }

    public function test_failed_history_fails_the_run_and_stores_no_reference(): void
    {
        Http::fake([
            self::REFERENCES_URL => Http::response($this->references()),
            self::WEEKS_URL => Http::response(['title' => 'boom'], 503),
        ]);

        $this->artisan('nve:fetch-reservoirs')->assertExitCode(1);

        $this->assertSame(0, DB::table('nve_reservoir_weeks')->count());
        $this->assertSame(0, DB::table('nve_reservoir_week_references')->count());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'HentOffentligDataMinMaxMedian'));
    }

    public function test_rerun_replaces_a_revised_week(): void
    {
        $revised = $this->history();
        $revised[22] = $this->week(2026, 0.70);

        Http::fakeSequence(self::WEEKS_URL)->push($this->history())->push($revised);
        Http::fake([self::REFERENCES_URL => Http::response($this->references())]);

        $this->artisan('nve:fetch-reservoirs --from-year=2026')->assertExitCode(0);
        $this->artisan('nve:fetch-reservoirs --from-year=2026')->assertExitCode(0);

        $this->assertSame(1, DB::table('nve_reservoir_weeks')->count());
        $this->assertEqualsWithDelta(0.70, DB::table('nve_reservoir_weeks')->value('filling_ratio'), 0.0000001);
    }
}
