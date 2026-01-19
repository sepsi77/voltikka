<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\Postcode;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test companies
        Company::create([
            'name' => 'Test Company Oy',
            'name_slug' => 'test-company-oy',
            'company_url' => 'https://testcompany.fi',
            'logo_url' => 'https://storage.example.com/logos/test.png',
        ]);

        Company::create([
            'name' => 'Another Company Ab',
            'name_slug' => 'another-company-ab',
            'company_url' => 'https://another.fi',
        ]);
    }

    /**
     * Test listing all contracts returns a successful response.
     */
    public function test_list_contracts_returns_ok(): void
    {
        $response = $this->getJson('/api/contracts');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta' => ['total', 'per_page', 'current_page', 'last_page'],
        ]);
    }

    /**
     * Test listing contracts returns contract data.
     */
    public function test_list_contracts_returns_contract_data(): void
    {
        // Create a test contract
        ElectricityContract::create([
            'id' => 'test-contract-1',
            'company_name' => 'Test Company Oy',
            'name' => 'Edulinen Sähkö',
            'name_slug' => 'edulinen-sahko',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        // Create price components
        PriceComponent::create([
            'id' => 'pc-general-1',
            'electricity_contract_id' => 'test-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.5,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-1',
            'electricity_contract_id' => 'test-contract-1',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 2.95,
            'payment_unit' => 'EUR/month',
        ]);

        $response = $this->getJson('/api/contracts');

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Edulinen Sähkö']);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'name_slug',
                    'contract_type',
                    'metering',
                    'company' => ['name', 'name_slug', 'logo_url'],
                ],
            ],
        ]);
    }

    /**
     * Test filtering contracts by contract_type.
     */
    public function test_filter_contracts_by_contract_type(): void
    {
        ElectricityContract::create([
            'id' => 'fixed-contract',
            'company_name' => 'Test Company Oy',
            'name' => 'Fixed Price Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        ElectricityContract::create([
            'id' => 'spot-contract',
            'company_name' => 'Test Company Oy',
            'name' => 'Spot Price Contract',
            'contract_type' => 'Spot',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        // Filter by Fixed
        $response = $this->getJson('/api/contracts?contract_type=Fixed');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Fixed Price Contract']);
        $response->assertJsonMissing(['name' => 'Spot Price Contract']);
    }

    /**
     * Test filtering contracts by metering type.
     */
    public function test_filter_contracts_by_metering(): void
    {
        ElectricityContract::create([
            'id' => 'general-metering',
            'company_name' => 'Test Company Oy',
            'name' => 'General Metering Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        ElectricityContract::create([
            'id' => 'time-metering',
            'company_name' => 'Test Company Oy',
            'name' => 'Time Metering Contract',
            'contract_type' => 'Fixed',
            'metering' => 'Time',
            'availability_is_national' => true,
        ]);

        // Filter by Time metering
        $response = $this->getJson('/api/contracts?metering=Time');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Time Metering Contract']);
    }

    /**
     * Test filtering contracts by postcode availability.
     */
    public function test_filter_contracts_by_postcode(): void
    {
        // Create postcodes
        Postcode::create([
            'postcode' => '00100',
            'postcode_name' => 'Helsinki',
            'municipality_code' => '091',
        ]);

        Postcode::create([
            'postcode' => '33100',
            'postcode_name' => 'Tampere',
            'municipality_code' => '837',
        ]);

        // Contract available only in Helsinki
        $helsinkiContract = ElectricityContract::create([
            'id' => 'helsinki-contract',
            'company_name' => 'Test Company Oy',
            'name' => 'Helsinki Only Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => false,
        ]);
        $helsinkiContract->availabilityPostcodes()->attach('00100');

        // National contract
        ElectricityContract::create([
            'id' => 'national-contract',
            'company_name' => 'Test Company Oy',
            'name' => 'National Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        // Filter by Helsinki postcode - should get both
        $response = $this->getJson('/api/contracts?postcode=00100');
        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');

        // Filter by Tampere postcode - should only get national
        $response = $this->getJson('/api/contracts?postcode=33100');
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'National Contract']);
    }

    /**
     * Test filtering contracts by energy source type.
     */
    public function test_filter_contracts_by_energy_source(): void
    {
        // Create renewable contract
        $renewableContract = ElectricityContract::create([
            'id' => 'renewable-contract',
            'company_name' => 'Test Company Oy',
            'name' => 'Green Energy Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);
        ElectricitySource::create([
            'contract_id' => 'renewable-contract',
            'renewable_total' => 100.0,
            'renewable_wind' => 60.0,
            'renewable_hydro' => 40.0,
            'fossil_total' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        // Create nuclear contract
        $nuclearContract = ElectricityContract::create([
            'id' => 'nuclear-contract',
            'company_name' => 'Test Company Oy',
            'name' => 'Nuclear Energy Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);
        ElectricitySource::create([
            'contract_id' => 'nuclear-contract',
            'renewable_total' => 30.0,
            'fossil_total' => 0.0,
            'nuclear_total' => 70.0,
            'nuclear_general' => 70.0,
        ]);

        // Filter by renewable (100% renewable)
        $response = $this->getJson('/api/contracts?energy_source=renewable');
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Green Energy Contract']);

        // Filter by nuclear (has nuclear)
        $response = $this->getJson('/api/contracts?energy_source=nuclear');
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Nuclear Energy Contract']);
    }

    /**
     * Test getting a single contract by ID.
     */
    public function test_get_single_contract(): void
    {
        ElectricityContract::create([
            'id' => 'single-contract',
            'company_name' => 'Test Company Oy',
            'name' => 'Test Contract',
            'name_slug' => 'test-contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'short_description' => 'A great contract',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-gen',
            'electricity_contract_id' => 'single-contract',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-mon',
            'electricity_contract_id' => 'single-contract',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 3.0,
            'payment_unit' => 'EUR/month',
        ]);

        ElectricitySource::create([
            'contract_id' => 'single-contract',
            'renewable_total' => 80.0,
            'nuclear_total' => 20.0,
            'fossil_total' => 0.0,
        ]);

        $response = $this->getJson('/api/contracts/single-contract');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'name_slug',
                'contract_type',
                'metering',
                'short_description',
                'company' => ['name', 'name_slug', 'logo_url', 'company_url'],
                'price_components' => [
                    '*' => ['price_component_type', 'price', 'payment_unit'],
                ],
                'electricity_source' => [
                    'renewable_total',
                    'nuclear_total',
                    'fossil_total',
                ],
            ],
        ]);
        $response->assertJsonFragment(['name' => 'Test Contract']);
    }

    /**
     * Test getting a non-existent contract returns 404.
     */
    public function test_get_nonexistent_contract_returns_404(): void
    {
        $response = $this->getJson('/api/contracts/nonexistent-id');

        $response->assertStatus(404);
    }

    /**
     * Test contracts include calculated annual cost when consumption is provided.
     */
    public function test_contracts_include_calculated_annual_cost(): void
    {
        ElectricityContract::create([
            'id' => 'cost-test-contract',
            'company_name' => 'Test Company Oy',
            'name' => 'Cost Test Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cost-gen',
            'electricity_contract_id' => 'cost-test-contract',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.0, // 5 c/kWh
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-cost-mon',
            'electricity_contract_id' => 'cost-test-contract',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 3.0, // 3 EUR/month
            'payment_unit' => 'EUR/month',
        ]);

        // Request with 5000 kWh annual consumption
        $response = $this->getJson('/api/contracts?consumption=5000');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'calculated_cost' => [
                        'total_cost',
                        'avg_monthly_cost',
                    ],
                ],
            ],
        ]);

        // Verify cost calculation: 5000 kWh * 5 c/kWh = 25000 c = 250 EUR + 36 EUR fixed = 286 EUR
        $data = $response->json('data');
        $this->assertEqualsWithDelta(286.0, $data[0]['calculated_cost']['total_cost'], 0.01);
    }

    /**
     * Test single contract includes calculated annual cost when consumption is provided.
     */
    public function test_single_contract_includes_calculated_annual_cost(): void
    {
        ElectricityContract::create([
            'id' => 'single-cost-contract',
            'company_name' => 'Test Company Oy',
            'name' => 'Single Cost Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-single-gen',
            'electricity_contract_id' => 'single-cost-contract',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0, // 4 c/kWh
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-single-mon',
            'electricity_contract_id' => 'single-cost-contract',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 2.0, // 2 EUR/month
            'payment_unit' => 'EUR/month',
        ]);

        // Request with 10000 kWh annual consumption
        $response = $this->getJson('/api/contracts/single-cost-contract?consumption=10000');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'calculated_cost' => [
                    'total_cost',
                    'avg_monthly_cost',
                    'monthly_costs',
                ],
            ],
        ]);

        // Verify cost calculation: 10000 kWh * 4 c/kWh = 40000 c = 400 EUR + 24 EUR fixed = 424 EUR
        $data = $response->json('data');
        $this->assertEqualsWithDelta(424.0, $data['calculated_cost']['total_cost'], 0.01);
    }

    /**
     * Test contracts can be sorted by calculated cost.
     */
    public function test_contracts_sorted_by_calculated_cost(): void
    {
        // Expensive contract
        ElectricityContract::create([
            'id' => 'expensive-contract',
            'company_name' => 'Test Company Oy',
            'name' => 'Expensive Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);
        PriceComponent::create([
            'id' => 'pc-exp-gen',
            'electricity_contract_id' => 'expensive-contract',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 10.0,
            'payment_unit' => 'c/kWh',
        ]);

        // Cheap contract
        ElectricityContract::create([
            'id' => 'cheap-contract',
            'company_name' => 'Test Company Oy',
            'name' => 'Cheap Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);
        PriceComponent::create([
            'id' => 'pc-chp-gen',
            'electricity_contract_id' => 'cheap-contract',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 3.0,
            'payment_unit' => 'c/kWh',
        ]);

        // Request sorted by cost (ascending)
        $response = $this->getJson('/api/contracts?consumption=5000&sort=cost');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Cheap contract should come first
        $this->assertEquals('Cheap Contract', $data[0]['name']);
        $this->assertEquals('Expensive Contract', $data[1]['name']);
    }

    /**
     * Test pagination works correctly.
     */
    public function test_contracts_pagination(): void
    {
        // Create 25 contracts
        for ($i = 1; $i <= 25; $i++) {
            ElectricityContract::create([
                'id' => "paginated-contract-{$i}",
                'company_name' => 'Test Company Oy',
                'name' => "Paginated Contract {$i}",
                'contract_type' => 'Fixed',
                'metering' => 'General',
                'availability_is_national' => true,
            ]);
        }

        // First page (default per_page = 20)
        $response = $this->getJson('/api/contracts');
        $response->assertStatus(200);
        $response->assertJsonCount(20, 'data');
        $response->assertJsonPath('meta.total', 25);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.last_page', 2);

        // Second page
        $response = $this->getJson('/api/contracts?page=2');
        $response->assertStatus(200);
        $response->assertJsonCount(5, 'data');
        $response->assertJsonPath('meta.current_page', 2);
    }

    /**
     * Test custom per_page parameter.
     */
    public function test_contracts_custom_per_page(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            ElectricityContract::create([
                'id' => "perpage-contract-{$i}",
                'company_name' => 'Test Company Oy',
                'name' => "Per Page Contract {$i}",
                'contract_type' => 'Fixed',
                'metering' => 'General',
                'availability_is_national' => true,
            ]);
        }

        $response = $this->getJson('/api/contracts?per_page=10');
        $response->assertStatus(200);
        $response->assertJsonCount(10, 'data');
        $response->assertJsonPath('meta.per_page', 10);
    }

    /**
     * Test filtering by multiple parameters.
     */
    public function test_filter_contracts_multiple_params(): void
    {
        ElectricityContract::create([
            'id' => 'multi-filter-match',
            'company_name' => 'Test Company Oy',
            'name' => 'Multi Filter Match Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        ElectricityContract::create([
            'id' => 'multi-filter-no-match',
            'company_name' => 'Test Company Oy',
            'name' => 'Multi Filter No Match Contract',
            'contract_type' => 'Spot',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        ElectricityContract::create([
            'id' => 'multi-filter-no-match-2',
            'company_name' => 'Test Company Oy',
            'name' => 'Multi Filter No Match 2 Contract',
            'contract_type' => 'Fixed',
            'metering' => 'Time',
            'availability_is_national' => true,
        ]);

        $response = $this->getJson('/api/contracts?contract_type=Fixed&metering=General');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Multi Filter Match Contract']);
    }
}
