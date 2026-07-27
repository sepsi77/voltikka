<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CalculationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test company
        Company::create([
            'name' => 'Test Company Oy',
            'name_slug' => 'test-company-oy',
            'company_url' => 'https://testcompany.fi',
        ]);
    }

    // =========================================================================
    // POST /api/calculate-price tests
    // =========================================================================

    /**
     * Test calculate-price endpoint with basic consumption (simple total kWh).
     */
    public function test_calculate_price_with_basic_consumption(): void
    {
        // Create a test contract with general metering
        ElectricityContract::create([
            'id' => 'test-contract-1',
            'company_name' => 'Test Company Oy',
            'name' => 'Test Contract',
            'name_slug' => 'test-contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-gen-1',
            'electricity_contract_id' => 'test-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.0, // 5 c/kWh
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-mon-1',
            'electricity_contract_id' => 'test-contract-1',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 3.0, // 3 EUR/month
            'payment_unit' => 'EUR/month',
        ]);

        $response = $this->postJson('/api/calculate-price', [
            'contract_id' => 'test-contract-1',
            'consumption' => 5000,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'total_cost',
                'avg_monthly_cost',
                'monthly_costs',
                'monthly_fixed_fee',
            ],
        ]);

        // 5000 kWh * 5 c/kWh = 25000 c = 250 EUR + 36 EUR fixed = 286 EUR
        $data = $response->json('data');
        $this->assertEqualsWithDelta(286.0, $data['total_cost'], 0.01);
        $this->assertEqualsWithDelta(3.0, $data['monthly_fixed_fee'], 0.01);
    }

    /**
     * Test calculate-price endpoint with detailed energy usage breakdown.
     */
    public function test_calculate_price_with_detailed_energy_usage(): void
    {
        ElectricityContract::create([
            'id' => 'test-contract-2',
            'company_name' => 'Test Company Oy',
            'name' => 'Test Contract 2',
            'name_slug' => 'test-contract-2',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-gen-2',
            'electricity_contract_id' => 'test-contract-2',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.0, // 6 c/kWh
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-mon-2',
            'electricity_contract_id' => 'test-contract-2',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 2.0, // 2 EUR/month
            'payment_unit' => 'EUR/month',
        ]);

        $response = $this->postJson('/api/calculate-price', [
            'contract_id' => 'test-contract-2',
            'energy_usage' => [
                'total' => 8000,
                'basic_living' => 3000,
                'room_heating' => 2000,
                'water' => 1500,
                'sauna' => 1500,
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'total_cost',
                'avg_monthly_cost',
                'monthly_costs',
            ],
        ]);

        // Verify that calculation was performed
        $data = $response->json('data');
        $this->assertGreaterThan(0, $data['total_cost']);
    }

    /**
     * Test calculate-price endpoint with time metering contract.
     */
    public function test_calculate_price_with_time_metering(): void
    {
        ElectricityContract::create([
            'id' => 'time-contract',
            'company_name' => 'Test Company Oy',
            'name' => 'Time Metering Contract',
            'name_slug' => 'time-metering-contract',
            'contract_type' => 'Fixed',
            'metering' => 'Time',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-day',
            'electricity_contract_id' => 'time-contract',
            'price_component_type' => 'DayTime',
            'price_date' => now()->format('Y-m-d'),
            'price' => 7.0, // 7 c/kWh
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-night',
            'electricity_contract_id' => 'time-contract',
            'price_component_type' => 'NightTime',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0, // 4 c/kWh
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-mon-time',
            'electricity_contract_id' => 'time-contract',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 3.5, // 3.5 EUR/month
            'payment_unit' => 'EUR/month',
        ]);

        $response = $this->postJson('/api/calculate-price', [
            'contract_id' => 'time-contract',
            'consumption' => 10000,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        // Verify structure
        $this->assertArrayHasKey('total_cost', $data);
        $this->assertArrayHasKey('daytime_kwh_price', $data);
        $this->assertArrayHasKey('nighttime_kwh_price', $data);

        // Verify day and night prices are returned
        $this->assertEqualsWithDelta(7.0, $data['daytime_kwh_price'], 0.01);
        $this->assertEqualsWithDelta(4.0, $data['nighttime_kwh_price'], 0.01);
    }

    /**
     * Test calculate-price endpoint includes structured discount savings in price output.
     */
    public function test_calculate_price_applies_monthly_discount(): void
    {
        ElectricityContract::create([
            'id' => 'discount-contract',
            'company_name' => 'Test Company Oy',
            'name' => 'Discount Contract',
            'name_slug' => 'discount-contract',
            'contract_type' => 'Fixed',
            'pricing_model' => 'FixedPrice',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-disc-monthly',
            'electricity_contract_id' => 'discount-contract',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.95,
            'payment_unit' => 'EurPerMonth',
            'has_discount' => true,
            'discount_value' => 5.95,
            'discount_is_percentage' => false,
            'discount_type' => 'NFirstMonth',
            'discount_discount_n_first_months' => 6,
        ]);

        PriceComponent::create([
            'id' => 'pc-disc-general',
            'electricity_contract_id' => 'discount-contract',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 8.75,
            'payment_unit' => 'CentPerKiwattHour',
        ]);

        $response = $this->postJson('/api/calculate-price', [
            'contract_id' => 'discount-contract',
            'consumption' => 5000,
        ]);

        $response->assertOk();
        $data = $response->json('data');

        $this->assertTrue($data['includes_discounts']);
        $this->assertEqualsWithDelta(508.9, $data['base_total_cost'], 0.01);
        $this->assertEqualsWithDelta(35.7, $data['discount_savings_total'], 0.01);
        $this->assertEqualsWithDelta(473.2, $data['total_cost'], 0.01);
    }

    public function test_canonical_calculation_does_not_query_price_components(): void
    {
        config(['canonical_pricing.enabled' => true]);

        ElectricityContract::create([
            'id' => 'canonical-query-contract',
            'company_name' => 'Test Company Oy',
            'name' => 'Canonical Query Contract',
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'FixedPrice',
            'metering' => 'General',
            'availability_is_national' => true,
            'canonical_pricing' => [
                'phases' => [[
                    'label' => 'current',
                    'phase_kind' => 'current_structured',
                    'starts' => ['kind' => 'contract_start', 'value' => null],
                    'ends' => ['kind' => 'none', 'value' => null],
                    'components' => [[
                        'component_type' => 'energy_general',
                        'amount' => 10.0,
                        'normal_amount' => null,
                        'unit' => 'cents_per_kwh',
                        'vat_status' => 'included',
                        'price_role' => 'current',
                        'source_kind' => 'both',
                        'evidence' => [],
                    ]],
                    'evidence' => [],
                ]],
                'recurring_schedule' => [
                    'present' => false,
                    'cadence' => 'none',
                    'current_period_start' => null,
                    'current_period_end' => null,
                    'future_price_known' => null,
                    'description' => null,
                    'evidence' => [],
                ],
                'consumption_effect' => [
                    'present' => false,
                    'applies_to' => 'unknown',
                    'cadence' => 'none',
                    'expected_cents_per_kwh' => null,
                    'typical_min_cents_per_kwh' => null,
                    'typical_max_cents_per_kwh' => null,
                    'hard_min_cents_per_kwh' => null,
                    'hard_max_cents_per_kwh' => null,
                    'uncapped' => null,
                    'description' => null,
                    'evidence' => [],
                ],
            ],
            'canonical_calculation' => [
                'status' => 'exact',
                'missing_facts' => [],
                'required_assumptions' => [],
            ],
            'canonical_source_consistency' => [
                'misleading_first_12_months' => 'not_detected',
                'structured_pricing_status' => 'complete',
                'issue_codes' => [],
            ],
        ]);

        PriceComponent::create([
            'id' => 'pc-canonical-query-contract',
            'electricity_contract_id' => 'canonical-query-contract',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 1.0,
            'payment_unit' => 'c/kWh',
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->postJson('/api/calculate-price', [
            'contract_id' => 'canonical-query-contract',
            'consumption' => 5000,
        ]);

        $response->assertOk()->assertJsonPath('data.pricing_basis', 'canonical');
        $this->assertEqualsWithDelta(500.0, (float) $response->json('data.total_cost'), 0.01);
        $this->assertEmpty(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'price_components'),
        ));
    }

    /**
     * Test calculate-price endpoint validates required fields.
     */
    public function test_calculate_price_validates_contract_id(): void
    {
        $response = $this->postJson('/api/calculate-price', [
            'consumption' => 5000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['contract_id']);
    }

    /**
     * Test calculate-price endpoint validates consumption or energy_usage.
     */
    public function test_calculate_price_validates_consumption_or_energy_usage(): void
    {
        ElectricityContract::create([
            'id' => 'test-contract-val',
            'company_name' => 'Test Company Oy',
            'name' => 'Validation Test Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        $response = $this->postJson('/api/calculate-price', [
            'contract_id' => 'test-contract-val',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['consumption']);
    }

    /**
     * Test calculate-price endpoint returns 404 for non-existent contract.
     */
    public function test_calculate_price_returns_404_for_nonexistent_contract(): void
    {
        $response = $this->postJson('/api/calculate-price', [
            'contract_id' => 'nonexistent-contract',
            'consumption' => 5000,
        ]);

        $response->assertStatus(404);
    }

    /**
     * Test calculate-price endpoint with seasonal metering contract.
     */
    public function test_calculate_price_with_seasonal_metering(): void
    {
        ElectricityContract::create([
            'id' => 'seasonal-contract',
            'company_name' => 'Test Company Oy',
            'name' => 'Seasonal Metering Contract',
            'contract_type' => 'Fixed',
            'metering' => 'Seasonal',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-winter',
            'electricity_contract_id' => 'seasonal-contract',
            'price_component_type' => 'SeasonalWinterDay',
            'price_date' => now()->format('Y-m-d'),
            'price' => 8.0, // 8 c/kWh
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-other',
            'electricity_contract_id' => 'seasonal-contract',
            'price_component_type' => 'SeasonalOther',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0, // 4 c/kWh
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-mon-seasonal',
            'electricity_contract_id' => 'seasonal-contract',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'EUR/month',
        ]);

        $response = $this->postJson('/api/calculate-price', [
            'contract_id' => 'seasonal-contract',
            'consumption' => 12000,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        // Verify seasonal prices are returned
        $this->assertArrayHasKey('seasonal_winter_day_kwh_price', $data);
        $this->assertArrayHasKey('seasonal_other_kwh_price', $data);
        $this->assertEqualsWithDelta(8.0, $data['seasonal_winter_day_kwh_price'], 0.01);
        $this->assertEqualsWithDelta(4.0, $data['seasonal_other_kwh_price'], 0.01);
    }

    // =========================================================================
    // POST /api/estimate-consumption tests
    // =========================================================================

    /**
     * Test estimate-consumption endpoint with minimal parameters.
     */
    public function test_estimate_consumption_with_minimal_params(): void
    {
        $response = $this->postJson('/api/estimate-consumption', [
            'living_area' => 75,
            'num_people' => 2,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'total',
                'basic_living',
            ],
        ]);

        $data = $response->json('data');
        $this->assertGreaterThan(0, $data['total']);
        $this->assertGreaterThan(0, $data['basic_living']);
    }

    /**
     * Test estimate-consumption endpoint with full parameters.
     */
    public function test_estimate_consumption_with_full_params(): void
    {
        $response = $this->postJson('/api/estimate-consumption', [
            'living_area' => 150,
            'num_people' => 4,
            'building_type' => 'detached_house',
            'heating_method' => 'electricity',
            'supplementary_heating' => 'heat_pump',
            'building_energy_efficiency' => '2010',
            'building_region' => 'south',
            'electric_vehicle_kms_per_month' => 1000,
            'bathroom_heating_area' => 10,
            'sauna_usage_per_week' => 2,
            'external_heating' => false,
            'external_heating_water' => false,
            'cooling' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'total',
                'basic_living',
            ],
        ]);

        $data = $response->json('data');

        // With all these features, should have significant consumption
        $this->assertGreaterThan(5000, $data['total']);

        // Should have various components
        $this->assertArrayHasKey('electricity_vehicle', $data);
        $this->assertArrayHasKey('cooling', $data);
    }

    /**
     * Test estimate-consumption validates required fields.
     */
    public function test_estimate_consumption_validates_required_fields(): void
    {
        $response = $this->postJson('/api/estimate-consumption', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['living_area', 'num_people']);
    }

    /**
     * Test estimate-consumption validates living_area is positive.
     */
    public function test_estimate_consumption_validates_living_area_positive(): void
    {
        $response = $this->postJson('/api/estimate-consumption', [
            'living_area' => 0,
            'num_people' => 2,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['living_area']);
    }

    /**
     * Test estimate-consumption validates num_people is positive.
     */
    public function test_estimate_consumption_validates_num_people_positive(): void
    {
        $response = $this->postJson('/api/estimate-consumption', [
            'living_area' => 75,
            'num_people' => 0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['num_people']);
    }

    /**
     * Test estimate-consumption validates enum values.
     */
    public function test_estimate_consumption_validates_enum_values(): void
    {
        $response = $this->postJson('/api/estimate-consumption', [
            'living_area' => 75,
            'num_people' => 2,
            'heating_method' => 'invalid_method',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['heating_method']);
    }

    /**
     * Test estimate-consumption returns sauna consumption when sauna is used.
     */
    public function test_estimate_consumption_includes_sauna(): void
    {
        $response = $this->postJson('/api/estimate-consumption', [
            'living_area' => 100,
            'num_people' => 3,
            'sauna_usage_per_week' => 3,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertArrayHasKey('sauna', $data);
        $this->assertGreaterThan(0, $data['sauna']);
    }

    /**
     * Test estimate-consumption returns EV consumption when EV is used.
     */
    public function test_estimate_consumption_includes_ev_charging(): void
    {
        $response = $this->postJson('/api/estimate-consumption', [
            'living_area' => 100,
            'num_people' => 3,
            'electric_vehicle_kms_per_month' => 1500,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertArrayHasKey('electricity_vehicle', $data);
        $this->assertGreaterThan(0, $data['electricity_vehicle']);
    }

    /**
     * Test estimate-consumption returns heating breakdown when heating is enabled.
     */
    public function test_estimate_consumption_includes_heating_breakdown(): void
    {
        $response = $this->postJson('/api/estimate-consumption', [
            'living_area' => 150,
            'num_people' => 4,
            'building_type' => 'detached_house',
            'heating_method' => 'electricity',
            'building_energy_efficiency' => '2000',
            'building_region' => 'central',
            'external_heating' => false,
            'external_heating_water' => false,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        // Should have heating components
        $this->assertArrayHasKey('heating_total', $data);
        $this->assertArrayHasKey('room_heating', $data);
        $this->assertArrayHasKey('water', $data);
        $this->assertGreaterThan(0, $data['room_heating']);
    }

    /**
     * Test estimate-consumption with always-on sauna.
     */
    public function test_estimate_consumption_with_always_on_sauna(): void
    {
        $response = $this->postJson('/api/estimate-consumption', [
            'living_area' => 100,
            'num_people' => 2,
            'sauna_is_always_on_type' => true,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertArrayHasKey('sauna', $data);
        // Always-on sauna uses ~2750 kWh/year
        $this->assertEqualsWithDelta(2750, $data['sauna'], 100);
    }

    /**
     * Test estimate-consumption with bathroom floor heating.
     */
    public function test_estimate_consumption_with_bathroom_heating(): void
    {
        $response = $this->postJson('/api/estimate-consumption', [
            'living_area' => 100,
            'num_people' => 2,
            'bathroom_heating_area' => 15,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertArrayHasKey('bathroom_underfloor_heating', $data);
        // 15 sqm * 200 kWh/sqm = 3000 kWh
        $this->assertEquals(3000, $data['bathroom_underfloor_heating']);
    }
}
