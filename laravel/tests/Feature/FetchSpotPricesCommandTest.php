<?php

namespace Tests\Feature;

use App\Models\SpotPriceHour;
use App\Services\EleringApiClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchSpotPricesCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test command fetches spot prices from API and saves to database.
     */
    public function test_command_fetches_and_saves_spot_prices(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
        ]);

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        // Verify spot prices were created
        $this->assertDatabaseHas('spot_prices_hour', [
            'region' => 'FI',
            'timestamp' => 1705644000,
        ]);
    }

    /**
     * Test command converts prices from EUR/MWh to cents/kWh.
     */
    public function test_command_converts_price_units(): void
    {
        // API returns 50 EUR/MWh which should convert to 5.0 c/kWh
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        [
                            'timestamp' => 1705644000,
                            'price' => 50.0, // EUR/MWh
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', 1705644000)->first();
        $this->assertEquals(5.0, $spotPrice->price_without_tax);
    }

    /**
     * Test command stores correct VAT rate for standard period (24%).
     */
    public function test_command_applies_standard_vat_rate(): void
    {
        // Timestamp for 2024-01-19 10:00 UTC (standard VAT period)
        $timestamp = Carbon::create(2024, 1, 19, 10, 0, 0, 'UTC')->timestamp;

        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        [
                            'timestamp' => $timestamp,
                            'price' => 100.0, // EUR/MWh
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(0.24, $spotPrice->vat_rate);
    }

    /**
     * Test command stores reduced VAT rate for Dec 2022 - Apr 2023 (10%).
     */
    public function test_command_applies_reduced_vat_rate_for_temporary_period(): void
    {
        // Timestamp for 2023-01-15 10:00 UTC (temporary reduced VAT period)
        $timestamp = Carbon::create(2023, 1, 15, 10, 0, 0, 'UTC')->timestamp;

        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        [
                            'timestamp' => $timestamp,
                            'price' => 100.0, // EUR/MWh
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(0.10, $spotPrice->vat_rate);
    }

    /**
     * Test command uses correct start of reduced VAT period (Dec 1, 2022).
     */
    public function test_command_applies_correct_vat_at_reduced_period_start(): void
    {
        // December 1, 2022 - first day of reduced VAT
        $timestamp = Carbon::create(2022, 12, 1, 0, 0, 0, 'UTC')->timestamp;

        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        [
                            'timestamp' => $timestamp,
                            'price' => 100.0,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(0.10, $spotPrice->vat_rate);
    }

    /**
     * Test command applies standard VAT before reduced period (Nov 30, 2022).
     * Note: VAT is determined in Helsinki timezone (UTC+2 in winter).
     * Nov 30, 2022 21:00 UTC = Nov 30, 2022 23:00 Helsinki (still before Dec 1).
     */
    public function test_command_applies_standard_vat_before_reduced_period(): void
    {
        // November 30, 2022 21:00 UTC = November 30, 2022 23:00 Helsinki (before Dec 1)
        $timestamp = Carbon::create(2022, 11, 30, 21, 0, 0, 'UTC')->timestamp;

        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        [
                            'timestamp' => $timestamp,
                            'price' => 100.0,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(0.24, $spotPrice->vat_rate);
    }

    /**
     * Test command applies standard VAT after reduced period ends (May 1, 2023).
     * Note: VAT is determined in Helsinki timezone (UTC+3 in summer due to DST).
     * May 1, 2023 10:00 UTC = May 1, 2023 13:00 Helsinki (after reduced VAT period).
     */
    public function test_command_applies_standard_vat_after_reduced_period(): void
    {
        // May 1, 2023 10:00 UTC = May 1, 2023 13:00 Helsinki (clearly after May 1)
        $timestamp = Carbon::create(2023, 5, 1, 10, 0, 0, 'UTC')->timestamp;

        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        [
                            'timestamp' => $timestamp,
                            'price' => 100.0,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(0.24, $spotPrice->vat_rate);
    }

    /**
     * Test command only saves FI region data.
     */
    public function test_command_only_saves_fi_region(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        ['timestamp' => 1705644000, 'price' => 50.0],
                    ],
                    'ee' => [
                        ['timestamp' => 1705644000, 'price' => 45.0],
                    ],
                    'lv' => [
                        ['timestamp' => 1705644000, 'price' => 48.0],
                    ],
                    'lt' => [
                        ['timestamp' => 1705644000, 'price' => 47.0],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        // Only FI region should be saved
        $this->assertEquals(1, SpotPriceHour::count());
        $this->assertDatabaseHas('spot_prices_hour', ['region' => 'FI']);
        $this->assertDatabaseMissing('spot_prices_hour', ['region' => 'EE']);
        $this->assertDatabaseMissing('spot_prices_hour', ['region' => 'LV']);
        $this->assertDatabaseMissing('spot_prices_hour', ['region' => 'LT']);
    }

    /**
     * Test command stores UTC datetime correctly.
     */
    public function test_command_stores_utc_datetime(): void
    {
        $timestamp = 1705644000; // 2024-01-19 06:00:00 UTC

        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        ['timestamp' => $timestamp, 'price' => 50.0],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $expectedDatetime = Carbon::createFromTimestamp($timestamp, 'UTC');

        $this->assertEquals($expectedDatetime->toDateTimeString(), $spotPrice->utc_datetime->toDateTimeString());
    }

    /**
     * Test command handles multiple hours of data.
     */
    public function test_command_handles_multiple_hours(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        ['timestamp' => 1705644000, 'price' => 50.0],
                        ['timestamp' => 1705647600, 'price' => 55.0],
                        ['timestamp' => 1705651200, 'price' => 48.0],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        $this->assertEquals(3, SpotPriceHour::count());
    }

    /**
     * Test command skips existing records (on conflict do nothing).
     */
    public function test_command_skips_existing_records(): void
    {
        // Create an existing record
        SpotPriceHour::create([
            'region' => 'FI',
            'timestamp' => 1705644000,
            'utc_datetime' => Carbon::createFromTimestamp(1705644000, 'UTC'),
            'price_without_tax' => 4.0, // Original price
            'vat_rate' => 0.24,
        ]);

        // API returns same timestamp with different price
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        ['timestamp' => 1705644000, 'price' => 60.0], // Different price
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        // Original record should remain unchanged
        $spotPrice = SpotPriceHour::where('timestamp', 1705644000)->first();
        $this->assertEquals(4.0, $spotPrice->price_without_tax);
    }

    /**
     * Test command handles API errors gracefully.
     */
    public function test_command_handles_api_errors(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response(
                ['error' => 'Server Error'],
                500
            ),
        ]);

        $this->artisan('spotprices:fetch')
            ->assertExitCode(1);
    }

    /**
     * Test command handles empty API response.
     */
    public function test_command_handles_empty_response(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [],
                ],
            ], 200),
        ]);

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        $this->assertEquals(0, SpotPriceHour::count());
    }

    /**
     * Test command handles missing FI region in response.
     */
    public function test_command_handles_missing_fi_region(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'ee' => [
                        ['timestamp' => 1705644000, 'price' => 50.0],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        $this->assertEquals(0, SpotPriceHour::count());
    }

    /**
     * Test command retries on temporary failures.
     */
    public function test_command_retries_on_failure(): void
    {
        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                return Http::response(['error' => 'Temporary Error'], 503);
            }
            return Http::response($this->getSampleApiResponse(), 200);
        });

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        $this->assertDatabaseHas('spot_prices_hour', ['region' => 'FI']);
    }

    /**
     * Test command uses correct date range (yesterday to tomorrow).
     */
    public function test_command_uses_correct_date_range(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
        ]);

        Carbon::setTestNow(Carbon::create(2024, 1, 19, 12, 0, 0, 'UTC'));

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        // Verify the API was called with correct date range
        Http::assertSent(function ($request) {
            $start = $request->data()['start'] ?? null;
            $end = $request->data()['end'] ?? null;

            // start should be yesterday (2024-01-18)
            // end should be tomorrow (2024-01-20)
            return str_contains($start, '2024-01-18') && str_contains($end, '2024-01-20');
        });

        Carbon::setTestNow();
    }

    /**
     * Test command outputs progress information.
     */
    public function test_command_outputs_progress(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
        ]);

        $this->artisan('spotprices:fetch')
            ->expectsOutput('Fetching spot prices from Elering API...')
            ->expectsOutput('Spot prices fetched successfully!')
            ->assertExitCode(0);
    }

    /**
     * Test command handles negative prices (can occur during high renewable production).
     */
    public function test_command_handles_negative_prices(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        ['timestamp' => 1705644000, 'price' => -10.0], // Negative price in EUR/MWh
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('spotprices:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', 1705644000)->first();
        $this->assertEquals(-1.0, $spotPrice->price_without_tax); // -10 EUR/MWh = -1 c/kWh
    }

    /**
     * Get a sample API response for testing.
     */
    protected function getSampleApiResponse(): array
    {
        return [
            'data' => [
                'fi' => [
                    [
                        'timestamp' => 1705644000, // 2024-01-19 06:00:00 UTC
                        'price' => 52.35, // EUR/MWh
                    ],
                    [
                        'timestamp' => 1705647600, // 2024-01-19 07:00:00 UTC
                        'price' => 55.12,
                    ],
                    [
                        'timestamp' => 1705651200, // 2024-01-19 08:00:00 UTC
                        'price' => 48.87,
                    ],
                ],
            ],
        ];
    }
}
