<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContractDetailPageTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected ElectricityContract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test company
        $this->company = Company::create([
            'name' => 'Test Energia Oy',
            'name_slug' => 'test-energia-oy',
            'company_url' => 'https://testenergia.fi',
            'street_address' => 'Energiakatu 1',
            'postal_code' => '00100',
            'postal_name' => 'Helsinki',
            'logo_url' => 'https://storage.example.com/logos/test-energia.png',
        ]);

        // Create test contract
        $this->contract = ElectricityContract::create([
            'id' => 'contract-detail-test',
            'company_name' => 'Test Energia Oy',
            'name' => 'Perus Sähkö 24kk',
            'name_slug' => 'perus-sahko-24kk',
            'contract_type' => 'Fixed',
            'fixed_time_range' => '24 months',
            'metering' => 'General',
            'short_description' => 'Edullinen kiinteähintainen sähkösopimus.',
            'long_description' => 'Tämä on pidempi kuvaus sopimuksesta. Se sisältää lisätietoja hinnoittelusta ja ehdoista.',
            'product_link' => 'https://testenergia.fi/products/perus-sahko',
            'order_link' => 'https://testenergia.fi/order/perus-sahko',
            'billing_frequency' => ['monthly'],
            'availability_is_national' => true,
            'microproduction_buys' => true,
            'microproduction_default' => 'Ostamme ylituotannon spot-hintaan.',
        ]);

        // Create price components
        PriceComponent::create([
            'id' => 'pc-general-detail',
            'electricity_contract_id' => 'contract-detail-test',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.5,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-detail',
            'electricity_contract_id' => 'contract-detail-test',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 2.95,
            'payment_unit' => 'EUR/month',
        ]);

        // Create electricity source
        ElectricitySource::create([
            'contract_id' => 'contract-detail-test',
            'renewable_total' => 65.0,
            'renewable_wind' => 40.0,
            'renewable_hydro' => 20.0,
            'renewable_solar' => 5.0,
            'nuclear_total' => 30.0,
            'fossil_total' => 5.0,
        ]);
    }

    /**
     * Test that the contract detail page is accessible.
     */
    public function test_contract_detail_page_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/sopimus/contract-detail-test');

        $response->assertStatus(200);
    }

    /**
     * Test that the contract detail page displays the Livewire component.
     */
    public function test_contract_detail_page_renders_livewire_component(): void
    {
        $response = $this->get('/sahkosopimus/sopimus/contract-detail-test');

        $response->assertStatus(200);
        $response->assertSeeLivewire('contract-detail');
    }

    /**
     * Test that the contract name is displayed.
     */
    public function test_contract_name_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Perus Sähkö 24kk');
    }

    /**
     * Test that the company name is displayed.
     */
    public function test_company_name_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Test Energia Oy');
    }

    /**
     * Test that the company logo is displayed.
     */
    public function test_company_logo_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSeeHtml('https://storage.example.com/logos/test-energia.png');
    }

    /**
     * Test that the contract type is displayed.
     */
    public function test_contract_type_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Fixed');
    }

    /**
     * Test that the fixed time range is displayed.
     */
    public function test_fixed_time_range_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('24 months');
    }

    /**
     * Test that the metering type is displayed.
     */
    public function test_metering_type_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('General');
    }

    /**
     * Test that the short description is displayed.
     */
    public function test_short_description_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Edullinen kiinteähintainen sähkösopimus.');
    }

    /**
     * Test that the long description is displayed.
     */
    public function test_long_description_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Tämä on pidempi kuvaus sopimuksesta.');
    }

    /**
     * Test that the energy price is displayed.
     */
    public function test_energy_price_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('5,5'); // Finnish number format
    }

    /**
     * Test that the monthly fee is displayed.
     */
    public function test_monthly_fee_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('2,95'); // Finnish number format
    }

    /**
     * Test that the electricity source breakdown is displayed.
     */
    public function test_electricity_source_breakdown_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('65') // Renewable total
            ->assertSee('30') // Nuclear total
            ->assertSee('5');  // Fossil total (but context might match price too)
    }

    /**
     * Test that the renewable energy breakdown is displayed.
     */
    public function test_renewable_breakdown_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('40') // Wind
            ->assertSee('20'); // Hydro
    }

    /**
     * Test that the product link is displayed.
     */
    public function test_product_link_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSeeHtml('https://testenergia.fi/products/perus-sahko');
    }

    /**
     * Test that the order link is displayed.
     */
    public function test_order_link_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSeeHtml('https://testenergia.fi/order/perus-sahko');
    }

    /**
     * Test that the annual cost is calculated and displayed.
     */
    public function test_annual_cost_is_displayed(): void
    {
        // Default 5000 kWh consumption
        // Energy: 5.5 * 5000 / 100 = 275 EUR
        // Monthly: 2.95 * 12 = 35.40 EUR
        // Total: 310.40 EUR
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('310'); // Approximate match
    }

    /**
     * Test that changing consumption updates the cost.
     */
    public function test_changing_consumption_updates_cost(): void
    {
        // 10000 kWh consumption
        // Energy: 5.5 * 10000 / 100 = 550 EUR
        // Monthly: 2.95 * 12 = 35.40 EUR
        // Total: 585.40 EUR
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->set('consumption', 10000)
            ->assertSee('585'); // Approximate match
    }

    /**
     * Test that consumption presets are available.
     */
    public function test_consumption_presets_are_available(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('2000 kWh')  // Studio
            ->assertSee('5000 kWh')  // Apartment
            ->assertSee('10000 kWh') // Small house
            ->assertSee('18000 kWh'); // Large house
    }

    /**
     * Test that microproduction info is displayed when available.
     */
    public function test_microproduction_info_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Ostamme ylituotannon spot-hintaan.');
    }

    /**
     * Test that company address is displayed.
     */
    public function test_company_address_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Energiakatu 1')
            ->assertSee('00100')
            ->assertSee('Helsinki');
    }

    /**
     * Test that 404 is returned for non-existent contract.
     */
    public function test_404_for_non_existent_contract(): void
    {
        $response = $this->get('/sahkosopimus/sopimus/non-existent-contract');

        $response->assertStatus(404);
    }

    /**
     * Test that price history is displayed when multiple price dates exist.
     */
    public function test_price_history_is_displayed(): void
    {
        // Add historical price
        PriceComponent::create([
            'id' => 'pc-general-history',
            'electricity_contract_id' => 'contract-detail-test',
            'price_component_type' => 'General',
            'price_date' => now()->subMonth()->format('Y-m-d'),
            'price' => 6.0, // Old higher price
            'payment_unit' => 'c/kWh',
        ]);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('6,0'); // Historical price should be shown
    }

    /**
     * Test that back to list link is present.
     */
    public function test_back_to_list_link_is_present(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSeeHtml('href="/"'); // Back link to contracts list
    }

    /**
     * Test time-based metering contract shows day/night prices.
     */
    public function test_time_metering_shows_day_night_prices(): void
    {
        $timeContract = ElectricityContract::create([
            'id' => 'time-metering-contract',
            'company_name' => 'Test Energia Oy',
            'name' => 'Aika Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'Time',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-day-time',
            'electricity_contract_id' => 'time-metering-contract',
            'price_component_type' => 'DayTime',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-night-time',
            'electricity_contract_id' => 'time-metering-contract',
            'price_component_type' => 'NightTime',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-time',
            'electricity_contract_id' => 'time-metering-contract',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 3.50,
            'payment_unit' => 'EUR/month',
        ]);

        Livewire::test('contract-detail', ['contractId' => 'time-metering-contract'])
            ->assertSee('6,0')  // Day price
            ->assertSee('4,0'); // Night price
    }

    /**
     * Test seasonal metering contract shows seasonal prices.
     */
    public function test_seasonal_metering_shows_seasonal_prices(): void
    {
        $seasonalContract = ElectricityContract::create([
            'id' => 'seasonal-metering-contract',
            'company_name' => 'Test Energia Oy',
            'name' => 'Kausi Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'Season',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-winter-seasonal',
            'electricity_contract_id' => 'seasonal-metering-contract',
            'price_component_type' => 'SeasonalWinterDay',
            'price_date' => now()->format('Y-m-d'),
            'price' => 7.0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-other-seasonal',
            'electricity_contract_id' => 'seasonal-metering-contract',
            'price_component_type' => 'SeasonalOther',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.5,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-seasonal',
            'electricity_contract_id' => 'seasonal-metering-contract',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.00,
            'payment_unit' => 'EUR/month',
        ]);

        Livewire::test('contract-detail', ['contractId' => 'seasonal-metering-contract'])
            ->assertSee('7,0')  // Winter price
            ->assertSee('4,5'); // Other season price
    }

    /**
     * Test that presets are filtered when contract has minimum consumption limit.
     */
    public function test_presets_filtered_by_minimum_consumption_limit(): void
    {
        // Create contract with min consumption of 8000 kWh
        $this->contract->update([
            'consumption_limitation_min_x_kwh_per_y' => 8000,
        ]);

        // Only "Pieni talo" (10000) and "Suuri talo" (18000) should be shown
        // "Yksiö" (2000) and "Kerrostalo" (5000) should be hidden
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertDontSee('2000 kWh')  // Below min
            ->assertDontSee('5000 kWh')  // Below min
            ->assertSee('10000 kWh')     // Above min
            ->assertSee('18000 kWh');    // Above min
    }

    /**
     * Test that presets are filtered when contract has maximum consumption limit.
     */
    public function test_presets_filtered_by_maximum_consumption_limit(): void
    {
        // Create contract with max consumption of 6000 kWh
        $this->contract->update([
            'consumption_limitation_max_x_kwh_per_y' => 6000,
        ]);

        // Only "Yksiö" (2000) and "Kerrostalo" (5000) should be shown
        // "Pieni talo" (10000) and "Suuri talo" (18000) should be hidden
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('2000 kWh')       // Below max
            ->assertSee('5000 kWh')       // Below max
            ->assertDontSee('10000 kWh')  // Above max
            ->assertDontSee('18000 kWh'); // Above max
    }

    /**
     * Test that presets are filtered when contract has both min and max limits.
     */
    public function test_presets_filtered_by_both_consumption_limits(): void
    {
        // Create contract with range 4000-12000 kWh
        $this->contract->update([
            'consumption_limitation_min_x_kwh_per_y' => 4000,
            'consumption_limitation_max_x_kwh_per_y' => 12000,
        ]);

        // Only "Kerrostalo" (5000) and "Pieni talo" (10000) should be shown
        // "Yksiö" (2000) is below min, "Suuri talo" (18000) is above max
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertDontSee('2000 kWh')   // Below min
            ->assertSee('5000 kWh')       // Within range
            ->assertSee('10000 kWh')      // Within range
            ->assertDontSee('18000 kWh'); // Above max
    }

    /**
     * Test that consumption notice is shown when contract has limits.
     */
    public function test_consumption_limits_notice_displayed(): void
    {
        // Create contract with range
        $this->contract->update([
            'consumption_limitation_min_x_kwh_per_y' => 5000,
            'consumption_limitation_max_x_kwh_per_y' => 15000,
        ]);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('5 000')   // Min limit formatted
            ->assertSee('15 000'); // Max limit formatted
    }

    /**
     * Test that default consumption is adjusted when out of range.
     */
    public function test_default_consumption_adjusted_to_range(): void
    {
        // Default is 5000, set min to 8000
        $this->contract->update([
            'consumption_limitation_min_x_kwh_per_y' => 8000,
        ]);

        // The default consumption should be adjusted to the minimum
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSet('consumption', 8000);
    }

    /**
     * Test that setConsumption respects contract limits.
     */
    public function test_set_consumption_respects_limits(): void
    {
        // Set limits
        $this->contract->update([
            'consumption_limitation_min_x_kwh_per_y' => 3000,
            'consumption_limitation_max_x_kwh_per_y' => 10000,
        ]);

        // Try to set consumption below min - should clamp to min
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->call('setConsumption', 1000)
            ->assertSet('consumption', 3000);

        // Try to set consumption above max - should clamp to max
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->call('setConsumption', 20000)
            ->assertSet('consumption', 10000);
    }
}
