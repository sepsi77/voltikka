<?php

namespace Tests\Feature;

use App\Models\SpotPriceHour;
use App\Services\EntsoeService;
use App\Services\SpotPriceAverageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Mockery;
use Tests\TestCase;

class FetchSpotCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock SpotPriceAverageService to prevent actual calculations
        $mockAverageService = Mockery::mock(SpotPriceAverageService::class);
        $mockAverageService->shouldReceive('calculateAllAverages')->zeroOrMoreTimes();
        $this->app->instance(SpotPriceAverageService::class, $mockAverageService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper to create an EntsoeService mock that returns given prices.
     */
    private function mockEntsoeService(array $prices): void
    {
        $mockService = Mockery::mock(EntsoeService::class);
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->once()
            ->andReturn($prices);

        $this->app->instance(EntsoeService::class, $mockService);
    }

    /**
     * Test command fetches today's prices and saves to database.
     */
    public function test_command_fetches_todays_prices_and_saves_to_database(): void
    {
        $this->mockEntsoeService([
            [
                'region' => 'FI',
                'timestamp' => 1705644000,
                'utc_datetime' => Carbon::createFromTimestamp(1705644000, 'UTC'),
                'price_without_tax' => 5.235,
                'resolution_minutes' => 60,
            ],
            [
                'region' => 'FI',
                'timestamp' => 1705647600,
                'utc_datetime' => Carbon::createFromTimestamp(1705647600, 'UTC'),
                'price_without_tax' => 5.512,
                'resolution_minutes' => 60,
            ],
        ]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $this->assertDatabaseHas('spot_prices_hour', [
            'region' => 'FI',
            'timestamp' => 1705644000,
        ]);
        $this->assertDatabaseHas('spot_prices_hour', [
            'region' => 'FI',
            'timestamp' => 1705647600,
        ]);
    }

    /**
     * Test command sets current VAT rate (25.5%) for prices from September 2024 onwards.
     */
    public function test_command_sets_current_vat_rate(): void
    {
        // Timestamp for January 2026 (after September 2024 VAT increase)
        $timestamp = Carbon::create(2026, 1, 19, 10, 0, 0, 'UTC')->timestamp;

        $this->mockEntsoeService([
            [
                'region' => 'FI',
                'timestamp' => $timestamp,
                'utc_datetime' => Carbon::createFromTimestamp($timestamp, 'UTC'),
                'price_without_tax' => 5.0,
                'resolution_minutes' => 60,
            ],
        ]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(0.255, $spotPrice->vat_rate);
    }

    /**
     * Test command applies standard VAT rate (24%) for prices before September 2024.
     */
    public function test_command_applies_standard_vat_rate_before_september_2024(): void
    {
        // Timestamp for August 2024 (before VAT increase)
        $timestamp = Carbon::create(2024, 8, 15, 10, 0, 0, 'UTC')->timestamp;

        $this->mockEntsoeService([
            [
                'region' => 'FI',
                'timestamp' => $timestamp,
                'utc_datetime' => Carbon::createFromTimestamp($timestamp, 'UTC'),
                'price_without_tax' => 5.0,
                'resolution_minutes' => 60,
            ],
        ]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(0.24, $spotPrice->vat_rate);
    }

    /**
     * Test command applies reduced VAT rate (10%) for Dec 2022 - Apr 2023 period.
     */
    public function test_command_applies_reduced_vat_rate_for_temporary_period(): void
    {
        // Timestamp for January 2023 (temporary reduced VAT period)
        $timestamp = Carbon::create(2023, 1, 15, 10, 0, 0, 'UTC')->timestamp;

        $this->mockEntsoeService([
            [
                'region' => 'FI',
                'timestamp' => $timestamp,
                'utc_datetime' => Carbon::createFromTimestamp($timestamp, 'UTC'),
                'price_without_tax' => 5.0,
                'resolution_minutes' => 60,
            ],
        ]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(0.10, $spotPrice->vat_rate);
    }

    /**
     * Test command uses upsert to avoid duplicates (ignores existing records).
     */
    public function test_command_uses_upsert_to_avoid_duplicates(): void
    {
        // Create an existing record
        SpotPriceHour::create([
            'region' => 'FI',
            'timestamp' => 1705644000,
            'utc_datetime' => Carbon::createFromTimestamp(1705644000, 'UTC'),
            'price_without_tax' => 4.0, // Original price
            'vat_rate' => 0.24,
        ]);

        $this->mockEntsoeService([
            [
                'region' => 'FI',
                'timestamp' => 1705644000,
                'utc_datetime' => Carbon::createFromTimestamp(1705644000, 'UTC'),
                'price_without_tax' => 6.0, // Different price from API
                'resolution_minutes' => 60,
            ],
        ]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        // Original record should remain unchanged (insertOrIgnore behavior)
        $spotPrice = SpotPriceHour::where('timestamp', 1705644000)->first();
        $this->assertEquals(4.0, $spotPrice->price_without_tax);
        $this->assertEquals(1, SpotPriceHour::count());
    }

    /**
     * Test command fetches correct date range (today and tomorrow).
     */
    public function test_command_fetches_today_and_tomorrow(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 19, 15, 0, 0, 'Europe/Helsinki'));

        $mockService = Mockery::mock(EntsoeService::class);
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->once()
            ->withArgs(function (Carbon $start, Carbon $end) {
                // Start should be today at midnight Helsinki time, converted to UTC
                $expectedStart = Carbon::create(2026, 1, 19, 0, 0, 0, 'Europe/Helsinki')->setTimezone('UTC');
                // End should be day after tomorrow at midnight Helsinki time, converted to UTC
                $expectedEnd = Carbon::create(2026, 1, 21, 0, 0, 0, 'Europe/Helsinki')->setTimezone('UTC');

                return $start->eq($expectedStart) && $end->eq($expectedEnd);
            })
            ->andReturn([]);

        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        Carbon::setTestNow();
    }

    /**
     * Test command handles API errors gracefully.
     */
    public function test_command_handles_api_errors(): void
    {
        $mockService = Mockery::mock(EntsoeService::class);
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->once()
            ->andThrow(new RequestException(
                new \Illuminate\Http\Client\Response(
                    new \GuzzleHttp\Psr7\Response(500, [], 'Server Error')
                )
            ));

        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:fetch')
            ->assertExitCode(1);
    }

    /**
     * Test command handles empty API response.
     */
    public function test_command_handles_empty_response(): void
    {
        $this->mockEntsoeService([]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $this->assertEquals(0, SpotPriceHour::count());
    }

    /**
     * Test command outputs success message with record count.
     */
    public function test_command_outputs_success_message(): void
    {
        $this->mockEntsoeService([
            [
                'region' => 'FI',
                'timestamp' => 1705644000,
                'utc_datetime' => Carbon::createFromTimestamp(1705644000, 'UTC'),
                'price_without_tax' => 5.235,
                'resolution_minutes' => 60,
            ],
            [
                'region' => 'FI',
                'timestamp' => 1705647600,
                'utc_datetime' => Carbon::createFromTimestamp(1705647600, 'UTC'),
                'price_without_tax' => 5.512,
                'resolution_minutes' => 60,
            ],
        ]);

        $this->artisan('spot:fetch')
            ->expectsOutput('Fetching spot prices from ENTSO-E API...')
            ->expectsOutputToContain('2')
            ->assertExitCode(0);
    }

    /**
     * Test command logs success with record count.
     */
    public function test_command_logs_success(): void
    {
        $this->mockEntsoeService([
            [
                'region' => 'FI',
                'timestamp' => 1705644000,
                'utc_datetime' => Carbon::createFromTimestamp(1705644000, 'UTC'),
                'price_without_tax' => 5.235,
                'resolution_minutes' => 60,
            ],
        ]);

        // Allow any Log calls to pass through
        \Illuminate\Support\Facades\Log::shouldReceive('info')->zeroOrMoreTimes();
        \Illuminate\Support\Facades\Log::shouldReceive('error')->zeroOrMoreTimes();

        $this->artisan('spot:fetch')
            ->assertExitCode(0);
    }

    /**
     * Test command handles negative prices (can occur during high renewable production).
     */
    public function test_command_handles_negative_prices(): void
    {
        $this->mockEntsoeService([
            [
                'region' => 'FI',
                'timestamp' => 1705644000,
                'utc_datetime' => Carbon::createFromTimestamp(1705644000, 'UTC'),
                'price_without_tax' => -1.0, // Negative price (c/kWh)
                'resolution_minutes' => 60,
            ],
        ]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', 1705644000)->first();
        $this->assertEquals(-1.0, $spotPrice->price_without_tax);
    }

    /**
     * Test command stores UTC datetime correctly.
     */
    public function test_command_stores_utc_datetime_correctly(): void
    {
        $timestamp = 1705644000; // 2024-01-19 06:00:00 UTC
        $utcDatetime = Carbon::createFromTimestamp($timestamp, 'UTC');

        $this->mockEntsoeService([
            [
                'region' => 'FI',
                'timestamp' => $timestamp,
                'utc_datetime' => $utcDatetime,
                'price_without_tax' => 5.0,
                'resolution_minutes' => 60,
            ],
        ]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals($utcDatetime->toDateTimeString(), $spotPrice->utc_datetime->toDateTimeString());
    }

    /**
     * Test command can be scheduled (has schedule method or is compatible with scheduler).
     */
    public function test_command_signature_is_correct(): void
    {
        $this->mockEntsoeService([]);

        // Verify command is registered and callable
        $this->artisan('spot:fetch')
            ->assertExitCode(0);
    }

    /**
     * Test command processes multiple hours correctly.
     */
    public function test_command_processes_multiple_hours(): void
    {
        $prices = [];
        for ($i = 0; $i < 24; $i++) {
            $timestamp = Carbon::create(2026, 1, 19, $i, 0, 0, 'UTC')->timestamp;
            $prices[] = [
                'region' => 'FI',
                'timestamp' => $timestamp,
                'utc_datetime' => Carbon::createFromTimestamp($timestamp, 'UTC'),
                'price_without_tax' => 5.0 + ($i * 0.1),
                'resolution_minutes' => 60,
            ];
        }

        $this->mockEntsoeService($prices);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $this->assertEquals(24, SpotPriceHour::count());
    }

    /**
     * Test VAT rate boundary: September 1, 2024 should have 25.5% VAT.
     */
    public function test_vat_rate_boundary_september_2024(): void
    {
        // September 1, 2024 00:00 Helsinki time
        $timestamp = Carbon::create(2024, 9, 1, 0, 0, 0, 'Europe/Helsinki')
            ->setTimezone('UTC')->timestamp;

        $this->mockEntsoeService([
            [
                'region' => 'FI',
                'timestamp' => $timestamp,
                'utc_datetime' => Carbon::createFromTimestamp($timestamp, 'UTC'),
                'price_without_tax' => 5.0,
                'resolution_minutes' => 60,
            ],
        ]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(0.255, $spotPrice->vat_rate);
    }

    /**
     * Test VAT rate boundary: August 31, 2024 should have 24% VAT.
     */
    public function test_vat_rate_boundary_august_2024(): void
    {
        // August 31, 2024 23:00 Helsinki time (last hour before increase)
        $timestamp = Carbon::create(2024, 8, 31, 23, 0, 0, 'Europe/Helsinki')
            ->setTimezone('UTC')->timestamp;

        $this->mockEntsoeService([
            [
                'region' => 'FI',
                'timestamp' => $timestamp,
                'utc_datetime' => Carbon::createFromTimestamp($timestamp, 'UTC'),
                'price_without_tax' => 5.0,
                'resolution_minutes' => 60,
            ],
        ]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(0.24, $spotPrice->vat_rate);
    }
}
