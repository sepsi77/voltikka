<?php

namespace Tests\Unit;

use App\Services\EleringApiClient;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EleringApiClientTest extends TestCase
{
    private EleringApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new EleringApiClient();
    }

    /**
     * Test client fetches spot prices from Elering API.
     */
    public function test_fetch_spot_prices_returns_data(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        ['timestamp' => 1705644000, 'price' => 50.0],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->fetchSpotPrices();

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('FI', $result[0]['region']);
    }

    /**
     * Test client converts prices from EUR/MWh to c/kWh.
     */
    public function test_converts_price_to_cents_per_kwh(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        ['timestamp' => 1705644000, 'price' => 100.0], // 100 EUR/MWh
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->fetchSpotPrices();

        // 100 EUR/MWh = 100 * 100 / 1000 = 10 c/kWh
        $this->assertEquals(10.0, $result[0]['price_without_tax']);
    }

    /**
     * Test client stores region in uppercase.
     */
    public function test_stores_region_in_uppercase(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        ['timestamp' => 1705644000, 'price' => 50.0],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->fetchSpotPrices();

        $this->assertEquals('FI', $result[0]['region']);
    }

    /**
     * Test client includes timestamp.
     */
    public function test_includes_timestamp(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        ['timestamp' => 1705644000, 'price' => 50.0],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->fetchSpotPrices();

        $this->assertEquals(1705644000, $result[0]['timestamp']);
    }

    /**
     * Test client includes UTC datetime.
     */
    public function test_includes_utc_datetime(): void
    {
        $timestamp = 1705644000;

        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        ['timestamp' => $timestamp, 'price' => 50.0],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->fetchSpotPrices();

        $expectedDatetime = Carbon::createFromTimestamp($timestamp, 'UTC');
        $this->assertEquals($expectedDatetime->toDateTimeString(), $result[0]['utc_datetime']->toDateTimeString());
    }

    /**
     * Test client calculates standard VAT rate (24%).
     */
    public function test_calculates_standard_vat_rate(): void
    {
        $timestamp = Carbon::create(2024, 1, 19, 10, 0, 0, 'UTC')->timestamp;

        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        ['timestamp' => $timestamp, 'price' => 50.0],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->fetchSpotPrices();

        $this->assertEquals(0.24, $result[0]['vat_rate']);
    }

    /**
     * Test client calculates reduced VAT rate for Dec 2022 - Apr 2023 (10%).
     */
    public function test_calculates_reduced_vat_rate_during_temporary_period(): void
    {
        $timestamp = Carbon::create(2023, 1, 15, 10, 0, 0, 'UTC')->timestamp;

        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        ['timestamp' => $timestamp, 'price' => 50.0],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->fetchSpotPrices();

        $this->assertEquals(0.10, $result[0]['vat_rate']);
    }

    /**
     * Test client filters only FI region.
     */
    public function test_filters_only_fi_region(): void
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
                ],
            ], 200),
        ]);

        $result = $this->client->fetchSpotPrices();

        $this->assertCount(1, $result);
        $this->assertEquals('FI', $result[0]['region']);
    }

    /**
     * Test client handles multiple hours of data.
     */
    public function test_handles_multiple_hours(): void
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

        $result = $this->client->fetchSpotPrices();

        $this->assertCount(3, $result);
    }

    /**
     * Test client throws exception on API error.
     */
    public function test_throws_exception_on_api_error(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response(
                ['error' => 'Server Error'],
                500
            ),
        ]);

        $this->expectException(RequestException::class);
        $this->client->fetchSpotPrices();
    }

    /**
     * Test client returns empty array when FI region is missing.
     */
    public function test_returns_empty_when_fi_region_missing(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'ee' => [
                        ['timestamp' => 1705644000, 'price' => 45.0],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->fetchSpotPrices();

        $this->assertCount(0, $result);
    }

    /**
     * Test client returns empty array when FI data is empty.
     */
    public function test_returns_empty_when_fi_data_empty(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [],
                ],
            ], 200),
        ]);

        $result = $this->client->fetchSpotPrices();

        $this->assertCount(0, $result);
    }

    /**
     * Test client handles negative prices.
     */
    public function test_handles_negative_prices(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [
                        ['timestamp' => 1705644000, 'price' => -20.0], // -20 EUR/MWh
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->fetchSpotPrices();

        $this->assertEquals(-2.0, $result[0]['price_without_tax']); // -2 c/kWh
    }

    /**
     * Test client retries on server errors.
     */
    public function test_retries_on_server_error(): void
    {
        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                return Http::response(['error' => 'Temporary Error'], 503);
            }
            return Http::response([
                'data' => [
                    'fi' => [
                        ['timestamp' => 1705644000, 'price' => 50.0],
                    ],
                ],
            ], 200);
        });

        $result = $this->client->fetchSpotPrices();

        $this->assertCount(1, $result);
        $this->assertEquals(3, $attempts);
    }

    /**
     * Test client uses correct date range parameters.
     */
    public function test_uses_correct_date_range(): void
    {
        Http::fake([
            'dashboard.elering.ee/api/nps/price*' => Http::response([
                'data' => [
                    'fi' => [],
                ],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::create(2024, 1, 19, 12, 0, 0, 'UTC'));

        $this->client->fetchSpotPrices();

        Http::assertSent(function ($request) {
            $url = $request->url();
            return str_contains($url, 'dashboard.elering.ee/api/nps/price')
                && str_contains($url, 'start=')
                && str_contains($url, 'end=');
        });

        Carbon::setTestNow();
    }
}
