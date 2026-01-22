<?php

namespace Tests\Feature;

use App\Services\DTO\SolarEstimateResult;
use App\Services\SolarCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SolarEstimateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $mockResult = new SolarEstimateResult(
            annual_kwh: 4500.0,
            monthly_kwh: [100.0, 150.0, 300.0, 450.0, 600.0, 700.0, 680.0, 550.0, 380.0, 220.0, 120.0, 80.0],
            assumptions: [
                'system_kwp' => 5.0,
                'losses_percent' => 14,
                'shading_level' => 'none',
                'optimal_angles' => true,
            ],
        );

        $mockService = Mockery::mock(SolarCalculatorService::class);
        $mockService->shouldReceive('calculate')->andReturn($mockResult);

        $this->app->instance(SolarCalculatorService::class, $mockService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_estimate_endpoint_returns_json(): void
    {
        $response = $this->postJson('/api/solar/estimate', [
            'lat' => 60.1695,
            'lon' => 24.9354,
        ]);

        $response->assertStatus(200)
            ->assertHeader('content-type', 'application/json');
    }

    public function test_estimate_returns_annual_kwh(): void
    {
        $response = $this->postJson('/api/solar/estimate', [
            'lat' => 60.1695,
            'lon' => 24.9354,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(4500.0, (float) $data['annual_kwh']);
    }

    public function test_estimate_returns_monthly_kwh_array(): void
    {
        $response = $this->postJson('/api/solar/estimate', [
            'lat' => 60.1695,
            'lon' => 24.9354,
        ]);

        $response->assertStatus(200)
            ->assertJsonCount(12, 'data.monthly_kwh');
    }

    public function test_estimate_returns_assumptions(): void
    {
        $response = $this->postJson('/api/solar/estimate', [
            'lat' => 60.1695,
            'lon' => 24.9354,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['assumptions' => ['system_kwp', 'losses_percent']]])
            ->assertJsonPath('data.assumptions.losses_percent', 14);
    }

    public function test_estimate_requires_lat(): void
    {
        $response = $this->postJson('/api/solar/estimate', [
            'lon' => 24.9354,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['lat']);
    }

    public function test_estimate_requires_lon(): void
    {
        $response = $this->postJson('/api/solar/estimate', [
            'lat' => 60.1695,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['lon']);
    }

    public function test_estimate_validates_lat_range(): void
    {
        $response = $this->postJson('/api/solar/estimate', [
            'lat' => 91,
            'lon' => 24.9354,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['lat']);
    }

    public function test_estimate_validates_lon_range(): void
    {
        $response = $this->postJson('/api/solar/estimate', [
            'lat' => 60.1695,
            'lon' => 181,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['lon']);
    }

    public function test_estimate_accepts_optional_parameters(): void
    {
        $response = $this->postJson('/api/solar/estimate', [
            'lat' => 60.1695,
            'lon' => 24.9354,
            'system_kwp' => 10.0,
            'roof_tilt_deg' => 30,
            'roof_aspect_deg' => 180,
            'shading_level' => 'some',
        ]);

        $response->assertStatus(200);
    }

    public function test_estimate_validates_shading_level(): void
    {
        $response = $this->postJson('/api/solar/estimate', [
            'lat' => 60.1695,
            'lon' => 24.9354,
            'shading_level' => 'invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['shading_level']);
    }

    public function test_estimate_uses_default_system_kwp(): void
    {
        $mockService = Mockery::mock(SolarCalculatorService::class);
        $mockService->shouldReceive('calculate')
            ->once()
            ->withArgs(function ($request) {
                return $request->system_kwp === 5.0;
            })
            ->andReturn(new SolarEstimateResult(
                annual_kwh: 4500.0,
                monthly_kwh: array_fill(0, 12, 375.0),
                assumptions: [],
            ));

        $this->app->instance(SolarCalculatorService::class, $mockService);

        $response = $this->postJson('/api/solar/estimate', [
            'lat' => 60.1695,
            'lon' => 24.9354,
        ]);

        $response->assertStatus(200);
    }
}
