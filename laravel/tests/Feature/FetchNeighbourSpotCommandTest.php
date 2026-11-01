<?php

namespace Tests\Feature;

use App\Models\SpotPriceHour;
use App\Models\SpotPriceQuarter;
use App\Services\EntsoeService;
use App\Services\SpotPriceAverageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Mockery;
use Tests\TestCase;

class FetchNeighbourSpotCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 31, 12, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quarterRecords(string $region, float $price): array
    {
        $start = Carbon::parse('2026-10-31 00:00:00', 'UTC');

        return array_map(fn (int $i) => [
            'region' => $region,
            'timestamp' => $start->copy()->addMinutes(15 * $i)->timestamp,
            'utc_datetime' => $start->copy()->addMinutes(15 * $i),
            'price_without_tax' => $price,
            'resolution_minutes' => 15,
        ], range(0, 3));
    }

    public function test_imports_each_configured_zone_without_finnish_vat(): void
    {
        $service = Mockery::mock(EntsoeService::class);
        foreach (['SE1' => 2.0, 'SE3' => 8.0, 'EE' => 12.0, 'DE-LU' => 20.0] as $region => $price) {
            $service->shouldReceive('fetchDayAheadPrices')
                ->once()
                ->withArgs(fn ($start, $end, $requested) => $requested === $region)
                ->andReturn($this->quarterRecords($region, $price));
        }
        $this->app->instance(EntsoeService::class, $service);

        $this->artisan('spot:fetch-neighbours')->assertExitCode(0);

        $this->assertSame(16, SpotPriceQuarter::count());
        $se3 = SpotPriceHour::query()->forRegion('SE3')->firstOrFail();
        $this->assertEqualsWithDelta(8.0, $se3->price_without_tax, 0.0001);
        $this->assertEqualsWithDelta(0.0, $se3->vat_rate, 0.0001);
        $this->assertSame(0, SpotPriceHour::query()->forRegion('FI')->count());
    }

    public function test_one_failed_zone_does_not_stop_the_others_and_fails_the_run(): void
    {
        $averages = Mockery::mock(SpotPriceAverageService::class);
        $averages->shouldNotReceive('calculateAllAverages');
        $this->app->instance(SpotPriceAverageService::class, $averages);

        $service = Mockery::mock(EntsoeService::class);
        $service->shouldReceive('fetchDayAheadPrices')
            ->withArgs(fn ($start, $end, $region) => $region === 'SE1')
            ->andThrow(new ConnectionException('timeout'));
        $service->shouldReceive('fetchDayAheadPrices')
            ->withArgs(fn ($start, $end, $region) => $region !== 'SE1')
            ->andReturnUsing(fn ($start, $end, $region) => $this->quarterRecords($region, 5.0));
        $this->app->instance(EntsoeService::class, $service);

        $this->artisan('spot:fetch-neighbours')->assertExitCode(1);

        $this->assertSame(0, SpotPriceHour::query()->forRegion('SE1')->count());
        $this->assertSame(1, SpotPriceHour::query()->forRegion('EE')->count());
    }

    public function test_backfill_accepts_a_neighbour_region_and_skips_finnish_averages(): void
    {
        $averages = Mockery::mock(SpotPriceAverageService::class);
        $averages->shouldNotReceive('calculateAllAverages');
        $this->app->instance(SpotPriceAverageService::class, $averages);

        $service = Mockery::mock(EntsoeService::class);
        $service->shouldReceive('fetchDayAheadPrices')
            ->once()
            ->withArgs(fn ($start, $end, $region) => $region === 'EE')
            ->andReturn($this->quarterRecords('EE', 9.0));
        $this->app->instance(EntsoeService::class, $service);

        $this->artisan('spot:backfill --start-date=2026-10-31 --end-date=2026-10-31 --region=EE')->assertExitCode(0);

        $this->assertSame(1, SpotPriceHour::query()->forRegion('EE')->count());
    }

    public function test_backfill_rejects_an_unknown_region(): void
    {
        $this->artisan('spot:backfill --start-date=2026-10-31 --end-date=2026-10-31 --region=XX')->assertExitCode(1);
    }
}
