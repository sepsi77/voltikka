<?php

namespace Tests\Feature;

use App\Services\EntsoeService;
use App\Services\SpotPriceAverageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class RepairSpotHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 12, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Two hours of fresh 15-minute records starting 2026-10-15 00:00 UTC.
     * Hour 1: 10, 12, 12, 20 (mean 13.5). Hour 2: 8, 8, 8, 8.
     *
     * @return list<array<string, mixed>>
     */
    private function freshRecords(): array
    {
        $start = Carbon::parse('2026-10-15 00:00:00', 'UTC');

        return array_map(fn (int $i, float $price) => [
            'region' => 'FI',
            'timestamp' => $start->copy()->addMinutes(15 * $i)->timestamp,
            'utc_datetime' => $start->copy()->addMinutes(15 * $i),
            'price_without_tax' => $price,
            'resolution_minutes' => 15,
        ], range(0, 7), [10.0, 12.0, 12.0, 20.0, 8.0, 8.0, 8.0, 8.0]);
    }

    private function storeQuarter(string $utc, float $price): void
    {
        DB::table('spot_prices_quarter')->insert([
            'region' => 'FI',
            'timestamp' => Carbon::parse($utc, 'UTC')->timestamp,
            'utc_datetime' => $utc,
            'price_without_tax' => $price,
            'vat_rate' => 0.255,
        ]);
    }

    private function storeHour(string $utc, float $price): void
    {
        DB::table('spot_prices_hour')->insert([
            'region' => 'FI',
            'timestamp' => Carbon::parse($utc, 'UTC')->timestamp,
            'utc_datetime' => $utc,
            'price_without_tax' => $price,
            'vat_rate' => 0.255,
        ]);
    }

    /**
     * The old A03 state: quarter 3 of hour 1 and the whole flat hour 2 were omitted,
     * so hour 1 was a mean of 3 quarters (14.0) and hour 2 did not exist.
     */
    private function storeOldState(): void
    {
        $this->storeQuarter('2026-10-15 00:00:00', 10.0);
        $this->storeQuarter('2026-10-15 00:15:00', 12.0);
        $this->storeQuarter('2026-10-15 00:45:00', 20.0);
        $this->storeHour('2026-10-15 00:00:00', 14.0);
        // A stored row that the fresh fetch does not contain must survive.
        $this->storeHour('2026-10-20 05:00:00', 7.0);
        // A float rounding difference below the tolerance must not count as a change.
        $this->storeHour('2026-10-21 05:00:00', 5.12345);
    }

    private function mockEntsoe(array $records): void
    {
        $service = Mockery::mock(EntsoeService::class);
        $service->shouldReceive('fetchDayAheadPrices')->andReturn($records);
        $this->app->instance(EntsoeService::class, $service);
    }

    public function test_dry_run_reports_but_writes_nothing(): void
    {
        $this->storeOldState();
        $this->mockEntsoe($this->freshRecords());
        $averages = Mockery::mock(SpotPriceAverageService::class);
        $averages->shouldNotReceive('calculateAllAverages');
        $this->app->instance(SpotPriceAverageService::class, $averages);

        $this->artisan('spot:repair-history --from=2026-10 --to=2026-10')
            ->expectsOutputToContain('Total: 1 missing hours, 1 changed hours, 5 missing quarters, 0 changed quarters.')
            ->expectsOutputToContain('Dry run: nothing was written.')
            ->assertExitCode(0);

        $this->assertSame(3, DB::table('spot_prices_quarter')->count());
        $this->assertEqualsWithDelta(14.0, DB::table('spot_prices_hour')->where('utc_datetime', '2026-10-15 00:00:00')->value('price_without_tax'), 0.0001);
    }

    public function test_apply_inserts_missing_rows_corrects_means_and_keeps_other_rows(): void
    {
        $this->storeOldState();
        $fresh = $this->freshRecords();
        $fresh[] = [
            'region' => 'FI',
            'timestamp' => Carbon::parse('2026-10-21 05:00:00', 'UTC')->timestamp,
            'utc_datetime' => Carbon::parse('2026-10-21 05:00:00', 'UTC'),
            'price_without_tax' => 5.12348,
            'resolution_minutes' => 60,
        ];
        $this->mockEntsoe($fresh);
        $averages = Mockery::mock(SpotPriceAverageService::class);
        $averages->shouldReceive('calculateAllAverages')->once()->with('FI');
        $this->app->instance(SpotPriceAverageService::class, $averages);

        $this->artisan('spot:repair-history --from=2026-10 --to=2026-10 --apply')->assertExitCode(0);

        $this->assertSame(8, DB::table('spot_prices_quarter')->count());
        $hours = DB::table('spot_prices_hour')->orderBy('timestamp')->pluck('price_without_tax', 'utc_datetime');
        $this->assertEqualsWithDelta(13.5, $hours['2026-10-15 00:00:00'], 0.0001);
        $this->assertEqualsWithDelta(8.0, $hours['2026-10-15 01:00:00'], 0.0001);
        $this->assertEqualsWithDelta(7.0, $hours['2026-10-20 05:00:00'], 0.0001);
        $this->assertEqualsWithDelta(5.12345, $hours['2026-10-21 05:00:00'], 0.000001);
        $this->assertEqualsWithDelta(0.255, DB::table('spot_prices_quarter')->where('utc_datetime', '2026-10-15 00:30:00')->value('vat_rate'), 0.0001);

        // A second apply finds nothing to repair.
        $this->artisan('spot:repair-history --from=2026-10 --to=2026-10 --apply')
            ->expectsOutputToContain('Total: 0 missing hours, 0 changed hours, 0 missing quarters, 0 changed quarters.')
            ->assertExitCode(0);
    }

    public function test_rows_outside_the_helsinki_month_are_not_touched(): void
    {
        // 2026-09-30 21:30 UTC is 2026-10-01 00:30 Helsinki (inside October);
        // 2026-09-30 20:45 UTC is September in Helsinki and must be ignored.
        $records = array_map(fn (string $utc) => [
            'region' => 'FI',
            'timestamp' => Carbon::parse($utc, 'UTC')->timestamp,
            'utc_datetime' => Carbon::parse($utc, 'UTC'),
            'price_without_tax' => 3.0,
            'resolution_minutes' => 15,
        ], ['2026-09-30 20:45:00', '2026-09-30 21:30:00']);
        $this->mockEntsoe($records);

        $this->artisan('spot:repair-history --from=2026-10 --to=2026-10 --apply')->assertExitCode(0);

        $this->assertSame(['2026-09-30 21:30:00'], DB::table('spot_prices_quarter')->pluck('utc_datetime')->all());
    }

    public function test_rejects_invalid_options(): void
    {
        $this->mockEntsoe([]);

        $this->artisan('spot:repair-history --from=2026-10')->assertExitCode(1);
        $this->artisan('spot:repair-history --from=2026-11 --to=2026-10')->assertExitCode(1);
        $this->artisan('spot:repair-history --from=2026-10 --to=2026-12')->assertExitCode(1);
        $this->artisan('spot:repair-history --from=2026-10 --to=2026-10 --region=XX')->assertExitCode(1);
    }
}
