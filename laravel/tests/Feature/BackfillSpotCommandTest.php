<?php

namespace Tests\Feature;

use App\Models\SpotPriceHour;
use App\Services\EntsoeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Mockery;
use Tests\TestCase;

class BackfillSpotCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 1, 20, 12, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test command has correct signature with options.
     */
    public function test_command_has_correct_signature(): void
    {
        $mockService = Mockery::mock(EntsoeService::class);
        $mockService->shouldReceive('fetchDayAheadPrices')->andReturn([]);
        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:backfill --help')
            ->expectsOutputToContain('start-date')
            ->expectsOutputToContain('end-date')
            ->expectsOutputToContain('force')
            ->assertExitCode(0);
    }

    /**
     * Test command defaults to 1 year back when no dates specified.
     */
    public function test_command_defaults_to_one_year_back(): void
    {
        $mockService = Mockery::mock(EntsoeService::class);

        // Should fetch from ~1 year ago to today in monthly chunks
        // Expect roughly 12-13 API calls (one per month)
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->atLeast()->times(12)
            ->andReturn([]);

        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:backfill')
            ->assertExitCode(0);
    }

    /**
     * Test command accepts start-date option.
     */
    public function test_command_accepts_start_date_option(): void
    {
        $mockService = Mockery::mock(EntsoeService::class);
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->withArgs(function (Carbon $start, Carbon $end) {
                // Start should be January 1, 2026
                return $start->format('Y-m-d') === '2026-01-01';
            })
            ->once()
            ->andReturn([]);

        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:backfill --start-date=2026-01-01')
            ->assertExitCode(0);
    }

    /**
     * Test command accepts end-date option.
     */
    public function test_command_accepts_end_date_option(): void
    {
        $mockService = Mockery::mock(EntsoeService::class);
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->withArgs(function (Carbon $start, Carbon $end) {
                // End should be January 10, 2026 + 1 day (to include full day)
                return $end->format('Y-m-d') === '2026-01-11';
            })
            ->once()
            ->andReturn([]);

        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:backfill --start-date=2026-01-01 --end-date=2026-01-10')
            ->assertExitCode(0);
    }

    /**
     * Test command fetches prices in monthly chunks.
     */
    public function test_command_fetches_in_monthly_chunks(): void
    {
        $mockService = Mockery::mock(EntsoeService::class);

        // 2-month period (Nov + Dec) should result in 2 API calls
        $callCount = 0;
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->times(2)
            ->withArgs(function (Carbon $start, Carbon $end) use (&$callCount) {
                $callCount++;

                // Verify chunk sizes are roughly monthly
                $days = $start->diffInDays($end);
                return $days >= 28 && $days <= 32; // Approximately one month
            })
            ->andReturn([]);

        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:backfill --start-date=2025-11-01 --end-date=2025-12-31')
            ->assertExitCode(0);
    }

    /**
     * Test command saves fetched prices to database.
     */
    public function test_command_saves_prices_to_database(): void
    {
        $timestamp1 = Carbon::create(2025, 12, 1, 10, 0, 0, 'UTC')->timestamp;
        $timestamp2 = Carbon::create(2025, 12, 1, 11, 0, 0, 'UTC')->timestamp;

        $mockService = Mockery::mock(EntsoeService::class);
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->once()
            ->andReturn([
                [
                    'region' => 'FI',
                    'timestamp' => $timestamp1,
                    'utc_datetime' => Carbon::createFromTimestamp($timestamp1, 'UTC'),
                    'price_without_tax' => 5.235,
                ],
                [
                    'region' => 'FI',
                    'timestamp' => $timestamp2,
                    'utc_datetime' => Carbon::createFromTimestamp($timestamp2, 'UTC'),
                    'price_without_tax' => 5.512,
                ],
            ]);

        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:backfill --start-date=2025-12-01 --end-date=2025-12-02')
            ->assertExitCode(0);

        $this->assertDatabaseHas('spot_prices_hour', [
            'region' => 'FI',
            'timestamp' => $timestamp1,
        ]);
        $this->assertDatabaseHas('spot_prices_hour', [
            'region' => 'FI',
            'timestamp' => $timestamp2,
        ]);
    }

    /**
     * Test command skips dates that already have data.
     */
    public function test_command_skips_existing_data_by_default(): void
    {
        // Create existing data for December 2025
        $existingTimestamp = Carbon::create(2025, 12, 15, 10, 0, 0, 'UTC')->timestamp;
        SpotPriceHour::create([
            'region' => 'FI',
            'timestamp' => $existingTimestamp,
            'utc_datetime' => Carbon::createFromTimestamp($existingTimestamp, 'UTC'),
            'price_without_tax' => 4.0,
            'vat_rate' => 0.255,
        ]);

        $mockService = Mockery::mock(EntsoeService::class);

        // When a month already has data, the command should skip fetching it
        // We're fetching December only, and it has data, so should be skipped
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->never();

        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:backfill --start-date=2025-12-01 --end-date=2025-12-31')
            ->assertExitCode(0);

        // Original record should remain unchanged
        $spotPrice = SpotPriceHour::where('timestamp', $existingTimestamp)->first();
        $this->assertEquals(4.0, $spotPrice->price_without_tax);
    }

    /**
     * Test command fetches data with --force even when data exists.
     */
    public function test_command_fetches_with_force_flag(): void
    {
        // Create existing data
        $existingTimestamp = Carbon::create(2025, 12, 15, 10, 0, 0, 'UTC')->timestamp;
        SpotPriceHour::create([
            'region' => 'FI',
            'timestamp' => $existingTimestamp,
            'utc_datetime' => Carbon::createFromTimestamp($existingTimestamp, 'UTC'),
            'price_without_tax' => 4.0,
            'vat_rate' => 0.255,
        ]);

        $newTimestamp = Carbon::create(2025, 12, 15, 11, 0, 0, 'UTC')->timestamp;

        $mockService = Mockery::mock(EntsoeService::class);
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->once()
            ->andReturn([
                [
                    'region' => 'FI',
                    'timestamp' => $newTimestamp,
                    'utc_datetime' => Carbon::createFromTimestamp($newTimestamp, 'UTC'),
                    'price_without_tax' => 5.0,
                ],
            ]);

        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:backfill --start-date=2025-12-01 --end-date=2025-12-31 --force')
            ->assertExitCode(0);

        // New record should be added
        $this->assertDatabaseHas('spot_prices_hour', [
            'region' => 'FI',
            'timestamp' => $newTimestamp,
        ]);
    }

    /**
     * Test command shows progress bar for long operations.
     */
    public function test_command_shows_progress_output(): void
    {
        $mockService = Mockery::mock(EntsoeService::class);
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->atLeast()->once()
            ->andReturn([]);

        $this->app->instance(EntsoeService::class, $mockService);

        // Progress should show for multi-month operations
        $this->artisan('spot:backfill --start-date=2025-10-01 --end-date=2026-01-01')
            ->expectsOutputToContain('Backfilling')
            ->assertExitCode(0);
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

        // Should continue and report error but not crash
        $this->artisan('spot:backfill --start-date=2026-01-01 --end-date=2026-01-15')
            ->expectsOutputToContain('Error')
            ->assertExitCode(0); // Still exit 0 as it processes what it can
    }

    /**
     * Test command continues after API error on one chunk.
     */
    public function test_command_continues_after_error_on_chunk(): void
    {
        $timestamp = Carbon::create(2025, 12, 10, 10, 0, 0, 'UTC')->timestamp;

        $mockService = Mockery::mock(EntsoeService::class);

        // First chunk fails
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->once()
            ->andThrow(new RequestException(
                new \Illuminate\Http\Client\Response(
                    new \GuzzleHttp\Psr7\Response(500, [], 'Server Error')
                )
            ));

        // Second chunk succeeds
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->once()
            ->andReturn([
                [
                    'region' => 'FI',
                    'timestamp' => $timestamp,
                    'utc_datetime' => Carbon::createFromTimestamp($timestamp, 'UTC'),
                    'price_without_tax' => 5.0,
                ],
            ]);

        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:backfill --start-date=2025-11-01 --end-date=2025-12-31')
            ->assertExitCode(0);

        // Second chunk data should be saved
        $this->assertDatabaseHas('spot_prices_hour', [
            'region' => 'FI',
            'timestamp' => $timestamp,
        ]);
    }

    /**
     * Test command sets correct VAT rates based on date.
     */
    public function test_command_sets_correct_vat_rates(): void
    {
        // Three timestamps in different VAT periods
        $reducedVatTimestamp = Carbon::create(2023, 2, 15, 10, 0, 0, 'UTC')->timestamp;
        $standardVatTimestamp = Carbon::create(2024, 6, 15, 10, 0, 0, 'UTC')->timestamp;
        $increasedVatTimestamp = Carbon::create(2025, 1, 15, 10, 0, 0, 'UTC')->timestamp;

        $mockService = Mockery::mock(EntsoeService::class);
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->andReturn([
                [
                    'region' => 'FI',
                    'timestamp' => $reducedVatTimestamp,
                    'utc_datetime' => Carbon::createFromTimestamp($reducedVatTimestamp, 'UTC'),
                    'price_without_tax' => 5.0,
                ],
                [
                    'region' => 'FI',
                    'timestamp' => $standardVatTimestamp,
                    'utc_datetime' => Carbon::createFromTimestamp($standardVatTimestamp, 'UTC'),
                    'price_without_tax' => 5.0,
                ],
                [
                    'region' => 'FI',
                    'timestamp' => $increasedVatTimestamp,
                    'utc_datetime' => Carbon::createFromTimestamp($increasedVatTimestamp, 'UTC'),
                    'price_without_tax' => 5.0,
                ],
            ]);

        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:backfill --start-date=2023-01-01 --end-date=2025-02-01 --force')
            ->assertExitCode(0);

        // Check VAT rates
        $reducedVatPrice = SpotPriceHour::where('timestamp', $reducedVatTimestamp)->first();
        $standardVatPrice = SpotPriceHour::where('timestamp', $standardVatTimestamp)->first();
        $increasedVatPrice = SpotPriceHour::where('timestamp', $increasedVatTimestamp)->first();

        $this->assertEquals(0.10, $reducedVatPrice->vat_rate);
        $this->assertEquals(0.24, $standardVatPrice->vat_rate);
        $this->assertEquals(0.255, $increasedVatPrice->vat_rate);
    }

    /**
     * Test command reports total records processed.
     */
    public function test_command_reports_total_records(): void
    {
        $prices = [];
        for ($i = 0; $i < 5; $i++) {
            $timestamp = Carbon::create(2025, 12, 1, $i, 0, 0, 'UTC')->timestamp;
            $prices[] = [
                'region' => 'FI',
                'timestamp' => $timestamp,
                'utc_datetime' => Carbon::createFromTimestamp($timestamp, 'UTC'),
                'price_without_tax' => 5.0 + $i,
            ];
        }

        $mockService = Mockery::mock(EntsoeService::class);
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->once()
            ->andReturn($prices);

        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:backfill --start-date=2025-12-01 --end-date=2025-12-15')
            ->expectsOutputToContain('5')
            ->assertExitCode(0);
    }

    /**
     * Test command handles empty response from API.
     */
    public function test_command_handles_empty_response(): void
    {
        $mockService = Mockery::mock(EntsoeService::class);
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->once()
            ->andReturn([]);

        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:backfill --start-date=2026-01-01 --end-date=2026-01-15')
            ->assertExitCode(0);

        $this->assertEquals(0, SpotPriceHour::count());
    }

    /**
     * Test command validates date format.
     */
    public function test_command_validates_date_format(): void
    {
        $mockService = Mockery::mock(EntsoeService::class);
        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:backfill --start-date=invalid-date')
            ->expectsOutputToContain('Invalid')
            ->assertExitCode(1);
    }

    /**
     * Test command validates start date is before end date.
     */
    public function test_command_validates_start_before_end(): void
    {
        $mockService = Mockery::mock(EntsoeService::class);
        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:backfill --start-date=2026-01-15 --end-date=2026-01-01')
            ->expectsOutputToContain('before')
            ->assertExitCode(1);
    }

    /**
     * Test command adds delay between API calls.
     */
    public function test_command_adds_delay_between_api_calls(): void
    {
        $mockService = Mockery::mock(EntsoeService::class);

        // Track call times
        $callTimes = [];
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->times(2)
            ->andReturnUsing(function () use (&$callTimes) {
                $callTimes[] = microtime(true);
                return [];
            });

        $this->app->instance(EntsoeService::class, $mockService);

        // 2-month period generates exactly 2 API calls
        $this->artisan('spot:backfill --start-date=2025-11-01 --end-date=2025-12-31')
            ->assertExitCode(0);

        // There should be some delay between calls (at least 100ms)
        if (count($callTimes) >= 2) {
            $delay = $callTimes[1] - $callTimes[0];
            $this->assertGreaterThanOrEqual(0.1, $delay);
        }
    }

    /**
     * Test command uses insertOrIgnore to avoid duplicates.
     */
    public function test_command_uses_insert_or_ignore(): void
    {
        $timestamp = Carbon::create(2025, 12, 1, 10, 0, 0, 'UTC')->timestamp;

        // Create existing record
        SpotPriceHour::create([
            'region' => 'FI',
            'timestamp' => $timestamp,
            'utc_datetime' => Carbon::createFromTimestamp($timestamp, 'UTC'),
            'price_without_tax' => 4.0,
            'vat_rate' => 0.255,
        ]);

        $mockService = Mockery::mock(EntsoeService::class);
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->once()
            ->andReturn([
                [
                    'region' => 'FI',
                    'timestamp' => $timestamp,
                    'utc_datetime' => Carbon::createFromTimestamp($timestamp, 'UTC'),
                    'price_without_tax' => 6.0, // Different price
                ],
            ]);

        $this->app->instance(EntsoeService::class, $mockService);

        // Run with --force to actually fetch, but insertOrIgnore should keep original
        $this->artisan('spot:backfill --start-date=2025-12-01 --end-date=2025-12-15 --force')
            ->assertExitCode(0);

        // Original price should be preserved
        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(4.0, $spotPrice->price_without_tax);
        $this->assertEquals(1, SpotPriceHour::count());
    }

    /**
     * Test command handles negative prices (common during high renewable output).
     */
    public function test_command_handles_negative_prices(): void
    {
        $timestamp = Carbon::create(2025, 12, 1, 10, 0, 0, 'UTC')->timestamp;

        $mockService = Mockery::mock(EntsoeService::class);
        $mockService->shouldReceive('fetchDayAheadPrices')
            ->once()
            ->andReturn([
                [
                    'region' => 'FI',
                    'timestamp' => $timestamp,
                    'utc_datetime' => Carbon::createFromTimestamp($timestamp, 'UTC'),
                    'price_without_tax' => -2.5, // Negative price
                ],
            ]);

        $this->app->instance(EntsoeService::class, $mockService);

        $this->artisan('spot:backfill --start-date=2025-12-01 --end-date=2025-12-15')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(-2.5, $spotPrice->price_without_tax);
    }
}
