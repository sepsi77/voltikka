<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\Postcode;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContractsFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test companies
        Company::create([
            'name' => 'Test Energia Oy',
            'name_slug' => 'test-energia-oy',
            'company_url' => 'https://testenergia.fi',
            'logo_url' => 'https://storage.example.com/logos/test-energia.png',
        ]);

        Company::create([
            'name' => 'Halpa Sähkö Ab',
            'name_slug' => 'halpa-sahko-ab',
            'company_url' => 'https://halpasahko.fi',
            'logo_url' => 'https://storage.example.com/logos/halpa.png',
        ]);
    }

    /**
     * Create a test contract with price components.
     */
    private function createContract(array $attributes = []): ElectricityContract
    {
        $defaults = [
            'id' => 'contract-' . uniqid(),
            'company_name' => 'Test Energia Oy',
            'name' => 'Test Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ];

        $contract = ElectricityContract::create(array_merge($defaults, $attributes));

        // Add basic price components
        PriceComponent::create([
            'id' => 'pc-general-' . $contract->id,
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.5,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-' . $contract->id,
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 2.95,
            'payment_unit' => 'EUR/month',
        ]);

        return $contract;
    }

    // ========================================
    // Contract Type Filter Tests
    // ========================================

    /**
     * Test filter controls are displayed on the page.
     */
    public function test_filter_controls_are_displayed(): void
    {
        Livewire::test('contracts-list')
            ->assertSee('Hinnoittelumalli') // Pricing model filter label
            ->assertSee('Sopimuksen kesto') // Contract duration filter label
            ->assertSee('Energialähde');    // Energy source filter label
    }

    /**
     * Test filtering by contract type Fixed.
     */
    public function test_filter_by_contract_type_fixed(): void
    {
        $fixedContract = $this->createContract([
            'id' => 'fixed-contract',
            'name' => 'Kiinteä Sähkö',
            'contract_type' => 'Fixed',
        ]);

        $spotContract = $this->createContract([
            'id' => 'spot-contract',
            'name' => 'Spot Sähkö',
            'contract_type' => 'Spot',
        ]);

        $component = Livewire::test('contracts-list')
            ->set('contractTypeFilter', 'Fixed');

        $contracts = $component->viewData('contracts');

        $this->assertTrue($contracts->contains('id', 'fixed-contract'));
        $this->assertFalse($contracts->contains('id', 'spot-contract'));
    }

    /**
     * Test filtering by pricing model Spot.
     */
    public function test_filter_by_pricing_model_spot(): void
    {
        $fixedContract = $this->createContract([
            'id' => 'fixed-contract',
            'name' => 'Kiinteä Sähkö',
            'contract_type' => 'FixedTerm',
            'pricing_model' => 'FixedPrice',
        ]);

        $spotContract = $this->createContract([
            'id' => 'spot-contract',
            'name' => 'Spot Sähkö',
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'Spot',
        ]);

        $component = Livewire::test('contracts-list')
            ->set('pricingModelFilter', 'Spot');

        $contracts = $component->viewData('contracts');

        $this->assertFalse($contracts->contains('id', 'fixed-contract'));
        $this->assertTrue($contracts->contains('id', 'spot-contract'));
    }

    /**
     * Test filtering by pricing model Hybrid.
     */
    public function test_filter_by_pricing_model_hybrid(): void
    {
        $fixedContract = $this->createContract([
            'id' => 'fixed-contract',
            'name' => 'Kiinteä Sähkö',
            'contract_type' => 'FixedTerm',
            'pricing_model' => 'FixedPrice',
        ]);

        $hybridContract = $this->createContract([
            'id' => 'hybrid-contract',
            'name' => 'Hybridi Sähkö',
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'Hybrid',
        ]);

        $component = Livewire::test('contracts-list')
            ->set('pricingModelFilter', 'Hybrid');

        $contracts = $component->viewData('contracts');

        $this->assertFalse($contracts->contains('id', 'fixed-contract'));
        $this->assertTrue($contracts->contains('id', 'hybrid-contract'));
    }

    /**
     * Test filtering by contract type OpenEnded.
     */
    public function test_filter_by_contract_type_open_ended(): void
    {
        $fixedContract = $this->createContract([
            'id' => 'fixed-contract',
            'name' => 'Kiinteä Sähkö',
            'contract_type' => 'Fixed',
        ]);

        $openEndedContract = $this->createContract([
            'id' => 'open-ended-contract',
            'name' => 'Toistaiseksi Voimassa',
            'contract_type' => 'OpenEnded',
        ]);

        $component = Livewire::test('contracts-list')
            ->set('contractTypeFilter', 'OpenEnded');

        $contracts = $component->viewData('contracts');

        $this->assertFalse($contracts->contains('id', 'fixed-contract'));
        $this->assertTrue($contracts->contains('id', 'open-ended-contract'));
    }

    /**
     * Test clearing contract type filter shows all contracts.
     */
    public function test_clear_contract_type_filter_shows_all(): void
    {
        $fixedContract = $this->createContract([
            'id' => 'fixed-contract',
            'contract_type' => 'Fixed',
        ]);

        $spotContract = $this->createContract([
            'id' => 'spot-contract',
            'contract_type' => 'Spot',
        ]);

        $component = Livewire::test('contracts-list')
            ->set('contractTypeFilter', 'Fixed')
            ->set('contractTypeFilter', ''); // Clear filter

        $contracts = $component->viewData('contracts');

        $this->assertTrue($contracts->contains('id', 'fixed-contract'));
        $this->assertTrue($contracts->contains('id', 'spot-contract'));
    }

    // ========================================
    // Metering Type Filter Tests
    // ========================================

    /**
     * Test filtering by metering type General.
     */
    public function test_filter_by_metering_type_general(): void
    {
        $generalContract = $this->createContract([
            'id' => 'general-contract',
            'name' => 'Yleis Sähkö',
            'metering' => 'General',
        ]);

        $timeContract = $this->createContract([
            'id' => 'time-contract',
            'name' => 'Aika Sähkö',
            'metering' => 'Time',
        ]);

        $component = Livewire::test('contracts-list')
            ->set('meteringFilter', 'General');

        $contracts = $component->viewData('contracts');

        $this->assertTrue($contracts->contains('id', 'general-contract'));
        $this->assertFalse($contracts->contains('id', 'time-contract'));
    }

    /**
     * Test filtering by metering type Time.
     */
    public function test_filter_by_metering_type_time(): void
    {
        $generalContract = $this->createContract([
            'id' => 'general-contract',
            'metering' => 'General',
        ]);

        $timeContract = $this->createContract([
            'id' => 'time-contract',
            'metering' => 'Time',
        ]);

        $component = Livewire::test('contracts-list')
            ->set('meteringFilter', 'Time');

        $contracts = $component->viewData('contracts');

        $this->assertFalse($contracts->contains('id', 'general-contract'));
        $this->assertTrue($contracts->contains('id', 'time-contract'));
    }

    /**
     * Test filtering by metering type Seasonal.
     */
    public function test_filter_by_metering_type_seasonal(): void
    {
        $generalContract = $this->createContract([
            'id' => 'general-contract',
            'metering' => 'General',
        ]);

        $seasonalContract = $this->createContract([
            'id' => 'seasonal-contract',
            'metering' => 'Seasonal',
        ]);

        $component = Livewire::test('contracts-list')
            ->set('meteringFilter', 'Seasonal');

        $contracts = $component->viewData('contracts');

        $this->assertFalse($contracts->contains('id', 'general-contract'));
        $this->assertTrue($contracts->contains('id', 'seasonal-contract'));
    }

    // ========================================
    // Postcode Filter Tests
    // ========================================

    /**
     * Test filtering by postcode shows only contracts available in that area.
     */
    public function test_filter_by_postcode(): void
    {
        // Create postcodes
        $postcode00100 = Postcode::create([
            'postcode' => '00100',
            'postcode_fi_name' => 'Helsinki',
            'postcode_fi_name_slug' => 'helsinki',
            'municipal_name_fi' => 'Helsinki',
            'municipal_name_fi_slug' => 'helsinki',
        ]);

        $postcode33100 = Postcode::create([
            'postcode' => '33100',
            'postcode_fi_name' => 'Tampere',
            'postcode_fi_name_slug' => 'tampere',
            'municipal_name_fi' => 'Tampere',
            'municipal_name_fi_slug' => 'tampere',
        ]);

        // Create contracts - one national, one regional
        $nationalContract = $this->createContract([
            'id' => 'national-contract',
            'name' => 'Valtakunnallinen',
            'availability_is_national' => true,
        ]);

        $helsinkiOnlyContract = $this->createContract([
            'id' => 'helsinki-contract',
            'name' => 'Helsinki Vain',
            'availability_is_national' => false,
        ]);

        // Attach Helsinki postcode to the regional contract
        $helsinkiOnlyContract->availabilityPostcodes()->attach('00100');

        $component = Livewire::test('contracts-list')
            ->set('postcodeFilter', '00100');

        $contracts = $component->viewData('contracts');

        // National contracts should always be visible
        $this->assertTrue($contracts->contains('id', 'national-contract'));
        // Helsinki-only contract should be visible for Helsinki postcode
        $this->assertTrue($contracts->contains('id', 'helsinki-contract'));

        // Now filter by Tampere - Helsinki contract should not be visible
        $component = Livewire::test('contracts-list')
            ->set('postcodeFilter', '33100');

        $contracts = $component->viewData('contracts');

        $this->assertTrue($contracts->contains('id', 'national-contract'));
        $this->assertFalse($contracts->contains('id', 'helsinki-contract'));
    }

    /**
     * Test postcode search autocomplete suggestions.
     */
    public function test_postcode_search_provides_suggestions(): void
    {
        Postcode::create([
            'postcode' => '00100',
            'postcode_fi_name' => 'Helsinki',
            'postcode_fi_name_slug' => 'helsinki',
            'municipal_name_fi' => 'Helsinki',
            'municipal_name_fi_slug' => 'helsinki',
        ]);

        Postcode::create([
            'postcode' => '00180',
            'postcode_fi_name' => 'Helsinki Kamppi',
            'postcode_fi_name_slug' => 'helsinki-kamppi',
            'municipal_name_fi' => 'Helsinki',
            'municipal_name_fi_slug' => 'helsinki',
        ]);

        $component = Livewire::test('contracts-list')
            ->set('postcodeSearch', '001');

        $suggestions = $component->viewData('postcodeSuggestions');

        $this->assertCount(2, $suggestions);
        $this->assertTrue($suggestions->contains('postcode', '00100'));
        $this->assertTrue($suggestions->contains('postcode', '00180'));
    }

    /**
     * Test postcode search by municipality name.
     */
    public function test_postcode_search_by_municipality_name(): void
    {
        Postcode::create([
            'postcode' => '00100',
            'postcode_fi_name' => 'Helsinki',
            'postcode_fi_name_slug' => 'helsinki',
            'municipal_name_fi' => 'Helsinki',
            'municipal_name_fi_slug' => 'helsinki',
        ]);

        Postcode::create([
            'postcode' => '33100',
            'postcode_fi_name' => 'Tampere keskus',
            'postcode_fi_name_slug' => 'tampere-keskus',
            'municipal_name_fi' => 'Tampere',
            'municipal_name_fi_slug' => 'tampere',
        ]);

        $component = Livewire::test('contracts-list')
            ->set('postcodeSearch', 'Helsinki');

        $suggestions = $component->viewData('postcodeSuggestions');

        $this->assertCount(1, $suggestions);
        $this->assertEquals('00100', $suggestions->first()->postcode);
    }

    // ========================================
    // Energy Source Filter Tests
    // ========================================

    /**
     * Test filtering by renewable energy preference.
     */
    public function test_filter_by_renewable_energy(): void
    {
        $renewableContract = $this->createContract([
            'id' => 'renewable-contract',
            'name' => 'Vihreä Sähkö',
        ]);

        ElectricitySource::create([
            'contract_id' => 'renewable-contract',
            'renewable_total' => 100.0,
            'nuclear_total' => 0.0,
            'fossil_total' => 0.0,
        ]);

        $fossilContract = $this->createContract([
            'id' => 'fossil-contract',
            'name' => 'Fossiili Sähkö',
        ]);

        ElectricitySource::create([
            'contract_id' => 'fossil-contract',
            'renewable_total' => 20.0,
            'nuclear_total' => 0.0,
            'fossil_total' => 80.0,
        ]);

        // Filter for contracts with >= 50% renewable
        $component = Livewire::test('contracts-list')
            ->set('renewableFilter', true);

        $contracts = $component->viewData('contracts');

        $this->assertTrue($contracts->contains('id', 'renewable-contract'));
        $this->assertFalse($contracts->contains('id', 'fossil-contract'));
    }

    /**
     * Test filtering by nuclear energy inclusion.
     */
    public function test_filter_by_nuclear_energy(): void
    {
        $nuclearContract = $this->createContract([
            'id' => 'nuclear-contract',
            'name' => 'Ydinvoima Sähkö',
        ]);

        ElectricitySource::create([
            'contract_id' => 'nuclear-contract',
            'renewable_total' => 50.0,
            'nuclear_total' => 50.0,
            'fossil_total' => 0.0,
        ]);

        $noNuclearContract = $this->createContract([
            'id' => 'no-nuclear-contract',
            'name' => 'Ei Ydinvoimaa',
        ]);

        ElectricitySource::create([
            'contract_id' => 'no-nuclear-contract',
            'renewable_total' => 100.0,
            'nuclear_total' => 0.0,
            'fossil_total' => 0.0,
        ]);

        // Filter for contracts with nuclear energy
        $component = Livewire::test('contracts-list')
            ->set('nuclearFilter', true);

        $contracts = $component->viewData('contracts');

        $this->assertTrue($contracts->contains('id', 'nuclear-contract'));
        $this->assertFalse($contracts->contains('id', 'no-nuclear-contract'));
    }

    /**
     * Test filtering to exclude fossil fuels.
     */
    public function test_filter_fossil_free(): void
    {
        $fossilFreeContract = $this->createContract([
            'id' => 'fossil-free-contract',
            'name' => 'Fossiiliton Sähkö',
        ]);

        ElectricitySource::create([
            'contract_id' => 'fossil-free-contract',
            'renewable_total' => 60.0,
            'nuclear_total' => 40.0,
            'fossil_total' => 0.0,
        ]);

        $fossilContract = $this->createContract([
            'id' => 'fossil-contract',
            'name' => 'Fossiili Sähkö',
        ]);

        ElectricitySource::create([
            'contract_id' => 'fossil-contract',
            'renewable_total' => 20.0,
            'nuclear_total' => 0.0,
            'fossil_total' => 80.0,
        ]);

        // Filter for fossil-free contracts
        $component = Livewire::test('contracts-list')
            ->set('fossilFreeFilter', true);

        $contracts = $component->viewData('contracts');

        $this->assertTrue($contracts->contains('id', 'fossil-free-contract'));
        $this->assertFalse($contracts->contains('id', 'fossil-contract'));
    }

    // ========================================
    // Combined Filter Tests
    // ========================================

    /**
     * Test combining contract type and metering filters.
     */
    public function test_combining_contract_type_and_metering_filters(): void
    {
        $fixedGeneral = $this->createContract([
            'id' => 'fixed-general',
            'contract_type' => 'Fixed',
            'metering' => 'General',
        ]);

        $fixedTime = $this->createContract([
            'id' => 'fixed-time',
            'contract_type' => 'Fixed',
            'metering' => 'Time',
        ]);

        $spotGeneral = $this->createContract([
            'id' => 'spot-general',
            'contract_type' => 'Spot',
            'metering' => 'General',
        ]);

        $component = Livewire::test('contracts-list')
            ->set('contractTypeFilter', 'Fixed')
            ->set('meteringFilter', 'General');

        $contracts = $component->viewData('contracts');

        $this->assertTrue($contracts->contains('id', 'fixed-general'));
        $this->assertFalse($contracts->contains('id', 'fixed-time'));
        $this->assertFalse($contracts->contains('id', 'spot-general'));
    }

    /**
     * Test combining all filters together.
     */
    public function test_combining_all_filters(): void
    {
        // Create a postcode
        Postcode::create([
            'postcode' => '00100',
            'postcode_fi_name' => 'Helsinki',
            'postcode_fi_name_slug' => 'helsinki',
            'municipal_name_fi' => 'Helsinki',
            'municipal_name_fi_slug' => 'helsinki',
        ]);

        // Create the perfect match contract
        $perfectMatch = $this->createContract([
            'id' => 'perfect-match',
            'name' => 'Täydellinen Sopimus',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        ElectricitySource::create([
            'contract_id' => 'perfect-match',
            'renewable_total' => 100.0,
            'nuclear_total' => 0.0,
            'fossil_total' => 0.0,
        ]);

        // Create non-matching contracts
        $wrongType = $this->createContract([
            'id' => 'wrong-type',
            'contract_type' => 'Spot',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        ElectricitySource::create([
            'contract_id' => 'wrong-type',
            'renewable_total' => 100.0,
            'nuclear_total' => 0.0,
            'fossil_total' => 0.0,
        ]);

        $wrongEnergy = $this->createContract([
            'id' => 'wrong-energy',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        ElectricitySource::create([
            'contract_id' => 'wrong-energy',
            'renewable_total' => 30.0,
            'nuclear_total' => 0.0,
            'fossil_total' => 70.0,
        ]);

        $component = Livewire::test('contracts-list')
            ->set('contractTypeFilter', 'Fixed')
            ->set('meteringFilter', 'General')
            ->set('postcodeFilter', '00100')
            ->set('renewableFilter', true)
            ->set('fossilFreeFilter', true);

        $contracts = $component->viewData('contracts');

        $this->assertCount(1, $contracts);
        $this->assertTrue($contracts->contains('id', 'perfect-match'));
    }

    // ========================================
    // Filter Reset Tests
    // ========================================

    /**
     * Test resetting all filters.
     */
    public function test_reset_all_filters(): void
    {
        $contract1 = $this->createContract(['id' => 'contract-1', 'contract_type' => 'Fixed']);
        $contract2 = $this->createContract(['id' => 'contract-2', 'contract_type' => 'Spot']);

        $component = Livewire::test('contracts-list')
            ->set('contractTypeFilter', 'Fixed')
            ->set('meteringFilter', 'General')
            ->call('resetFilters');

        $this->assertEquals('', $component->get('contractTypeFilter'));
        $this->assertEquals('', $component->get('meteringFilter'));

        $contracts = $component->viewData('contracts');
        $this->assertCount(2, $contracts);
    }

    /**
     * Test that filter state persists across Livewire updates.
     */
    public function test_filter_state_persists(): void
    {
        $this->createContract(['id' => 'contract-1', 'contract_type' => 'Fixed']);

        $component = Livewire::test('contracts-list')
            ->set('contractTypeFilter', 'Fixed')
            ->set('consumption', 10000); // Change consumption (triggers update)

        // Filter should still be applied
        $this->assertEquals('Fixed', $component->get('contractTypeFilter'));
    }

    // ========================================
    // UI Interaction Tests
    // ========================================

    /**
     * Test that active filters are visually indicated.
     */
    public function test_active_filters_are_indicated(): void
    {
        $this->createContract(['id' => 'contract-1']);

        Livewire::test('contracts-list')
            ->set('contractTypeFilter', 'FixedTerm')
            ->assertSeeHtml('bg-slate-950'); // Active filter indicator class
    }

    /**
     * Test that filter counts are displayed.
     */
    public function test_filter_counts_displayed(): void
    {
        $this->createContract(['id' => 'contract-1', 'contract_type' => 'Fixed']);
        $this->createContract(['id' => 'contract-2', 'contract_type' => 'Fixed']);
        $this->createContract(['id' => 'contract-3', 'contract_type' => 'Spot']);

        $component = Livewire::test('contracts-list')
            ->set('contractTypeFilter', 'Fixed');

        // Should show count of filtered contracts
        $contracts = $component->viewData('contracts');
        $this->assertCount(2, $contracts);
    }
}
