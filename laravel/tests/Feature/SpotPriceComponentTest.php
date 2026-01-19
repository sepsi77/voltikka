<?php

namespace Tests\Feature;

use App\Livewire\SpotPrice;
use App\Models\SpotPriceHour;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SpotPriceComponentTest extends TestCase
{
    use RefreshDatabase;

    private const REGION = 'FI';
    private const TIMEZONE = 'Europe/Helsinki';

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze time to a known moment for consistent testing
        // January 20, 2026 at 14:30 Helsinki time
        Carbon::setTestNow(Carbon::parse('2026-01-20 14:30:00', self::TIMEZONE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // Reset test time
        parent::tearDown();
    }

    /**
     * Helper to create a spot price record for a specific Helsinki hour.
     */
    private function createSpotPrice(int $year, int $month, int $day, int $hour, float $priceWithoutTax, float $vatRate = 0.255): SpotPriceHour
    {
        $helsinkiTime = Carbon::create($year, $month, $day, $hour, 0, 0, self::TIMEZONE);
        $utcTime = $helsinkiTime->copy()->setTimezone('UTC');

        return SpotPriceHour::create([
            'region' => self::REGION,
            'timestamp' => $utcTime->timestamp,
            'utc_datetime' => $utcTime,
            'price_without_tax' => $priceWithoutTax,
            'vat_rate' => $vatRate,
        ]);
    }

    /**
     * Helper to create prices for a full day (24 hours).
     */
    private function createFullDayPrices(int $year, int $month, int $day, ?array $prices = null): void
    {
        $prices = $prices ?? array_fill(0, 24, 5.0);

        foreach ($prices as $hour => $price) {
            $this->createSpotPrice($year, $month, $day, $hour, $price);
        }
    }

    // ==========================================
    // Basic Rendering Tests
    // ==========================================

    public function test_component_renders_successfully(): void
    {
        Livewire::test(SpotPrice::class)
            ->assertStatus(200)
            ->assertSee('Pörssisähkön hinta');
    }

    public function test_component_shows_no_error_when_no_data(): void
    {
        Livewire::test(SpotPrice::class)
            ->assertDontSee('Hintatietojen lataaminen epäonnistui');
    }

    public function test_component_uses_correct_layout(): void
    {
        $this->get('/spot-price')
            ->assertStatus(200)
            ->assertSee('Voltikka');
    }

    // ==========================================
    // Price Loading Tests
    // ==========================================

    public function test_loads_today_prices_from_database(): void
    {
        // Create prices for today (2026-01-20)
        $this->createFullDayPrices(2026, 1, 20);

        Livewire::test(SpotPrice::class)
            ->assertSet('loading', false)
            ->assertSet('error', null);
    }

    public function test_loads_tomorrow_prices_when_available(): void
    {
        // Create prices for today and tomorrow
        $this->createFullDayPrices(2026, 1, 20);
        $this->createFullDayPrices(2026, 1, 21, array_fill(0, 24, 6.0));

        $component = Livewire::test(SpotPrice::class);

        // Component should have 48 hours of data (today + tomorrow)
        $component->assertSet('loading', false);
        $this->assertCount(48, $component->get('hourlyPrices'));
    }

    public function test_handles_empty_database_gracefully(): void
    {
        Livewire::test(SpotPrice::class)
            ->assertSet('loading', false)
            ->assertSet('error', null)
            ->assertSet('hourlyPrices', []);
    }

    // ==========================================
    // Current Price Tests
    // ==========================================

    public function test_gets_current_hour_price_correctly(): void
    {
        // Create prices for today with unique prices per hour
        $prices = [];
        for ($i = 0; $i < 24; $i++) {
            $prices[$i] = $i + 1.0; // Hour 0 = 1.0, Hour 1 = 2.0, etc.
        }
        $this->createFullDayPrices(2026, 1, 20, $prices);

        $component = Livewire::test(SpotPrice::class);
        $currentPrice = $component->viewData('currentPrice');

        // Current time is 14:30, so current hour is 14
        $this->assertNotNull($currentPrice);
        $this->assertEquals(15.0, $currentPrice['price_without_tax']); // Hour 14 = 15.0
        $this->assertEquals(14, $currentPrice['helsinki_hour']);
    }

    public function test_current_price_returns_null_when_no_data(): void
    {
        $component = Livewire::test(SpotPrice::class);
        $this->assertNull($component->viewData('currentPrice'));
    }

    public function test_current_price_uses_helsinki_timezone(): void
    {
        // Create a price for hour 14 Helsinki time (current hour)
        $this->createSpotPrice(2026, 1, 20, 14, 12.34);

        $component = Livewire::test(SpotPrice::class);
        $currentPrice = $component->viewData('currentPrice');

        $this->assertNotNull($currentPrice);
        $this->assertEquals(12.34, $currentPrice['price_without_tax']);
        $this->assertEquals(14, $currentPrice['helsinki_hour']);
    }

    // ==========================================
    // Min/Max Price Tests
    // ==========================================

    public function test_calculates_today_min_and_max_prices(): void
    {
        // Create varying prices for today
        $prices = array_fill(0, 24, 5.0);
        $prices[3] = 1.5;   // Set specific min at 03:00
        $prices[15] = 12.0; // Set specific max at 15:00

        $this->createFullDayPrices(2026, 1, 20, $prices);

        $component = Livewire::test(SpotPrice::class);
        $minMax = $component->viewData('todayMinMax');

        $this->assertEquals(1.5, $minMax['min']);
        $this->assertEquals(12.0, $minMax['max']);
    }

    public function test_min_max_returns_null_when_no_data(): void
    {
        $component = Livewire::test(SpotPrice::class);
        $minMax = $component->viewData('todayMinMax');

        $this->assertNull($minMax['min']);
        $this->assertNull($minMax['max']);
    }

    public function test_min_max_handles_negative_prices(): void
    {
        // Negative prices can occur during high renewable production
        $this->createSpotPrice(2026, 1, 20, 2, -2.5);
        $this->createSpotPrice(2026, 1, 20, 3, -1.0);
        $this->createSpotPrice(2026, 1, 20, 4, 0.5);
        $this->createSpotPrice(2026, 1, 20, 5, 1.0);
        $this->createSpotPrice(2026, 1, 20, 6, 2.0);
        $this->createSpotPrice(2026, 1, 20, 7, 3.0);

        $component = Livewire::test(SpotPrice::class);
        $minMax = $component->viewData('todayMinMax');

        $this->assertEquals(-2.5, $minMax['min']);
        $this->assertEquals(3.0, $minMax['max']);
    }

    // ==========================================
    // Cheapest/Most Expensive Hour Tests
    // ==========================================

    public function test_finds_cheapest_hour(): void
    {
        $prices = array_fill(0, 24, 10.0);
        $prices[4] = 2.5; // Cheapest at 04:00

        $this->createFullDayPrices(2026, 1, 20, $prices);

        $component = Livewire::test(SpotPrice::class);
        $cheapest = $component->viewData('cheapestHour');

        $this->assertNotNull($cheapest);
        $this->assertEquals(2.5, $cheapest['price_without_tax']);
        $this->assertEquals(4, $cheapest['helsinki_hour']);
    }

    public function test_finds_most_expensive_hour(): void
    {
        $prices = array_fill(0, 24, 5.0);
        $prices[17] = 25.0; // Most expensive at 17:00

        $this->createFullDayPrices(2026, 1, 20, $prices);

        $component = Livewire::test(SpotPrice::class);
        $expensive = $component->viewData('mostExpensiveHour');

        $this->assertNotNull($expensive);
        $this->assertEquals(25.0, $expensive['price_without_tax']);
        $this->assertEquals(17, $expensive['helsinki_hour']);
    }

    public function test_cheapest_hour_returns_null_when_no_data(): void
    {
        $component = Livewire::test(SpotPrice::class);
        $this->assertNull($component->viewData('cheapestHour'));
    }

    // ==========================================
    // Daily Statistics Tests
    // ==========================================

    public function test_calculates_daily_average(): void
    {
        // Create prices: 1, 2, 3, 4 = average 2.5
        $this->createSpotPrice(2026, 1, 20, 0, 1.0);
        $this->createSpotPrice(2026, 1, 20, 1, 2.0);
        $this->createSpotPrice(2026, 1, 20, 2, 3.0);
        $this->createSpotPrice(2026, 1, 20, 3, 4.0);

        $component = Livewire::test(SpotPrice::class);
        $stats = $component->viewData('todayStatistics');

        $this->assertEquals(2.5, $stats['average']);
    }

    public function test_calculates_daily_median(): void
    {
        // Create prices: 1, 2, 3, 4, 100 = median 3
        $this->createSpotPrice(2026, 1, 20, 0, 1.0);
        $this->createSpotPrice(2026, 1, 20, 1, 2.0);
        $this->createSpotPrice(2026, 1, 20, 2, 3.0);
        $this->createSpotPrice(2026, 1, 20, 3, 4.0);
        $this->createSpotPrice(2026, 1, 20, 4, 100.0);

        $component = Livewire::test(SpotPrice::class);
        $stats = $component->viewData('todayStatistics');

        $this->assertEquals(3.0, $stats['median']);
    }

    public function test_calculates_median_for_even_count(): void
    {
        // Create prices: 1, 2, 3, 4 = median (2+3)/2 = 2.5
        $this->createSpotPrice(2026, 1, 20, 0, 1.0);
        $this->createSpotPrice(2026, 1, 20, 1, 2.0);
        $this->createSpotPrice(2026, 1, 20, 2, 3.0);
        $this->createSpotPrice(2026, 1, 20, 3, 4.0);

        $component = Livewire::test(SpotPrice::class);
        $stats = $component->viewData('todayStatistics');

        $this->assertEquals(2.5, $stats['median']);
    }

    public function test_statistics_returns_nulls_when_no_data(): void
    {
        $component = Livewire::test(SpotPrice::class);
        $stats = $component->viewData('todayStatistics');

        $this->assertNull($stats['min']);
        $this->assertNull($stats['max']);
        $this->assertNull($stats['average']);
        $this->assertNull($stats['median']);
    }

    // ==========================================
    // Price With Tax Tests
    // ==========================================

    public function test_price_with_tax_calculated_correctly(): void
    {
        // 10 c/kWh with 25.5% VAT = 12.55 c/kWh
        $this->createSpotPrice(2026, 1, 20, 14, 10.0, 0.255);

        $component = Livewire::test(SpotPrice::class);
        $prices = $component->get('hourlyPrices');

        $this->assertCount(1, $prices);
        $this->assertEquals(10.0, $prices[0]['price_without_tax']);
        $this->assertEquals(12.55, $prices[0]['price_with_tax']);
    }

    public function test_different_vat_rates_displayed_correctly(): void
    {
        // Old VAT rate (24%)
        $this->createSpotPrice(2026, 1, 20, 14, 10.0, 0.24);

        $component = Livewire::test(SpotPrice::class);
        $prices = $component->get('hourlyPrices');

        $this->assertEquals(12.40, $prices[0]['price_with_tax']);
    }

    // ==========================================
    // Only Finland Region Tests
    // ==========================================

    public function test_only_loads_finland_region(): void
    {
        // Create Finland price
        $this->createSpotPrice(2026, 1, 20, 14, 5.0);

        // Create Sweden price directly (bypassing helper)
        $helsinkiTime = Carbon::create(2026, 1, 20, 14, 0, 0, self::TIMEZONE);
        $utcTime = $helsinkiTime->copy()->setTimezone('UTC');
        SpotPriceHour::create([
            'region' => 'SE1',
            'timestamp' => $utcTime->timestamp,
            'utc_datetime' => $utcTime,
            'price_without_tax' => 3.0,
            'vat_rate' => 0.25,
        ]);

        $component = Livewire::test(SpotPrice::class);
        $prices = $component->get('hourlyPrices');

        $this->assertCount(1, $prices);
        $this->assertEquals(5.0, $prices[0]['price_without_tax']);
    }

    // ==========================================
    // Sorting Tests
    // ==========================================

    public function test_hourly_prices_sorted_by_time(): void
    {
        // Create prices out of order
        $this->createSpotPrice(2026, 1, 20, 5, 5.0);
        $this->createSpotPrice(2026, 1, 20, 2, 2.0);
        $this->createSpotPrice(2026, 1, 20, 8, 8.0);

        $component = Livewire::test(SpotPrice::class);
        $prices = $component->get('hourlyPrices');

        $this->assertEquals(2.0, $prices[0]['price_without_tax']);
        $this->assertEquals(5.0, $prices[1]['price_without_tax']);
        $this->assertEquals(8.0, $prices[2]['price_without_tax']);
    }

    // ==========================================
    // View Data Tests
    // ==========================================

    public function test_view_receives_all_required_data(): void
    {
        $this->createFullDayPrices(2026, 1, 20);

        Livewire::test(SpotPrice::class)
            ->assertViewHas('currentPrice')
            ->assertViewHas('todayMinMax')
            ->assertViewHas('cheapestHour')
            ->assertViewHas('mostExpensiveHour')
            ->assertViewHas('todayStatistics');
    }

    public function test_helsinki_hour_included_in_price_data(): void
    {
        $this->createSpotPrice(2026, 1, 20, 14, 7.5);

        $component = Livewire::test(SpotPrice::class);
        $prices = $component->get('hourlyPrices');

        $this->assertEquals(14, $prices[0]['helsinki_hour']);
    }

    // ==========================================
    // Today vs Tomorrow Filtering Tests
    // ==========================================

    public function test_statistics_only_use_today_prices(): void
    {
        // Create today's prices (lower)
        $this->createSpotPrice(2026, 1, 20, 0, 2.0);
        $this->createSpotPrice(2026, 1, 20, 1, 4.0);

        // Create tomorrow's prices (higher - should not affect today's stats)
        $this->createSpotPrice(2026, 1, 21, 0, 100.0);
        $this->createSpotPrice(2026, 1, 21, 1, 200.0);

        $component = Livewire::test(SpotPrice::class);
        $stats = $component->viewData('todayStatistics');

        // Average should be 3.0 (only today's prices: 2.0 and 4.0)
        $this->assertEquals(3.0, $stats['average']);
        $this->assertEquals(2.0, $stats['min']);
        $this->assertEquals(4.0, $stats['max']);
    }

    public function test_cheapest_hour_only_considers_today(): void
    {
        // Create today's prices
        $this->createSpotPrice(2026, 1, 20, 0, 5.0);

        // Create cheaper price for tomorrow (should not be the "cheapest" for today stats)
        $this->createSpotPrice(2026, 1, 21, 0, 1.0);

        $component = Livewire::test(SpotPrice::class);
        $cheapest = $component->viewData('cheapestHour');

        // Should be today's price, not tomorrow's
        $this->assertEquals(5.0, $cheapest['price_without_tax']);
    }

    // ==========================================
    // HTTP Removal Tests
    // ==========================================

    public function test_does_not_make_http_requests(): void
    {
        // This test ensures we don't make any HTTP calls to external services
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['error' => 'Should not be called'], 500),
        ]);

        $this->createFullDayPrices(2026, 1, 20);

        // This should NOT throw an exception or return error
        Livewire::test(SpotPrice::class)
            ->assertSet('error', null)
            ->assertSet('loading', false);
    }
}
