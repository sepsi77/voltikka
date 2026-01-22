<?php

namespace Tests\Unit;

use App\Http\Requests\SolarEstimateFormRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SolarEstimateRequestValidationTest extends TestCase
{
    private function validateRequest(array $data): \Illuminate\Validation\Validator
    {
        $request = new SolarEstimateFormRequest();

        return Validator::make($data, $request->rules());
    }

    public function test_lat_is_required(): void
    {
        $validator = $this->validateRequest(['lon' => 24.9354]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('lat', $validator->errors()->toArray());
    }

    public function test_lon_is_required(): void
    {
        $validator = $this->validateRequest(['lat' => 60.1695]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('lon', $validator->errors()->toArray());
    }

    public function test_lat_must_be_numeric(): void
    {
        $validator = $this->validateRequest(['lat' => 'not-a-number', 'lon' => 24.9354]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('lat', $validator->errors()->toArray());
    }

    public function test_lon_must_be_numeric(): void
    {
        $validator = $this->validateRequest(['lat' => 60.1695, 'lon' => 'not-a-number']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('lon', $validator->errors()->toArray());
    }

    public function test_lat_must_be_between_negative_90_and_90(): void
    {
        $validatorTooLow = $this->validateRequest(['lat' => -91, 'lon' => 24.9354]);
        $validatorTooHigh = $this->validateRequest(['lat' => 91, 'lon' => 24.9354]);

        $this->assertTrue($validatorTooLow->fails());
        $this->assertTrue($validatorTooHigh->fails());
    }

    public function test_lat_boundary_values_are_valid(): void
    {
        $validatorMin = $this->validateRequest(['lat' => -90, 'lon' => 24.9354]);
        $validatorMax = $this->validateRequest(['lat' => 90, 'lon' => 24.9354]);

        $this->assertFalse($validatorMin->fails());
        $this->assertFalse($validatorMax->fails());
    }

    public function test_lon_must_be_between_negative_180_and_180(): void
    {
        $validatorTooLow = $this->validateRequest(['lat' => 60.0, 'lon' => -181]);
        $validatorTooHigh = $this->validateRequest(['lat' => 60.0, 'lon' => 181]);

        $this->assertTrue($validatorTooLow->fails());
        $this->assertTrue($validatorTooHigh->fails());
    }

    public function test_lon_boundary_values_are_valid(): void
    {
        $validatorMin = $this->validateRequest(['lat' => 60.0, 'lon' => -180]);
        $validatorMax = $this->validateRequest(['lat' => 60.0, 'lon' => 180]);

        $this->assertFalse($validatorMin->fails());
        $this->assertFalse($validatorMax->fails());
    }

    public function test_system_kwp_is_nullable(): void
    {
        $validator = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0]);

        $this->assertFalse($validator->fails());
    }

    public function test_system_kwp_must_be_numeric(): void
    {
        $validator = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'system_kwp' => 'not-a-number']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('system_kwp', $validator->errors()->toArray());
    }

    public function test_system_kwp_must_be_between_0_1_and_100(): void
    {
        $validatorTooLow = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'system_kwp' => 0.05]);
        $validatorTooHigh = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'system_kwp' => 101]);

        $this->assertTrue($validatorTooLow->fails());
        $this->assertTrue($validatorTooHigh->fails());
    }

    public function test_system_kwp_boundary_values_are_valid(): void
    {
        $validatorMin = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'system_kwp' => 0.1]);
        $validatorMax = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'system_kwp' => 100]);

        $this->assertFalse($validatorMin->fails());
        $this->assertFalse($validatorMax->fails());
    }

    public function test_roof_tilt_deg_is_nullable(): void
    {
        $validator = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0]);

        $this->assertFalse($validator->fails());
    }

    public function test_roof_tilt_deg_must_be_integer(): void
    {
        $validator = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'roof_tilt_deg' => 30.5]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('roof_tilt_deg', $validator->errors()->toArray());
    }

    public function test_roof_tilt_deg_must_be_between_0_and_90(): void
    {
        $validatorTooLow = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'roof_tilt_deg' => -1]);
        $validatorTooHigh = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'roof_tilt_deg' => 91]);

        $this->assertTrue($validatorTooLow->fails());
        $this->assertTrue($validatorTooHigh->fails());
    }

    public function test_roof_tilt_deg_boundary_values_are_valid(): void
    {
        $validatorMin = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'roof_tilt_deg' => 0]);
        $validatorMax = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'roof_tilt_deg' => 90]);

        $this->assertFalse($validatorMin->fails());
        $this->assertFalse($validatorMax->fails());
    }

    public function test_roof_aspect_deg_is_nullable(): void
    {
        $validator = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0]);

        $this->assertFalse($validator->fails());
    }

    public function test_roof_aspect_deg_must_be_integer(): void
    {
        $validator = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'roof_aspect_deg' => 180.5]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('roof_aspect_deg', $validator->errors()->toArray());
    }

    public function test_roof_aspect_deg_must_be_between_negative_180_and_180(): void
    {
        $validatorTooLow = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'roof_aspect_deg' => -181]);
        $validatorTooHigh = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'roof_aspect_deg' => 181]);

        $this->assertTrue($validatorTooLow->fails());
        $this->assertTrue($validatorTooHigh->fails());
    }

    public function test_roof_aspect_deg_boundary_values_are_valid(): void
    {
        $validatorMin = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'roof_aspect_deg' => -180]);
        $validatorMax = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'roof_aspect_deg' => 180]);

        $this->assertFalse($validatorMin->fails());
        $this->assertFalse($validatorMax->fails());
    }

    public function test_shading_level_is_nullable(): void
    {
        $validator = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0]);

        $this->assertFalse($validator->fails());
    }

    public function test_shading_level_must_be_valid_value(): void
    {
        $validatorInvalid = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'shading_level' => 'invalid']);

        $this->assertTrue($validatorInvalid->fails());
        $this->assertArrayHasKey('shading_level', $validatorInvalid->errors()->toArray());
    }

    public function test_shading_level_valid_values(): void
    {
        $validatorNone = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'shading_level' => 'none']);
        $validatorSome = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'shading_level' => 'some']);
        $validatorHeavy = $this->validateRequest(['lat' => 60.0, 'lon' => 24.0, 'shading_level' => 'heavy']);

        $this->assertFalse($validatorNone->fails());
        $this->assertFalse($validatorSome->fails());
        $this->assertFalse($validatorHeavy->fails());
    }

    public function test_valid_complete_request(): void
    {
        $validator = $this->validateRequest([
            'lat' => 60.1695,
            'lon' => 24.9354,
            'system_kwp' => 5.0,
            'roof_tilt_deg' => 30,
            'roof_aspect_deg' => 180,
            'shading_level' => 'some',
        ]);

        $this->assertFalse($validator->fails());
    }
}
