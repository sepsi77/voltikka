<?php

namespace Tests\Unit;

use App\Services\DTO\SolarEstimateRequest;
use App\Services\DTO\SolarEstimateResult;
use PHPUnit\Framework\TestCase;

class SolarEstimateDtoTest extends TestCase
{
    public function test_solar_estimate_request_has_default_values(): void
    {
        $request = new SolarEstimateRequest(
            lat: 60.1695,
            lon: 24.9354,
        );

        $this->assertEquals(60.1695, $request->lat);
        $this->assertEquals(24.9354, $request->lon);
        $this->assertEquals(5.0, $request->system_kwp);
        $this->assertNull($request->roof_tilt_deg);
        $this->assertNull($request->roof_aspect_deg);
        $this->assertEquals('none', $request->shading_level);
    }

    public function test_solar_estimate_request_accepts_custom_values(): void
    {
        $request = new SolarEstimateRequest(
            lat: 61.5,
            lon: 23.8,
            system_kwp: 10.0,
            roof_tilt_deg: 30,
            roof_aspect_deg: 180,
            shading_level: 'some',
        );

        $this->assertEquals(61.5, $request->lat);
        $this->assertEquals(23.8, $request->lon);
        $this->assertEquals(10.0, $request->system_kwp);
        $this->assertEquals(30, $request->roof_tilt_deg);
        $this->assertEquals(180, $request->roof_aspect_deg);
        $this->assertEquals('some', $request->shading_level);
    }

    public function test_solar_estimate_request_accepts_heavy_shading(): void
    {
        $request = new SolarEstimateRequest(
            lat: 60.0,
            lon: 24.0,
            shading_level: 'heavy',
        );

        $this->assertEquals('heavy', $request->shading_level);
    }

    public function test_solar_estimate_result_has_required_properties(): void
    {
        $monthlyKwh = [100.0, 150.0, 300.0, 450.0, 600.0, 700.0, 680.0, 550.0, 380.0, 220.0, 120.0, 80.0];
        $assumptions = [
            'system_kwp' => 5.0,
            'losses' => 14.0,
            'optimal_angle' => true,
        ];

        $result = new SolarEstimateResult(
            annual_kwh: 4330.0,
            monthly_kwh: $monthlyKwh,
            assumptions: $assumptions,
        );

        $this->assertEquals(4330.0, $result->annual_kwh);
        $this->assertEquals($monthlyKwh, $result->monthly_kwh);
        $this->assertEquals($assumptions, $result->assumptions);
    }

    public function test_solar_estimate_result_monthly_kwh_has_12_months(): void
    {
        $monthlyKwh = array_fill(0, 12, 360.0);

        $result = new SolarEstimateResult(
            annual_kwh: 4320.0,
            monthly_kwh: $monthlyKwh,
            assumptions: [],
        );

        $this->assertCount(12, $result->monthly_kwh);
    }

    public function test_solar_estimate_result_to_array(): void
    {
        $monthlyKwh = array_fill(0, 12, 360.0);
        $assumptions = ['system_kwp' => 5.0];

        $result = new SolarEstimateResult(
            annual_kwh: 4320.0,
            monthly_kwh: $monthlyKwh,
            assumptions: $assumptions,
        );

        $array = $result->toArray();

        $this->assertIsArray($array);
        $this->assertEquals(4320.0, $array['annual_kwh']);
        $this->assertEquals($monthlyKwh, $array['monthly_kwh']);
        $this->assertEquals($assumptions, $array['assumptions']);
    }
}
