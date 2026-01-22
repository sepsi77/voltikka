<?php

namespace Tests\Unit;

use App\Services\DTO\SolarEstimateRequest;
use App\Services\PvgisService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PvgisServiceTest extends TestCase
{
    private array $mockPvgisResponse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockPvgisResponse = [
            'inputs' => [
                'location' => [
                    'latitude' => 60.1695,
                    'longitude' => 24.9354,
                ],
                'pv_module' => [
                    'technology' => 'crystSi',
                    'peak_power' => 5,
                ],
                'mounting_system' => [
                    'fixed' => [
                        'slope' => ['value' => 42, 'optimal' => true],
                        'azimuth' => ['value' => 0, 'optimal' => true],
                    ],
                ],
                'meteo_data' => [
                    'radiation_db' => 'PVGIS-SARAH2',
                    'meteo_db' => 'ERA-Interim',
                    'year_min' => 2005,
                    'year_max' => 2020,
                ],
            ],
            'outputs' => [
                'monthly' => [
                    'fixed' => [
                        ['month' => 1, 'E_d' => 2.33, 'E_m' => 72.23],
                        ['month' => 2, 'E_d' => 5.98, 'E_m' => 167.44],
                        ['month' => 3, 'E_d' => 11.41, 'E_m' => 353.71],
                        ['month' => 4, 'E_d' => 16.01, 'E_m' => 480.30],
                        ['month' => 5, 'E_d' => 18.13, 'E_m' => 562.03],
                        ['month' => 6, 'E_d' => 18.17, 'E_m' => 545.10],
                        ['month' => 7, 'E_d' => 17.60, 'E_m' => 545.60],
                        ['month' => 8, 'E_d' => 14.50, 'E_m' => 449.50],
                        ['month' => 9, 'E_d' => 10.15, 'E_m' => 304.50],
                        ['month' => 10, 'E_d' => 5.07, 'E_m' => 157.17],
                        ['month' => 11, 'E_d' => 1.87, 'E_m' => 56.10],
                        ['month' => 12, 'E_d' => 0.98, 'E_m' => 30.38],
                    ],
                ],
                'totals' => [
                    'fixed' => [
                        'E_d' => 10.18,
                        'E_m' => 310.35,
                        'E_y' => 4324.06,
                        'H(i)_d' => 3.28,
                        'H(i)_m' => 99.85,
                        'H(i)_y' => 1198.17,
                        'SD_m' => 148.97,
                        'SD_y' => 515.89,
                        'l_aoi' => -2.6,
                        'l_spec' => '-0.57',
                        'l_tg' => -3.53,
                        'l_total' => -20.35,
                    ],
                ],
            ],
        ];
    }

    public function test_calls_pvgis_api_with_correct_parameters(): void
    {
        Http::fake([
            'https://re.jrc.ec.europa.eu/api/v5_3/PVcalc*' => Http::response($this->mockPvgisResponse),
        ]);

        $service = new PvgisService();
        $request = new SolarEstimateRequest(
            lat: 60.1695,
            lon: 24.9354,
            system_kwp: 5.0,
        );

        $service->calculate($request);

        Http::assertSent(function ($httpRequest) {
            return $httpRequest->url() === 'https://re.jrc.ec.europa.eu/api/v5_3/PVcalc?lat=60.1695&lon=24.9354&peakpower=5&loss=14&outputformat=json&optimalangles=1';
        });
    }

    public function test_uses_custom_angles_when_provided(): void
    {
        Http::fake([
            'https://re.jrc.ec.europa.eu/api/v5_3/PVcalc*' => Http::response($this->mockPvgisResponse),
        ]);

        $service = new PvgisService();
        $request = new SolarEstimateRequest(
            lat: 60.1695,
            lon: 24.9354,
            system_kwp: 5.0,
            roof_tilt_deg: 30,
            roof_aspect_deg: 180,
        );

        $service->calculate($request);

        Http::assertSent(function ($httpRequest) {
            $url = $httpRequest->url();

            return str_contains($url, 'angle=30')
                && str_contains($url, 'aspect=180')
                && ! str_contains($url, 'optimalangles');
        });
    }

    public function test_adds_shading_loss_for_some_shading(): void
    {
        Http::fake([
            'https://re.jrc.ec.europa.eu/api/v5_3/PVcalc*' => Http::response($this->mockPvgisResponse),
        ]);

        $service = new PvgisService();
        $request = new SolarEstimateRequest(
            lat: 60.1695,
            lon: 24.9354,
            shading_level: 'some',
        );

        $service->calculate($request);

        Http::assertSent(function ($httpRequest) {
            // Base 14% + 5% shading = 19%
            return str_contains($httpRequest->url(), 'loss=19');
        });
    }

    public function test_adds_shading_loss_for_heavy_shading(): void
    {
        Http::fake([
            'https://re.jrc.ec.europa.eu/api/v5_3/PVcalc*' => Http::response($this->mockPvgisResponse),
        ]);

        $service = new PvgisService();
        $request = new SolarEstimateRequest(
            lat: 60.1695,
            lon: 24.9354,
            shading_level: 'heavy',
        );

        $service->calculate($request);

        Http::assertSent(function ($httpRequest) {
            // Base 14% + 12% shading = 26%
            return str_contains($httpRequest->url(), 'loss=26');
        });
    }

    public function test_returns_annual_kwh_from_response(): void
    {
        Http::fake([
            'https://re.jrc.ec.europa.eu/api/v5_3/PVcalc*' => Http::response($this->mockPvgisResponse),
        ]);

        $service = new PvgisService();
        $request = new SolarEstimateRequest(
            lat: 60.1695,
            lon: 24.9354,
        );

        $result = $service->calculate($request);

        $this->assertEquals(4324.06, $result->annual_kwh);
    }

    public function test_returns_monthly_kwh_from_response(): void
    {
        Http::fake([
            'https://re.jrc.ec.europa.eu/api/v5_3/PVcalc*' => Http::response($this->mockPvgisResponse),
        ]);

        $service = new PvgisService();
        $request = new SolarEstimateRequest(
            lat: 60.1695,
            lon: 24.9354,
        );

        $result = $service->calculate($request);

        $this->assertCount(12, $result->monthly_kwh);
        $this->assertEquals(72.23, $result->monthly_kwh[0]);   // January
        $this->assertEquals(562.03, $result->monthly_kwh[4]);  // May
        $this->assertEquals(30.38, $result->monthly_kwh[11]);  // December
    }

    public function test_returns_assumptions_in_result(): void
    {
        Http::fake([
            'https://re.jrc.ec.europa.eu/api/v5_3/PVcalc*' => Http::response($this->mockPvgisResponse),
        ]);

        $service = new PvgisService();
        $request = new SolarEstimateRequest(
            lat: 60.1695,
            lon: 24.9354,
            system_kwp: 5.0,
            shading_level: 'none',
        );

        $result = $service->calculate($request);

        $this->assertEquals(5.0, $result->assumptions['system_kwp']);
        $this->assertEquals(14, $result->assumptions['losses_percent']);
        $this->assertEquals('none', $result->assumptions['shading_level']);
        $this->assertTrue($result->assumptions['optimal_angles']);
    }

    public function test_caches_results_for_30_days(): void
    {
        Http::fake([
            'https://re.jrc.ec.europa.eu/api/v5_3/PVcalc*' => Http::response($this->mockPvgisResponse),
        ]);

        Cache::shouldReceive('remember')
            ->once()
            ->withArgs(function ($key, $ttl, $callback) {
                // Check key format
                $this->assertStringStartsWith('pvgis:', $key);
                // Check TTL is 30 days in seconds
                $this->assertEquals(30 * 24 * 60 * 60, $ttl);

                return true;
            })
            ->andReturn([
                'annual_kwh' => 4324.06,
                'monthly_kwh' => array_fill(0, 12, 360.0),
                'assumptions' => [],
            ]);

        $service = new PvgisService();
        $request = new SolarEstimateRequest(
            lat: 60.1695,
            lon: 24.9354,
        );

        $service->calculate($request);
    }

    public function test_different_parameters_produce_different_cache_keys(): void
    {
        $service = new PvgisService();

        $request1 = new SolarEstimateRequest(lat: 60.0, lon: 24.0);
        $request2 = new SolarEstimateRequest(lat: 61.0, lon: 25.0);
        $request3 = new SolarEstimateRequest(lat: 60.0, lon: 24.0, system_kwp: 10.0);

        // Use reflection to access private method for testing cache keys
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('getCacheKey');
        $method->setAccessible(true);

        $key1 = $method->invoke($service, $request1);
        $key2 = $method->invoke($service, $request2);
        $key3 = $method->invoke($service, $request3);

        $this->assertNotEquals($key1, $key2);
        $this->assertNotEquals($key1, $key3);
    }

    public function test_throws_exception_on_api_error(): void
    {
        Http::fake([
            'https://re.jrc.ec.europa.eu/api/v5_3/PVcalc*' => Http::response('Internal Server Error', 500),
        ]);

        $service = new PvgisService();
        $request = new SolarEstimateRequest(
            lat: 60.1695,
            lon: 24.9354,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PVGIS API request failed');

        $service->calculate($request);
    }

    public function test_throws_exception_on_invalid_coordinates(): void
    {
        Http::fake([
            'https://re.jrc.ec.europa.eu/api/v5_3/PVcalc*' => Http::response([
                'message' => 'location outside data coverage',
            ], 400),
        ]);

        $service = new PvgisService();
        $request = new SolarEstimateRequest(
            lat: 0.0,
            lon: 0.0,
        );

        $this->expectException(\RuntimeException::class);

        $service->calculate($request);
    }
}
