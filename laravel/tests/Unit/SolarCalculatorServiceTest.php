<?php

namespace Tests\Unit;

use App\Services\DTO\SolarEstimateRequest;
use App\Services\DTO\SolarEstimateResult;
use App\Services\PvgisService;
use App\Services\SolarCalculatorService;
use Mockery;
use Tests\TestCase;

class SolarCalculatorServiceTest extends TestCase
{
    public function test_calculate_calls_pvgis_service(): void
    {
        $mockResult = new SolarEstimateResult(
            annual_kwh: 4500.0,
            monthly_kwh: array_fill(0, 12, 375.0),
            assumptions: ['system_kwp' => 5.0],
        );

        $pvgisService = Mockery::mock(PvgisService::class);
        $pvgisService->shouldReceive('calculate')
            ->once()
            ->andReturn($mockResult);

        $service = new SolarCalculatorService($pvgisService);
        $request = new SolarEstimateRequest(
            lat: 60.1695,
            lon: 24.9354,
        );

        $result = $service->calculate($request);

        $this->assertEquals(4500.0, $result->annual_kwh);
    }

    public function test_calculate_passes_request_to_pvgis_service(): void
    {
        $mockResult = new SolarEstimateResult(
            annual_kwh: 4500.0,
            monthly_kwh: array_fill(0, 12, 375.0),
            assumptions: ['system_kwp' => 5.0],
        );

        $pvgisService = Mockery::mock(PvgisService::class);
        $pvgisService->shouldReceive('calculate')
            ->once()
            ->withArgs(function (SolarEstimateRequest $request) {
                return $request->lat === 60.1695
                    && $request->lon === 24.9354
                    && $request->system_kwp === 10.0;
            })
            ->andReturn($mockResult);

        $service = new SolarCalculatorService($pvgisService);
        $request = new SolarEstimateRequest(
            lat: 60.1695,
            lon: 24.9354,
            system_kwp: 10.0,
        );

        $result = $service->calculate($request);

        $this->assertInstanceOf(SolarEstimateResult::class, $result);
    }

    public function test_convert_roof_area_to_system_kwp(): void
    {
        $service = new SolarCalculatorService(Mockery::mock(PvgisService::class));

        // 25 m² * 0.20 kWp/m² = 5 kWp
        $systemKwp = $service->roofAreaToSystemKwp(25.0);

        $this->assertEquals(5.0, $systemKwp);
    }

    public function test_convert_roof_area_to_system_kwp_with_larger_area(): void
    {
        $service = new SolarCalculatorService(Mockery::mock(PvgisService::class));

        // 50 m² * 0.20 kWp/m² = 10 kWp
        $systemKwp = $service->roofAreaToSystemKwp(50.0);

        $this->assertEquals(10.0, $systemKwp);
    }

    public function test_returns_solar_estimate_result(): void
    {
        $mockResult = new SolarEstimateResult(
            annual_kwh: 4500.0,
            monthly_kwh: [100.0, 150.0, 300.0, 450.0, 600.0, 700.0, 680.0, 550.0, 380.0, 220.0, 120.0, 80.0],
            assumptions: [
                'system_kwp' => 5.0,
                'losses_percent' => 14,
                'optimal_angles' => true,
            ],
        );

        $pvgisService = Mockery::mock(PvgisService::class);
        $pvgisService->shouldReceive('calculate')
            ->once()
            ->andReturn($mockResult);

        $service = new SolarCalculatorService($pvgisService);
        $request = new SolarEstimateRequest(
            lat: 60.1695,
            lon: 24.9354,
        );

        $result = $service->calculate($request);

        $this->assertInstanceOf(SolarEstimateResult::class, $result);
        $this->assertEquals(4500.0, $result->annual_kwh);
        $this->assertCount(12, $result->monthly_kwh);
        $this->assertArrayHasKey('system_kwp', $result->assumptions);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
