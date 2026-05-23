<?php

namespace Tests\Feature;

use App\Livewire\SpotPrice;
use App\Models\SpotPriceForecast;
use App\Models\SpotPriceHour;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class FetchSpotForecastCommandTest extends TestCase
{
    use RefreshDatabase;

    private const REGION = 'FI';
    private const TIMEZONE = 'Europe/Helsinki';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_fetch_spot_forecast_imports_nordpool_predict_fi_feed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-23 12:00:00', 'UTC'));
        $url = config('services.spot_forecasts.nordpool_predict_fi.url');
        $firstUtc = Carbon::parse('2026-05-24 00:00:00', 'UTC');
        $secondUtc = $firstUtc->copy()->addHour();

        Http::fake([
            $url => Http::response([
                [$firstUtc->timestamp * 1000, 10.50],
                [$secondUtc->timestamp * 1000, 12.25],
            ]),
        ]);

        $this->artisan('spot:fetch-forecast')
            ->expectsOutput('Fetching spot price forecasts from nordpool-predict-fi...')
            ->assertExitCode(0);

        $this->assertSame(2, SpotPriceForecast::count());

        $forecast = SpotPriceForecast::query()
            ->where('timestamp', $firstUtc->timestamp)
            ->firstOrFail();

        $this->assertSame(SpotPriceForecast::SOURCE_NORDPOOL_PREDICT_FI, $forecast->source);
        $this->assertSame(self::REGION, $forecast->region);
        $this->assertEquals(10.50, $forecast->price_with_tax);
        $this->assertEqualsWithDelta(10.50 / 1.255, $forecast->price_without_tax, 0.0001);
        $this->assertSame('https://github.com/vividfog/nordpool-predict-fi', $forecast->source_url);
    }

    public function test_spot_price_page_shows_forecasts_with_source_attribution(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-23 12:00:00', self::TIMEZONE));
        Cache::flush();

        $this->createFullDayActualPrices(2026, 5, 23);
        $this->createForecastPrice(Carbon::parse('2026-05-24 00:00:00', self::TIMEZONE), 8.75);
        $this->createForecastPrice(Carbon::parse('2026-05-24 01:00:00', self::TIMEZONE), 7.10);

        $component = Livewire::test(SpotPrice::class)
            ->assertSee('Ennuste')
            ->assertSee('nordpool-predict-fi')
            ->assertSee('Ennusteen lähde');

        $forecastDays = $component->viewData('forecastPricesGroupedByDate');

        $this->assertNotEmpty($forecastDays);
        $this->assertSame('Huomenna', $forecastDays[0]['label']);
        $this->assertSame(8.75, $forecastDays[0]['prices'][0]['price_with_vat']);

        $dayStrips = $component->viewData('dayStrips');
        $this->assertNotEmpty($dayStrips);
        $this->assertContains('Huomenna', array_column($dayStrips, 'label'));
    }

    private function createFullDayActualPrices(int $year, int $month, int $day): void
    {
        for ($hour = 0; $hour < 24; $hour++) {
            $helsinkiTime = Carbon::create($year, $month, $day, $hour, 0, 0, self::TIMEZONE);
            $utcTime = $helsinkiTime->copy()->setTimezone('UTC');

            SpotPriceHour::create([
                'region' => self::REGION,
                'timestamp' => $utcTime->timestamp,
                'utc_datetime' => $utcTime,
                'price_without_tax' => 5.0,
                'vat_rate' => 0.255,
            ]);
        }
    }

    private function createForecastPrice(Carbon $helsinkiTime, float $priceWithTax): void
    {
        $utcTime = $helsinkiTime->copy()->setTimezone('UTC');

        SpotPriceForecast::create([
            'source' => SpotPriceForecast::SOURCE_NORDPOOL_PREDICT_FI,
            'region' => self::REGION,
            'timestamp' => $utcTime->timestamp,
            'utc_datetime' => $utcTime,
            'price_with_tax' => $priceWithTax,
            'vat_rate' => 0.255,
            'source_url' => 'https://github.com/vividfog/nordpool-predict-fi',
            'fetched_at' => Carbon::now('UTC'),
            'metadata' => ['test' => true],
        ]);
    }
}
