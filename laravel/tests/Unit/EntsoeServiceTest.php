<?php

namespace Tests\Unit;

use App\Services\EntsoeService;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntsoeServiceTest extends TestCase
{
    private EntsoeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EntsoeService();
    }

    /**
     * Sample ENTSO-E XML response for day-ahead prices.
     */
    private function getSampleXmlResponse(array $prices, string $start = '2024-01-19T23:00Z', string $resolution = 'PT60M'): string
    {
        $points = '';
        $position = 1;
        foreach ($prices as $price) {
            $points .= "<Point><position>{$position}</position><price.amount>{$price}</price.amount></Point>";
            $position++;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Publication_MarketDocument xmlns="urn:iec62325.351:tc57wg16:451-3:publicationdocument:7:3">
    <TimeSeries>
        <mRID>1</mRID>
        <businessType>A62</businessType>
        <in_Domain.mRID codingScheme="A01">10YFI-1--------U</in_Domain.mRID>
        <out_Domain.mRID codingScheme="A01">10YFI-1--------U</out_Domain.mRID>
        <currency_Unit.name>EUR</currency_Unit.name>
        <price_Measure_Unit.name>MWH</price_Measure_Unit.name>
        <curveType>A01</curveType>
        <Period>
            <timeInterval>
                <start>{$start}</start>
                <end>2024-01-20T23:00Z</end>
            </timeInterval>
            <resolution>{$resolution}</resolution>
            {$points}
        </Period>
    </TimeSeries>
</Publication_MarketDocument>
XML;
    }

    /**
     * Sample ENTSO-E XML response with 15-minute resolution.
     */
    private function get15MinXmlResponse(array $prices, string $start = '2024-01-19T23:00Z'): string
    {
        $points = '';
        $position = 1;
        foreach ($prices as $price) {
            $points .= "<Point><position>{$position}</position><price.amount>{$price}</price.amount></Point>";
            $position++;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Publication_MarketDocument xmlns="urn:iec62325.351:tc57wg16:451-3:publicationdocument:7:3">
    <TimeSeries>
        <mRID>1</mRID>
        <businessType>A62</businessType>
        <in_Domain.mRID codingScheme="A01">10YFI-1--------U</in_Domain.mRID>
        <out_Domain.mRID codingScheme="A01">10YFI-1--------U</out_Domain.mRID>
        <currency_Unit.name>EUR</currency_Unit.name>
        <price_Measure_Unit.name>MWH</price_Measure_Unit.name>
        <curveType>A01</curveType>
        <Period>
            <timeInterval>
                <start>{$start}</start>
                <end>2024-01-20T23:00Z</end>
            </timeInterval>
            <resolution>PT15M</resolution>
            {$points}
        </Period>
    </TimeSeries>
</Publication_MarketDocument>
XML;
    }

    /**
     * Test service fetches day-ahead prices from ENTSO-E API.
     */
    public function test_fetches_day_ahead_prices(): void
    {
        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                $this->getSampleXmlResponse([50.0, 55.0, 60.0]),
                200
            ),
        ]);

        $result = $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
    }

    /**
     * Test service converts prices from EUR/MWh to c/kWh.
     */
    public function test_converts_price_to_cents_per_kwh(): void
    {
        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                $this->getSampleXmlResponse([100.0]), // 100 EUR/MWh
                200
            ),
        ]);

        $result = $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        // 100 EUR/MWh = 10 c/kWh (divide by 10)
        $this->assertEquals(10.0, $result[0]['price_without_tax']);
    }

    /**
     * Test service includes unix timestamp.
     */
    public function test_includes_unix_timestamp(): void
    {
        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                $this->getSampleXmlResponse([50.0], '2024-01-19T23:00Z'),
                200
            ),
        ]);

        $result = $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        // First hour starts at 2024-01-19T23:00Z
        $expectedTimestamp = Carbon::parse('2024-01-19T23:00Z')->timestamp;
        $this->assertEquals($expectedTimestamp, $result[0]['timestamp']);
    }

    /**
     * Test service includes UTC datetime as Carbon.
     */
    public function test_includes_utc_datetime_as_carbon(): void
    {
        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                $this->getSampleXmlResponse([50.0], '2024-01-19T23:00Z'),
                200
            ),
        ]);

        $result = $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        $this->assertInstanceOf(Carbon::class, $result[0]['utc_datetime']);
        $this->assertEquals('2024-01-19 23:00:00', $result[0]['utc_datetime']->toDateTimeString());
    }

    /**
     * Test service handles multiple hours with correct timestamps.
     */
    public function test_handles_multiple_hours_with_correct_timestamps(): void
    {
        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                $this->getSampleXmlResponse([50.0, 55.0, 60.0], '2024-01-19T23:00Z'),
                200
            ),
        ]);

        $result = $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        $this->assertCount(3, $result);

        // Check timestamps increment by 1 hour
        $this->assertEquals('2024-01-19 23:00:00', $result[0]['utc_datetime']->toDateTimeString());
        $this->assertEquals('2024-01-20 00:00:00', $result[1]['utc_datetime']->toDateTimeString());
        $this->assertEquals('2024-01-20 01:00:00', $result[2]['utc_datetime']->toDateTimeString());
    }

    /**
     * Test service handles negative prices.
     */
    public function test_handles_negative_prices(): void
    {
        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                $this->getSampleXmlResponse([-20.0]), // -20 EUR/MWh
                200
            ),
        ]);

        $result = $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        // -20 EUR/MWh = -2 c/kWh
        $this->assertEquals(-2.0, $result[0]['price_without_tax']);
    }

    /**
     * Test service returns raw 15-minute resolution data without aggregation.
     */
    public function test_returns_raw_15_minute_resolution_data(): void
    {
        // 4 prices at 15-min resolution: 40, 50, 60, 70 EUR/MWh = 4, 5, 6, 7 c/kWh
        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                $this->get15MinXmlResponse([40.0, 50.0, 60.0, 70.0], '2024-01-19T23:00Z'),
                200
            ),
        ]);

        $result = $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        // Returns raw 15-minute data (4 records, not aggregated to 1 hour)
        $this->assertCount(4, $result);
        $this->assertEquals(4.0, $result[0]['price_without_tax']);
        $this->assertEquals(5.0, $result[1]['price_without_tax']);
        $this->assertEquals(6.0, $result[2]['price_without_tax']);
        $this->assertEquals(7.0, $result[3]['price_without_tax']);
    }

    /**
     * Test service returns multiple hours of 15-minute resolution data.
     */
    public function test_returns_multiple_hours_of_15_minute_data(): void
    {
        // 8 prices at 15-min resolution for 2 hours
        // Hour 1: 40, 40, 40, 40 = 4 c/kWh each
        // Hour 2: 80, 80, 80, 80 = 8 c/kWh each
        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                $this->get15MinXmlResponse([40.0, 40.0, 40.0, 40.0, 80.0, 80.0, 80.0, 80.0], '2024-01-19T23:00Z'),
                200
            ),
        ]);

        $result = $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        // Returns raw 15-minute data (8 records, not aggregated to 2 hours)
        $this->assertCount(8, $result);
        $this->assertEquals(4.0, $result[0]['price_without_tax']);
        $this->assertEquals(4.0, $result[3]['price_without_tax']);
        $this->assertEquals(8.0, $result[4]['price_without_tax']);
        $this->assertEquals(8.0, $result[7]['price_without_tax']);
    }

    /**
     * Test service uses config for API key.
     */
    public function test_uses_config_api_key(): void
    {
        config(['services.entsoe.api_key' => 'test-api-key']);

        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                $this->getSampleXmlResponse([50.0]),
                200
            ),
        ]);

        $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'securityToken=test-api-key');
        });
    }

    /**
     * Test service uses config for Finland EIC code.
     */
    public function test_uses_finland_eic_code(): void
    {
        config(['services.entsoe.finland_eic' => '10YFI-1--------U']);

        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                $this->getSampleXmlResponse([50.0]),
                200
            ),
        ]);

        $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        Http::assertSent(function ($request) {
            $url = $request->url();
            return str_contains($url, 'in_Domain=10YFI-1--------U')
                && str_contains($url, 'out_Domain=10YFI-1--------U');
        });
    }

    /**
     * Test service uses correct document type for day-ahead prices.
     */
    public function test_uses_correct_document_type(): void
    {
        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                $this->getSampleXmlResponse([50.0]),
                200
            ),
        ]);

        $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'documentType=A44');
        });
    }

    /**
     * Test service throws exception on API error.
     */
    public function test_throws_exception_on_api_error(): void
    {
        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                '<error>Server Error</error>',
                500
            ),
        ]);

        $this->expectException(RequestException::class);

        $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );
    }

    /**
     * Test service returns empty array when no data available.
     */
    public function test_returns_empty_array_when_no_data(): void
    {
        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                '<?xml version="1.0"?><Publication_MarketDocument xmlns="urn:iec62325.351:tc57wg16:451-3:publicationdocument:7:3"></Publication_MarketDocument>',
                200
            ),
        ]);

        $result = $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test service handles "no matching data" acknowledgement response.
     */
    public function test_handles_no_matching_data_acknowledgement(): void
    {
        $noDataResponse = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Acknowledgement_MarketDocument xmlns="urn:iec62325.351:tc57wg16:451-1:acknowledgementdocument:7:0">
    <mRID>acknowledgement-id</mRID>
    <Reason>
        <code>999</code>
        <text>No matching data found</text>
    </Reason>
</Acknowledgement_MarketDocument>
XML;

        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response($noDataResponse, 200),
        ]);

        $result = $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test service formats date parameters correctly.
     */
    public function test_formats_date_parameters_correctly(): void
    {
        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                $this->getSampleXmlResponse([50.0]),
                200
            ),
        ]);

        $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20 00:00:00', 'UTC'),
            Carbon::parse('2024-01-21 00:00:00', 'UTC')
        );

        Http::assertSent(function ($request) {
            $url = $request->url();
            // ENTSO-E expects YYYYMMDDHHMM format
            return str_contains($url, 'periodStart=202401200000')
                && str_contains($url, 'periodEnd=202401210000');
        });
    }

    /**
     * Test service retries on server errors.
     */
    public function test_retries_on_server_error(): void
    {
        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                return Http::response('<error>Temporary Error</error>', 503);
            }
            return Http::response($this->getSampleXmlResponse([50.0]), 200);
        });

        $result = $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        $this->assertCount(1, $result);
        $this->assertEquals(3, $attempts);
    }

    /**
     * Test service includes region in result.
     */
    public function test_includes_region_in_result(): void
    {
        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                $this->getSampleXmlResponse([50.0]),
                200
            ),
        ]);

        $result = $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        $this->assertEquals('FI', $result[0]['region']);
    }

    /**
     * Test service handles multiple TimeSeries elements.
     */
    public function test_handles_multiple_time_series(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Publication_MarketDocument xmlns="urn:iec62325.351:tc57wg16:451-3:publicationdocument:7:3">
    <TimeSeries>
        <mRID>1</mRID>
        <businessType>A62</businessType>
        <in_Domain.mRID codingScheme="A01">10YFI-1--------U</in_Domain.mRID>
        <out_Domain.mRID codingScheme="A01">10YFI-1--------U</out_Domain.mRID>
        <currency_Unit.name>EUR</currency_Unit.name>
        <price_Measure_Unit.name>MWH</price_Measure_Unit.name>
        <curveType>A01</curveType>
        <Period>
            <timeInterval>
                <start>2024-01-19T23:00Z</start>
                <end>2024-01-20T05:00Z</end>
            </timeInterval>
            <resolution>PT60M</resolution>
            <Point><position>1</position><price.amount>50.0</price.amount></Point>
            <Point><position>2</position><price.amount>55.0</price.amount></Point>
        </Period>
    </TimeSeries>
    <TimeSeries>
        <mRID>2</mRID>
        <businessType>A62</businessType>
        <in_Domain.mRID codingScheme="A01">10YFI-1--------U</in_Domain.mRID>
        <out_Domain.mRID codingScheme="A01">10YFI-1--------U</out_Domain.mRID>
        <currency_Unit.name>EUR</currency_Unit.name>
        <price_Measure_Unit.name>MWH</price_Measure_Unit.name>
        <curveType>A01</curveType>
        <Period>
            <timeInterval>
                <start>2024-01-20T05:00Z</start>
                <end>2024-01-20T23:00Z</end>
            </timeInterval>
            <resolution>PT60M</resolution>
            <Point><position>1</position><price.amount>60.0</price.amount></Point>
            <Point><position>2</position><price.amount>65.0</price.amount></Point>
        </Period>
    </TimeSeries>
</Publication_MarketDocument>
XML;

        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response($xml, 200),
        ]);

        $result = $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        $this->assertCount(4, $result);
    }

    /**
     * Test service returns results sorted by timestamp.
     */
    public function test_returns_results_sorted_by_timestamp(): void
    {
        Http::fake([
            'web-api.tp.entsoe.eu/api*' => Http::response(
                $this->getSampleXmlResponse([50.0, 55.0, 60.0], '2024-01-19T23:00Z'),
                200
            ),
        ]);

        $result = $this->service->fetchDayAheadPrices(
            Carbon::parse('2024-01-20'),
            Carbon::parse('2024-01-21')
        );

        $timestamps = array_column($result, 'timestamp');
        $sortedTimestamps = $timestamps;
        sort($sortedTimestamps);

        $this->assertEquals($sortedTimestamps, $timestamps);
    }
}
