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
            ->assertSee('Pörssisähkön');
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

    // ==========================================
    // Best Consecutive Hours Tests
    // ==========================================

    public function test_finds_best_consecutive_hours(): void
    {
        // Create prices where hours 16-18 are cheapest consecutive (after current time 14:30)
        $prices = array_fill(0, 24, 10.0);
        $prices[16] = 2.0;
        $prices[17] = 3.0;
        $prices[18] = 1.0;

        $this->createFullDayPrices(2026, 1, 20, $prices);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $best = $instance->getBestConsecutiveHours(3);

        $this->assertNotNull($best);
        $this->assertEquals(16, $best['start_hour']); // Starts at hour 16
        $this->assertEquals(18, $best['end_hour']); // Ends at hour 18
        $this->assertEquals(2.0, $best['average_price']); // (2+3+1)/3 = 2
    }

    public function test_best_consecutive_hours_with_single_hour(): void
    {
        $prices = array_fill(0, 24, 10.0);
        $prices[17] = 1.0; // Cheapest single hour (after current time 14:30)

        $this->createFullDayPrices(2026, 1, 20, $prices);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $best = $instance->getBestConsecutiveHours(1);

        $this->assertEquals(17, $best['start_hour']);
        $this->assertEquals(17, $best['end_hour']);
        $this->assertEquals(1.0, $best['average_price']);
    }

    public function test_best_consecutive_hours_returns_null_when_not_enough_data(): void
    {
        // Only create 2 hours (after current time 14:30), but request 3
        $this->createSpotPrice(2026, 1, 20, 15, 5.0);
        $this->createSpotPrice(2026, 1, 20, 16, 6.0);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $best = $instance->getBestConsecutiveHours(3);

        $this->assertNull($best);
    }

    public function test_best_consecutive_hours_includes_prices_array(): void
    {
        $prices = array_fill(0, 24, 10.0);
        $prices[18] = 2.0; // After current time 14:30
        $prices[19] = 3.0;

        $this->createFullDayPrices(2026, 1, 20, $prices);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $best = $instance->getBestConsecutiveHours(2);

        $this->assertCount(2, $best['prices']);
        $this->assertEquals(2.0, $best['prices'][0]['price_without_tax']);
        $this->assertEquals(3.0, $best['prices'][1]['price_without_tax']);
    }

    // ==========================================
    // Price Volatility Tests
    // ==========================================

    public function test_calculates_price_variance(): void
    {
        // Create prices: 2, 4, 6, 8 - variance = ((2-5)^2 + (4-5)^2 + (6-5)^2 + (8-5)^2) / 4 = 5
        $this->createSpotPrice(2026, 1, 20, 0, 2.0);
        $this->createSpotPrice(2026, 1, 20, 1, 4.0);
        $this->createSpotPrice(2026, 1, 20, 2, 6.0);
        $this->createSpotPrice(2026, 1, 20, 3, 8.0);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $volatility = $instance->getPriceVolatility();

        $this->assertEquals(5.0, $volatility['variance']);
        $this->assertEquals(5.0, $volatility['average']);
    }

    public function test_calculates_standard_deviation(): void
    {
        // Create prices: 2, 4, 6, 8 - std dev = sqrt(5) ≈ 2.236
        $this->createSpotPrice(2026, 1, 20, 0, 2.0);
        $this->createSpotPrice(2026, 1, 20, 1, 4.0);
        $this->createSpotPrice(2026, 1, 20, 2, 6.0);
        $this->createSpotPrice(2026, 1, 20, 3, 8.0);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $volatility = $instance->getPriceVolatility();

        $this->assertEqualsWithDelta(2.236, $volatility['std_deviation'], 0.01);
    }

    public function test_volatility_returns_nulls_when_no_data(): void
    {
        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $volatility = $instance->getPriceVolatility();

        $this->assertNull($volatility['variance']);
        $this->assertNull($volatility['std_deviation']);
        $this->assertNull($volatility['average']);
    }

    public function test_volatility_includes_price_range(): void
    {
        $this->createSpotPrice(2026, 1, 20, 0, 2.0);
        $this->createSpotPrice(2026, 1, 20, 1, 10.0);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $volatility = $instance->getPriceVolatility();

        $this->assertEquals(8.0, $volatility['range']); // 10 - 2
    }

    // ==========================================
    // Historical Comparison Tests
    // ==========================================

    public function test_compares_today_with_yesterday(): void
    {
        // Create yesterday's prices (average 5.0)
        $this->createFullDayPrices(2026, 1, 19, array_fill(0, 24, 5.0));

        // Create today's prices (average 10.0)
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0));

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $comparison = $instance->getHistoricalComparison();

        $this->assertEquals(10.0, $comparison['today_average']);
        $this->assertEquals(5.0, $comparison['yesterday_average']);
        $this->assertEquals(100.0, $comparison['change_from_yesterday_percent']); // +100%
    }

    public function test_compares_today_with_weekly_average(): void
    {
        // Create 7 days of prices (week average = 7.0)
        for ($day = 13; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, (float) $day - 12));
        }

        // Create today's prices (average 10.0)
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0));

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $comparison = $instance->getHistoricalComparison();

        $this->assertEquals(10.0, $comparison['today_average']);
        $this->assertEquals(4.0, $comparison['weekly_average']); // Average of 1-7
        $this->assertEqualsWithDelta(150.0, $comparison['change_from_weekly_percent'], 0.1); // +150%
    }

    public function test_historical_comparison_handles_no_yesterday_data(): void
    {
        // Only create today's prices
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0));

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $comparison = $instance->getHistoricalComparison();

        $this->assertEquals(10.0, $comparison['today_average']);
        $this->assertNull($comparison['yesterday_average']);
        $this->assertNull($comparison['change_from_yesterday_percent']);
    }

    public function test_historical_comparison_handles_partial_weekly_data(): void
    {
        // Only create 3 days of history instead of 7
        $this->createFullDayPrices(2026, 1, 17, array_fill(0, 24, 6.0));
        $this->createFullDayPrices(2026, 1, 18, array_fill(0, 24, 8.0));
        $this->createFullDayPrices(2026, 1, 19, array_fill(0, 24, 10.0));
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 12.0));

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $comparison = $instance->getHistoricalComparison();

        $this->assertEquals(12.0, $comparison['today_average']);
        $this->assertEquals(8.0, $comparison['weekly_average']); // (6+8+10)/3 = 8
        $this->assertEquals(3, $comparison['weekly_days_available']);
    }

    // ==========================================
    // Cheapest Remaining Hours Tests
    // ==========================================

    public function test_finds_cheapest_remaining_hours_today(): void
    {
        // Create prices for today
        $prices = array_fill(0, 24, 10.0);
        $prices[15] = 3.0; // Cheap hour in future (current time is 14:30)
        $prices[16] = 2.0; // Cheapest hour in future
        $prices[17] = 4.0; // Another future hour
        $prices[5] = 1.0;  // Cheapest but already passed

        $this->createFullDayPrices(2026, 1, 20, $prices);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $remaining = $instance->getCheapestRemainingHours(3);

        $this->assertCount(3, $remaining);
        // Should be ordered by price, not by hour
        $this->assertEquals(16, $remaining[0]['helsinki_hour']); // 2.0
        $this->assertEquals(15, $remaining[1]['helsinki_hour']); // 3.0
        $this->assertEquals(17, $remaining[2]['helsinki_hour']); // 4.0
    }

    public function test_cheapest_remaining_hours_excludes_current_hour(): void
    {
        // Current hour is 14 (time is 14:30)
        $prices = array_fill(0, 24, 10.0);
        $prices[14] = 1.0; // Cheapest but current hour
        $prices[15] = 5.0;

        $this->createFullDayPrices(2026, 1, 20, $prices);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $remaining = $instance->getCheapestRemainingHours(1);

        $this->assertNotEquals(14, $remaining[0]['helsinki_hour']);
        $this->assertEquals(15, $remaining[0]['helsinki_hour']);
    }

    public function test_cheapest_remaining_includes_tomorrow(): void
    {
        // Create today and tomorrow prices
        $todayPrices = array_fill(0, 24, 10.0);
        $tomorrowPrices = array_fill(0, 24, 10.0);
        $tomorrowPrices[3] = 1.0; // Cheapest is tomorrow at 3:00

        $this->createFullDayPrices(2026, 1, 20, $todayPrices);
        $this->createFullDayPrices(2026, 1, 21, $tomorrowPrices);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $remaining = $instance->getCheapestRemainingHours(1);

        $this->assertEquals(3, $remaining[0]['helsinki_hour']);
        $this->assertEquals('2026-01-21', $remaining[0]['helsinki_date']);
    }

    public function test_cheapest_remaining_returns_empty_when_no_future_hours(): void
    {
        // Freeze time to 23:30, so only hour 23 is "past" for today
        Carbon::setTestNow(Carbon::parse('2026-01-20 23:30:00', self::TIMEZONE));

        $prices = array_fill(0, 24, 10.0);
        $this->createFullDayPrices(2026, 1, 20, $prices);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $remaining = $instance->getCheapestRemainingHours(3);

        // Only tomorrow's hours should be available (none exist)
        $this->assertEmpty($remaining);
    }

    // ==========================================
    // Potential Savings Tests
    // ==========================================

    public function test_calculates_potential_savings(): void
    {
        // Create varied prices
        $prices = array_fill(0, 24, 10.0);
        $prices[3] = 2.0;  // Cheap
        $prices[4] = 3.0;  // Cheap
        $prices[5] = 4.0;  // Cheap
        $prices[17] = 20.0; // Expensive
        $prices[18] = 25.0; // Most expensive

        $this->createFullDayPrices(2026, 1, 20, $prices);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $savings = $instance->calculatePotentialSavings(3, 10); // 3 hours at 10 kWh each

        // Average price ≈ 10.42 c/kWh
        // Cheapest 3 hours average = (2+3+4)/3 = 3 c/kWh
        // Savings per kWh ≈ 7.42 c/kWh
        // Total savings = 30 kWh * 0.0742 €/kWh ≈ 2.23 €

        $this->assertNotNull($savings);
        $this->assertEquals(3.0, $savings['cheapest_average']);
        $this->assertGreaterThan(0, $savings['savings_cents']);
        $this->assertGreaterThan(0, $savings['savings_percent']);
    }

    public function test_savings_calculation_with_consumption(): void
    {
        // Simple case: avg 10, cheapest 5
        $this->createSpotPrice(2026, 1, 20, 0, 5.0);
        $this->createSpotPrice(2026, 1, 20, 1, 5.0);
        $this->createSpotPrice(2026, 1, 20, 2, 15.0);
        $this->createSpotPrice(2026, 1, 20, 3, 15.0);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();

        // Use 2 hours with 10 kWh each = 20 kWh total
        $savings = $instance->calculatePotentialSavings(2, 10);

        // Average price = (5+5+15+15)/4 = 10 c/kWh
        // Cheapest 2 hours = 5 c/kWh
        // Savings = 5 c/kWh * 20 kWh = 100 cents = 1.00 €
        $this->assertEquals(5.0, $savings['cheapest_average']);
        $this->assertEquals(10.0, $savings['overall_average']);
        $this->assertEquals(100, $savings['savings_cents']); // 100 cents = 1€
    }

    public function test_savings_returns_null_when_not_enough_data(): void
    {
        $this->createSpotPrice(2026, 1, 20, 0, 5.0);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $savings = $instance->calculatePotentialSavings(3, 10); // Request 3 hours but only 1 available

        $this->assertNull($savings);
    }

    // ==========================================
    // View Data Analytics Tests
    // ==========================================

    public function test_view_receives_analytics_data(): void
    {
        $this->createFullDayPrices(2026, 1, 19);
        $this->createFullDayPrices(2026, 1, 20);

        Livewire::test(SpotPrice::class)
            ->assertViewHas('priceVolatility')
            ->assertViewHas('historicalComparison')
            ->assertViewHas('cheapestRemainingHours')
            ->assertViewHas('bestConsecutiveHours')
            ->assertViewHas('potentialSavings');
    }

    // ==========================================
    // Enhanced View Tests
    // ==========================================

    public function test_view_displays_hero_section_with_current_price(): void
    {
        $this->createFullDayPrices(2026, 1, 20);

        Livewire::test(SpotPrice::class)
            ->assertSee('Pörssisähkön hinta')
            ->assertSee('Seuraa sähkön pörssihinnan kehitystä');
    }

    public function test_view_displays_price_comparison_cards(): void
    {
        // Create yesterday's prices for comparison
        $this->createFullDayPrices(2026, 1, 19, array_fill(0, 24, 5.0));
        // Create today's prices
        $prices = array_fill(0, 24, 10.0);
        $prices[3] = 2.0;  // Min
        $prices[17] = 20.0; // Max
        $this->createFullDayPrices(2026, 1, 20, $prices);

        Livewire::test(SpotPrice::class)
            ->assertSee('Tänään')
            ->assertSee('Eilen')
            ->assertSee('Viikon ka.');
    }

    public function test_view_displays_statistics_section(): void
    {
        $prices = array_fill(0, 24, 10.0);
        $prices[3] = 2.0;
        $prices[17] = 20.0;
        $this->createFullDayPrices(2026, 1, 20, $prices);

        Livewire::test(SpotPrice::class)
            ->assertSee('Keskihinta')
            ->assertSee('Mediaani')
            ->assertSee('Alin')
            ->assertSee('Ylin');
    }

    public function test_view_displays_best_hours_section(): void
    {
        $prices = array_fill(0, 24, 10.0);
        $prices[15] = 2.0;
        $prices[16] = 3.0;
        $prices[17] = 4.0;
        $this->createFullDayPrices(2026, 1, 20, $prices);

        Livewire::test(SpotPrice::class)
            ->assertSee('Edullisimmat tunnit');
    }

    public function test_view_displays_ev_charging_section(): void
    {
        $this->createFullDayPrices(2026, 1, 20);

        Livewire::test(SpotPrice::class)
            ->assertSee('Sähköauton lataus');
    }

    public function test_view_displays_hourly_prices_chart_data(): void
    {
        $prices = array_fill(0, 24, 10.0);
        $this->createFullDayPrices(2026, 1, 20, $prices);

        $component = Livewire::test(SpotPrice::class);

        // Verify chart data is available in view
        $component->assertViewHas('chartData');
    }

    public function test_view_passes_chart_data_to_view(): void
    {
        $prices = array_fill(0, 24, 10.0);
        $prices[3] = 2.0;  // Min price for color coding
        $prices[17] = 20.0; // Max price for color coding
        $this->createFullDayPrices(2026, 1, 20, $prices);

        $component = Livewire::test(SpotPrice::class);
        $chartData = $component->viewData('chartData');

        // Chart data should have labels (hours) and datasets
        $this->assertArrayHasKey('labels', $chartData);
        $this->assertArrayHasKey('datasets', $chartData);
        $this->assertCount(24, $chartData['labels']);
    }

    public function test_view_chart_data_includes_color_coding(): void
    {
        // Create prices with variety for color coding
        $prices = array_fill(0, 24, 10.0);
        $prices[3] = 2.0;   // Cheap (green)
        $prices[17] = 20.0; // Expensive (red)
        $this->createFullDayPrices(2026, 1, 20, $prices);

        $component = Livewire::test(SpotPrice::class);
        $chartData = $component->viewData('chartData');

        // Verify background colors are set
        $this->assertArrayHasKey('backgroundColor', $chartData['datasets'][0]);
        $bgColors = $chartData['datasets'][0]['backgroundColor'];

        // Should have colors for each hour
        $this->assertCount(24, $bgColors);
    }

    public function test_view_displays_volatility_indicator(): void
    {
        // Create prices with high variance
        $prices = array_fill(0, 24, 10.0);
        $prices[3] = 2.0;
        $prices[17] = 50.0;
        $this->createFullDayPrices(2026, 1, 20, $prices);

        Livewire::test(SpotPrice::class)
            ->assertSee('Hintavaihtelu');
    }

    public function test_view_displays_potential_savings(): void
    {
        $prices = array_fill(0, 24, 10.0);
        $prices[3] = 2.0;
        $prices[4] = 3.0;
        $prices[5] = 4.0;
        $this->createFullDayPrices(2026, 1, 20, $prices);

        Livewire::test(SpotPrice::class)
            ->assertSee('Mahdollinen säästö');
    }

    public function test_view_handles_empty_data_gracefully(): void
    {
        // No data in database
        Livewire::test(SpotPrice::class)
            ->assertSee('Hintatietoja ei ole vielä saatavilla')
            ->assertDontSee('NaN')
            ->assertDontSee('undefined');
    }

    public function test_view_displays_finnish_formatted_numbers(): void
    {
        $this->createSpotPrice(2026, 1, 20, 14, 12.345);

        // Finnish number formatting uses comma as decimal separator
        Livewire::test(SpotPrice::class)
            ->assertSee('12,35'); // Rounded to 2 decimals with comma
    }

    public function test_view_displays_change_indicators(): void
    {
        // Create yesterday with lower prices
        $this->createFullDayPrices(2026, 1, 19, array_fill(0, 24, 5.0));
        // Create today with higher prices (should show increase)
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0));

        Livewire::test(SpotPrice::class)
            ->assertSee('+'); // Should show positive change indicator
    }

    public function test_view_shows_tomorrow_badge_for_next_day_prices(): void
    {
        $this->createFullDayPrices(2026, 1, 20);
        $this->createFullDayPrices(2026, 1, 21);

        Livewire::test(SpotPrice::class)
            ->assertSee('Huomenna');
    }

    public function test_view_highlights_current_hour(): void
    {
        $this->createFullDayPrices(2026, 1, 20);

        // Current time is 14:30, so hour 14 should be highlighted
        Livewire::test(SpotPrice::class)
            ->assertSee('14:00 - 15:00')
            ->assertSee('Nyt');
    }

    public function test_view_displays_cheapest_hour_prominently(): void
    {
        $prices = array_fill(0, 24, 10.0);
        $prices[4] = 1.0; // Cheapest hour at 04:00

        $this->createFullDayPrices(2026, 1, 20, $prices);

        Livewire::test(SpotPrice::class)
            ->assertSee('Edullisin tunti')
            ->assertSee('04-05');
    }

    public function test_view_displays_most_expensive_hour(): void
    {
        $prices = array_fill(0, 24, 10.0);
        $prices[17] = 25.0; // Most expensive at 17:00

        $this->createFullDayPrices(2026, 1, 20, $prices);

        Livewire::test(SpotPrice::class)
            ->assertSee('Kallein tunti')
            ->assertSee('17-18');
    }

    public function test_view_responsive_for_mobile(): void
    {
        $this->createFullDayPrices(2026, 1, 20);

        // Check that responsive classes are present
        $response = Livewire::test(SpotPrice::class);

        // View should contain mobile-responsive classes
        $html = $response->html();
        $this->assertStringContainsString('md:', $html);
        $this->assertStringContainsString('lg:', $html);
    }

    // ==========================================
    // Historical Price Trends Tests (Iteration 7)
    // ==========================================

    public function test_gets_weekly_daily_averages(): void
    {
        // Create 7 days of prices before today
        for ($day = 13; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, (float) $day));
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 20.0)); // Today

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $weeklyData = $instance->getWeeklyDailyAverages();

        // Should have 7 days of data (week before today)
        $this->assertCount(7, $weeklyData);

        // First entry should be the oldest (7 days ago = Jan 13)
        $this->assertEquals('2026-01-13', $weeklyData[0]['date']);
        $this->assertEquals(13.0, $weeklyData[0]['average']);

        // Last entry should be yesterday (Jan 19)
        $this->assertEquals('2026-01-19', $weeklyData[6]['date']);
        $this->assertEquals(19.0, $weeklyData[6]['average']);
    }

    public function test_weekly_daily_averages_handles_partial_data(): void
    {
        // Only create 3 days of history
        $this->createFullDayPrices(2026, 1, 17, array_fill(0, 24, 17.0));
        $this->createFullDayPrices(2026, 1, 18, array_fill(0, 24, 18.0));
        $this->createFullDayPrices(2026, 1, 19, array_fill(0, 24, 19.0));
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 20.0)); // Today

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $weeklyData = $instance->getWeeklyDailyAverages();

        // Should only have 3 days of data
        $this->assertCount(3, $weeklyData);
    }

    public function test_weekly_daily_averages_returns_empty_when_no_history(): void
    {
        // Only today's data
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 20.0));

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $weeklyData = $instance->getWeeklyDailyAverages();

        $this->assertEmpty($weeklyData);
    }

    public function test_gets_monthly_comparison(): void
    {
        // Create data for December 2025 (last month)
        for ($day = 1; $day <= 10; $day++) {
            $this->createFullDayPrices(2025, 12, $day, array_fill(0, 24, 5.0));
        }

        // Create data for January 2026 (this month, up to current day - 19)
        for ($day = 1; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, 10.0));
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0)); // Today

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $monthlyComparison = $instance->getMonthlyComparison();

        $this->assertEquals('Tammikuu', $monthlyComparison['current_month_name']);
        $this->assertEquals('Joulukuu', $monthlyComparison['last_month_name']);
        $this->assertEquals(10.0, $monthlyComparison['current_month_average']);
        $this->assertEquals(5.0, $monthlyComparison['last_month_average']);
        $this->assertEquals(100.0, $monthlyComparison['change_percent']); // +100% change
        $this->assertGreaterThan(0, $monthlyComparison['current_month_days']);
        $this->assertEquals(10, $monthlyComparison['last_month_days']);
    }

    public function test_monthly_comparison_handles_no_last_month_data(): void
    {
        // Only create current month data
        for ($day = 1; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, 10.0));
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0)); // Today

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $monthlyComparison = $instance->getMonthlyComparison();

        $this->assertEquals(10.0, $monthlyComparison['current_month_average']);
        $this->assertNull($monthlyComparison['last_month_average']);
        $this->assertNull($monthlyComparison['change_percent']);
    }

    public function test_gets_year_over_year_comparison(): void
    {
        // Create data for January 2025 (last year same month)
        for ($day = 1; $day <= 20; $day++) {
            $this->createFullDayPrices(2025, 1, $day, array_fill(0, 24, 5.0));
        }

        // Create data for January 2026 (this month)
        for ($day = 1; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, 8.0));
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 8.0)); // Today

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $yoyComparison = $instance->getYearOverYearComparison();

        $this->assertEquals(8.0, $yoyComparison['current_year_average']);
        $this->assertEquals(5.0, $yoyComparison['last_year_average']);
        $this->assertEquals(2026, $yoyComparison['current_year']);
        $this->assertEquals(2025, $yoyComparison['last_year']);
        $this->assertEquals(60.0, $yoyComparison['change_percent']); // +60% change
        $this->assertTrue($yoyComparison['has_last_year_data']);
    }

    public function test_year_over_year_handles_no_last_year_data(): void
    {
        // Only create current year data
        for ($day = 1; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, 10.0));
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0)); // Today

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $yoyComparison = $instance->getYearOverYearComparison();

        $this->assertEquals(10.0, $yoyComparison['current_year_average']);
        $this->assertNull($yoyComparison['last_year_average']);
        $this->assertNull($yoyComparison['change_percent']);
        $this->assertFalse($yoyComparison['has_last_year_data']);
    }

    public function test_gets_weekly_chart_data(): void
    {
        // Create 7 days of varying prices
        for ($day = 13; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, (float) $day));
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 20.0)); // Today

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $chartData = $instance->getWeeklyChartData();

        // Verify Chart.js structure
        $this->assertArrayHasKey('labels', $chartData);
        $this->assertArrayHasKey('datasets', $chartData);
        $this->assertCount(7, $chartData['labels']); // 7 days

        // Check dataset structure
        $this->assertArrayHasKey('data', $chartData['datasets'][0]);
        $this->assertArrayHasKey('label', $chartData['datasets'][0]);
    }

    public function test_weekly_chart_data_includes_min_max_range(): void
    {
        // Create data with varying daily averages
        for ($day = 13; $day <= 19; $day++) {
            $dayPrices = array_fill(0, 24, 10.0);
            $dayPrices[5] = 2.0;  // Min for the day
            $dayPrices[17] = 20.0; // Max for the day
            $this->createFullDayPrices(2026, 1, $day, $dayPrices);
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0)); // Today

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $chartData = $instance->getWeeklyChartData();

        // Should have additional datasets for min/max range
        $this->assertCount(2, $chartData['datasets']); // Average + range
    }

    public function test_generates_csv_data_for_export(): void
    {
        // Create today's prices
        $prices = [];
        for ($h = 0; $h < 24; $h++) {
            $prices[$h] = $h + 1.0;
        }
        $this->createFullDayPrices(2026, 1, 20, $prices);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $csvData = $instance->generateCsvData();

        // Should be a string with CSV content
        $this->assertIsString($csvData);

        // Should have header line
        $this->assertStringContainsString('Päivämäärä', $csvData);
        $this->assertStringContainsString('Tunti', $csvData);
        $this->assertStringContainsString('Hinta (c/kWh)', $csvData);

        // Should have data lines
        $lines = explode("\n", trim($csvData));
        $this->assertGreaterThanOrEqual(25, count($lines)); // Header + 24 hours
    }

    public function test_csv_data_includes_both_price_formats(): void
    {
        $this->createFullDayPrices(2026, 1, 20);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $csvData = $instance->generateCsvData();

        // Should include both VAT 0% and with VAT columns
        $this->assertStringContainsString('ALV 0%', $csvData);
        $this->assertStringContainsString('sis. ALV', $csvData);
    }

    public function test_csv_data_includes_historical_data_when_available(): void
    {
        // Create yesterday and today's prices
        $this->createFullDayPrices(2026, 1, 19);
        $this->createFullDayPrices(2026, 1, 20);

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $csvData = $instance->generateCsvData(days: 2);

        // Should have 48 data rows (2 days * 24 hours)
        $lines = explode("\n", trim($csvData));
        $this->assertEquals(49, count($lines)); // Header + 48 hours
    }

    public function test_historical_data_is_lazy_loaded(): void
    {
        // Create historical data
        for ($day = 1; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, 10.0));
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0)); // Today

        // Initial component load should NOT include heavy historical data
        $component = Livewire::test(SpotPrice::class);

        // Historical data should only be loaded when requested
        $this->assertFalse($component->get('historicalDataLoaded'));

        // Call the method to load historical data
        $component->call('loadHistoricalData');

        $this->assertTrue($component->get('historicalDataLoaded'));
        $component->assertViewHas('weeklyDailyAverages');
        $component->assertViewHas('monthlyComparison');
        $component->assertViewHas('yearOverYearComparison');
    }

    public function test_historical_section_shows_loading_state_initially(): void
    {
        $this->createFullDayPrices(2026, 1, 20);

        Livewire::test(SpotPrice::class)
            ->assertSee('Lataa historiatiedot');
    }

    public function test_historical_section_shows_data_after_loading(): void
    {
        // Create historical data
        for ($day = 13; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, 10.0));
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0)); // Today

        Livewire::test(SpotPrice::class)
            ->call('loadHistoricalData')
            ->assertSee('Viikon hintakehitys')
            ->assertSee('Kuukausivertailu');
    }

    public function test_view_displays_weekly_chart_section(): void
    {
        for ($day = 13; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, 10.0));
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0));

        Livewire::test(SpotPrice::class)
            ->call('loadHistoricalData')
            ->assertSee('Viikon hintakehitys');
    }

    public function test_view_displays_monthly_comparison_section(): void
    {
        // Create last month data
        for ($day = 1; $day <= 10; $day++) {
            $this->createFullDayPrices(2025, 12, $day, array_fill(0, 24, 5.0));
        }
        // Create current month data
        for ($day = 1; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, 10.0));
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0));

        Livewire::test(SpotPrice::class)
            ->call('loadHistoricalData')
            ->assertSee('Kuukausivertailu')
            ->assertSee('Tammikuu')
            ->assertSee('Joulukuu');
    }

    public function test_view_displays_year_over_year_when_data_available(): void
    {
        // Create last year's January data
        for ($day = 1; $day <= 20; $day++) {
            $this->createFullDayPrices(2025, 1, $day, array_fill(0, 24, 5.0));
        }
        // Create this year's data
        for ($day = 1; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, 10.0));
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0));

        Livewire::test(SpotPrice::class)
            ->call('loadHistoricalData')
            ->assertSee('Vuosivertailu')
            ->assertSee('2025')
            ->assertSee('2026');
    }

    public function test_view_hides_year_over_year_when_no_data(): void
    {
        // Only create current year data
        for ($day = 1; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, 10.0));
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0));

        Livewire::test(SpotPrice::class)
            ->call('loadHistoricalData')
            ->assertDontSee('Vuosivertailu');
    }

    public function test_view_displays_csv_download_button(): void
    {
        $this->createFullDayPrices(2026, 1, 20);

        Livewire::test(SpotPrice::class)
            ->assertSee('Lataa CSV');
    }

    public function test_csv_download_returns_proper_response(): void
    {
        $this->createFullDayPrices(2026, 1, 20);

        $component = Livewire::test(SpotPrice::class)
            ->call('downloadCsv');

        // Should trigger a file download (Livewire's download feature)
        $component->assertFileDownloaded('spot-hinnat.csv');
    }

    public function test_weekly_chart_uses_finnish_day_names(): void
    {
        for ($day = 13; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, 10.0));
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0));

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $chartData = $instance->getWeeklyChartData();

        // Labels should be Finnish day names or date format
        $labels = $chartData['labels'];
        // Check for Finnish format (e.g., "Ma 13.1" or "13.1.")
        $this->assertMatchesRegularExpression('/\d{1,2}\.\d{1,2}\./', $labels[0]);
    }

    public function test_monthly_comparison_uses_finnish_month_names(): void
    {
        for ($day = 1; $day <= 10; $day++) {
            $this->createFullDayPrices(2025, 12, $day, array_fill(0, 24, 5.0));
        }
        for ($day = 1; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, 10.0));
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0));

        $component = Livewire::test(SpotPrice::class);
        $instance = $component->instance();
        $monthlyComparison = $instance->getMonthlyComparison();

        // Finnish month names
        $finnishMonths = ['Tammikuu', 'Helmikuu', 'Maaliskuu', 'Huhtikuu', 'Toukokuu',
                          'Kesäkuu', 'Heinäkuu', 'Elokuu', 'Syyskuu', 'Lokakuu',
                          'Marraskuu', 'Joulukuu'];

        $this->assertContains($monthlyComparison['current_month_name'], $finnishMonths);
        $this->assertContains($monthlyComparison['last_month_name'], $finnishMonths);
    }

    public function test_view_receives_historical_analytics_data_after_load(): void
    {
        for ($day = 13; $day <= 19; $day++) {
            $this->createFullDayPrices(2026, 1, $day, array_fill(0, 24, 10.0));
        }
        $this->createFullDayPrices(2026, 1, 20, array_fill(0, 24, 10.0));

        Livewire::test(SpotPrice::class)
            ->call('loadHistoricalData')
            ->assertViewHas('weeklyDailyAverages')
            ->assertViewHas('weeklyChartData')
            ->assertViewHas('monthlyComparison')
            ->assertViewHas('yearOverYearComparison');
    }
}
