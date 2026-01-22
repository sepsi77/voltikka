<?php

namespace Tests\Feature;

use App\Livewire\SolarCalculator;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\DTO\SolarEstimateResult;
use App\Services\SolarCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SolarCalculatorSavingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test company
        Company::create([
            'name' => 'Test Energia Oy',
            'name_slug' => 'test-energia-oy',
            'company_url' => 'https://testenergia.fi',
        ]);

        Company::create([
            'name' => 'Another Sähkö Ab',
            'name_slug' => 'another-sahko-ab',
            'company_url' => 'https://anothersahko.fi',
        ]);
    }

    private function createContractWithPrice(string $id, string $name, string $companyName, float $priceCents, string $metering = 'General'): void
    {
        ElectricityContract::create([
            'id' => $id,
            'company_name' => $companyName,
            'name' => $name,
            'name_slug' => ElectricityContract::generateSlug($name),
            'contract_type' => 'FixedTerm',
            'metering' => $metering,
            'pricing_model' => 'FixedPrice',
            'target_group' => 'Household',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => "pc-general-{$id}",
            'electricity_contract_id' => $id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => $priceCents,
            'payment_unit' => 'c/kWh',
        ]);
    }

    private function createTimeBasedContract(string $id, string $name, string $companyName, float $dayPrice, float $nightPrice): void
    {
        ElectricityContract::create([
            'id' => $id,
            'company_name' => $companyName,
            'name' => $name,
            'name_slug' => ElectricityContract::generateSlug($name),
            'contract_type' => 'FixedTerm',
            'metering' => 'Time',
            'pricing_model' => 'FixedPrice',
            'target_group' => 'Household',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => "pc-day-{$id}",
            'electricity_contract_id' => $id,
            'price_component_type' => 'DayTime',
            'price_date' => now()->format('Y-m-d'),
            'price' => $dayPrice,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => "pc-night-{$id}",
            'electricity_contract_id' => $id,
            'price_component_type' => 'NightTime',
            'price_date' => now()->format('Y-m-d'),
            'price' => $nightPrice,
            'payment_unit' => 'c/kWh',
        ]);
    }

    private function mockSolarCalculation(float $annualKwh = 5000.0): void
    {
        $mockResult = new SolarEstimateResult(
            annual_kwh: $annualKwh,
            monthly_kwh: array_fill(0, 12, $annualKwh / 12),
            assumptions: ['tilt' => 35, 'azimuth' => 0, 'loss_percent' => 14],
        );

        $this->mock(SolarCalculatorService::class)
            ->shouldReceive('calculate')
            ->andReturn($mockResult);
    }

    // =========================================================================
    // Component Properties Tests
    // =========================================================================

    public function test_component_has_contract_selection_properties(): void
    {
        Livewire::test(SolarCalculator::class)
            ->assertSet('selectedContractId', null)
            ->assertSet('manualPrice', null)
            ->assertSet('selfConsumptionPercent', 30);
    }

    public function test_component_has_price_mode_property(): void
    {
        Livewire::test(SolarCalculator::class)
            ->assertSet('priceMode', 'contract');
    }

    // =========================================================================
    // Contract Dropdown Tests
    // =========================================================================

    public function test_contracts_are_loaded_for_selection(): void
    {
        $this->createContractWithPrice('contract-1', 'Edullinen Sähkö', 'Test Energia Oy', 8.5);
        $this->createContractWithPrice('contract-2', 'Premium Sähkö', 'Another Sähkö Ab', 10.0);

        $component = Livewire::test(SolarCalculator::class);

        $contracts = $component->get('availableContracts');

        $this->assertCount(2, $contracts);
    }

    public function test_contracts_have_required_fields(): void
    {
        $this->createContractWithPrice('contract-1', 'Edullinen Sähkö', 'Test Energia Oy', 8.5);

        $component = Livewire::test(SolarCalculator::class);
        $contracts = $component->get('availableContracts');

        $contract = $contracts[0];
        $this->assertArrayHasKey('id', $contract);
        $this->assertArrayHasKey('name', $contract);
        $this->assertArrayHasKey('company_name', $contract);
        $this->assertArrayHasKey('price_cents', $contract);
    }

    public function test_selecting_contract_sets_contract_id(): void
    {
        $this->createContractWithPrice('contract-1', 'Edullinen Sähkö', 'Test Energia Oy', 8.5);

        Livewire::test(SolarCalculator::class)
            ->set('selectedContractId', 'contract-1')
            ->assertSet('selectedContractId', 'contract-1');
    }

    // =========================================================================
    // Self-Consumption Slider Tests
    // =========================================================================

    public function test_self_consumption_slider_defaults_to_30_percent(): void
    {
        Livewire::test(SolarCalculator::class)
            ->assertSet('selfConsumptionPercent', 30);
    }

    public function test_self_consumption_can_be_adjusted(): void
    {
        Livewire::test(SolarCalculator::class)
            ->set('selfConsumptionPercent', 50)
            ->assertSet('selfConsumptionPercent', 50);
    }

    // =========================================================================
    // Manual Price Entry Tests
    // =========================================================================

    public function test_manual_price_mode_allows_custom_price(): void
    {
        Livewire::test(SolarCalculator::class)
            ->set('priceMode', 'manual')
            ->set('manualPrice', 12.5)
            ->assertSet('priceMode', 'manual')
            ->assertSet('manualPrice', 12.5);
    }

    public function test_switching_to_manual_mode_clears_contract_selection(): void
    {
        $this->createContractWithPrice('contract-1', 'Edullinen Sähkö', 'Test Energia Oy', 8.5);

        Livewire::test(SolarCalculator::class)
            ->set('selectedContractId', 'contract-1')
            ->set('priceMode', 'manual')
            ->assertSet('selectedContractId', null);
    }

    public function test_switching_to_contract_mode_clears_manual_price(): void
    {
        Livewire::test(SolarCalculator::class)
            ->set('priceMode', 'manual')
            ->set('manualPrice', 12.5)
            ->set('priceMode', 'contract')
            ->assertSet('manualPrice', null);
    }

    // =========================================================================
    // Savings Calculation Tests
    // =========================================================================

    public function test_savings_calculated_with_selected_contract(): void
    {
        $this->createContractWithPrice('contract-1', 'Edullinen Sähkö', 'Test Energia Oy', 10.0);
        $this->mockSolarCalculation(5000.0);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('selectedContractId', 'contract-1')
            ->set('selfConsumptionPercent', 30);

        // 5000 kWh * 30% self-consumption * 10 c/kWh / 100 = 150 EUR
        $savings = $component->get('annualSavings');
        $this->assertEqualsWithDelta(150.0, $savings, 0.01);
    }

    public function test_savings_calculated_with_manual_price(): void
    {
        $this->mockSolarCalculation(5000.0);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('priceMode', 'manual')
            ->set('manualPrice', 8.0) // 8 c/kWh
            ->set('selfConsumptionPercent', 40);

        // 5000 kWh * 40% self-consumption * 8 c/kWh / 100 = 160 EUR
        $savings = $component->get('annualSavings');
        $this->assertEqualsWithDelta(160.0, $savings, 0.01);
    }

    public function test_savings_zero_without_production_results(): void
    {
        $this->createContractWithPrice('contract-1', 'Edullinen Sähkö', 'Test Energia Oy', 10.0);

        $component = Livewire::test(SolarCalculator::class)
            ->set('selectedContractId', 'contract-1');

        $savings = $component->get('annualSavings');
        $this->assertEquals(0, $savings);
    }

    public function test_savings_zero_without_price_info(): void
    {
        $this->mockSolarCalculation(5000.0);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0);

        // No contract selected and no manual price
        $savings = $component->get('annualSavings');
        $this->assertEquals(0, $savings);
    }

    public function test_savings_recalculates_when_self_consumption_changes(): void
    {
        $this->createContractWithPrice('contract-1', 'Edullinen Sähkö', 'Test Energia Oy', 10.0);
        $this->mockSolarCalculation(5000.0);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('selectedContractId', 'contract-1')
            ->set('selfConsumptionPercent', 30);

        // 5000 * 0.3 * 10 / 100 = 150 EUR
        $this->assertEqualsWithDelta(150.0, $component->get('annualSavings'), 0.01);

        $component->set('selfConsumptionPercent', 50);
        // 5000 * 0.5 * 10 / 100 = 250 EUR
        $this->assertEqualsWithDelta(250.0, $component->get('annualSavings'), 0.01);
    }

    // =========================================================================
    // Time-Based Contract Tests
    // =========================================================================

    public function test_time_based_contract_uses_weighted_price(): void
    {
        // Day price: 12 c/kWh, Night price: 6 c/kWh
        // Solar production is primarily during day hours
        // Weighted price for solar should use day price (67% day, 33% night)
        $this->createTimeBasedContract('time-contract', 'Aika Sähkö', 'Test Energia Oy', 12.0, 6.0);
        $this->mockSolarCalculation(5000.0);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('selectedContractId', 'time-contract')
            ->set('selfConsumptionPercent', 30);

        // Solar production is primarily during day hours, use day price for simplicity
        // 5000 kWh * 30% * 12 c/kWh / 100 = 180 EUR
        $savings = $component->get('annualSavings');
        $this->assertEqualsWithDelta(180.0, $savings, 0.01);
    }

    // =========================================================================
    // UI Display Tests
    // =========================================================================

    public function test_savings_section_displayed_when_results_exist(): void
    {
        $this->createContractWithPrice('contract-1', 'Edullinen Sähkö', 'Test Energia Oy', 10.0);
        $this->mockSolarCalculation(5000.0);

        Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('selectedContractId', 'contract-1')
            ->assertSee('Arvioitu säästö')
            ->assertSee('150'); // 150 EUR savings
    }

    public function test_selected_contract_info_displayed(): void
    {
        $this->createContractWithPrice('contract-1', 'Edullinen Sähkö', 'Test Energia Oy', 10.0);
        $this->mockSolarCalculation(5000.0);

        Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('selectedContractId', 'contract-1')
            ->assertSee('Edullinen Sähkö')
            ->assertSee('Test Energia Oy');
    }

    public function test_self_consumption_slider_displayed(): void
    {
        Livewire::test(SolarCalculator::class)
            ->assertSee('Oman käytön osuus')
            ->assertSee('30'); // Default 30%
    }

    public function test_contract_dropdown_displayed(): void
    {
        $this->createContractWithPrice('contract-1', 'Edullinen Sähkö', 'Test Energia Oy', 8.5);

        Livewire::test(SolarCalculator::class)
            ->assertSee('Valitse sähkösopimus')
            ->assertSee('Edullinen Sähkö');
    }

    // =========================================================================
    // Computed Property Tests
    // =========================================================================

    public function test_has_savings_computed_property(): void
    {
        $this->createContractWithPrice('contract-1', 'Edullinen Sähkö', 'Test Energia Oy', 10.0);
        $this->mockSolarCalculation(5000.0);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('selectedContractId', 'contract-1');

        $this->assertTrue($component->get('hasSavings'));
    }

    public function test_has_savings_false_without_price(): void
    {
        $this->mockSolarCalculation(5000.0);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0);

        $this->assertFalse($component->get('hasSavings'));
    }

    public function test_effective_price_computed_property(): void
    {
        $this->createContractWithPrice('contract-1', 'Edullinen Sähkö', 'Test Energia Oy', 8.5);

        $component = Livewire::test(SolarCalculator::class)
            ->set('selectedContractId', 'contract-1');

        $this->assertEqualsWithDelta(8.5, $component->get('effectivePrice'), 0.01);
    }

    public function test_effective_price_uses_manual_price_when_set(): void
    {
        $component = Livewire::test(SolarCalculator::class)
            ->set('priceMode', 'manual')
            ->set('manualPrice', 12.5);

        $this->assertEqualsWithDelta(12.5, $component->get('effectivePrice'), 0.01);
    }
}
