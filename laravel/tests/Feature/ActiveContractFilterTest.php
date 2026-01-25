<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ActiveContractFilterTest extends TestCase
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
            'logo_url' => 'https://storage.example.com/logos/test-energia.png',
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
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'Spot',
            'target_group' => 'Household',
            'availability_is_national' => true,
        ];

        $contract = ElectricityContract::create(array_merge($defaults, $attributes));

        // Add basic price components
        PriceComponent::create([
            'id' => 'pc-general-' . $contract->id,
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 0.5,
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

    /**
     * Mark a contract as active by adding it to the active_contracts table.
     */
    private function markAsActive(ElectricityContract $contract): void
    {
        ActiveContract::create(['id' => $contract->id]);
    }

    // ========================================
    // Model Scope and Method Tests
    // ========================================

    /**
     * Test that the active() scope returns only contracts in the active_contracts table.
     */
    public function test_active_scope_returns_only_active_contracts(): void
    {
        $activeContract = $this->createContract(['id' => 'active-contract', 'name' => 'Active Contract']);
        $inactiveContract = $this->createContract(['id' => 'inactive-contract', 'name' => 'Inactive Contract']);

        // Mark only one contract as active
        $this->markAsActive($activeContract);

        $activeContracts = ElectricityContract::active()->get();

        $this->assertCount(1, $activeContracts);
        $this->assertTrue($activeContracts->contains('id', 'active-contract'));
        $this->assertFalse($activeContracts->contains('id', 'inactive-contract'));
    }

    /**
     * Test that isActive() returns true for active contracts.
     */
    public function test_is_active_returns_true_for_active_contracts(): void
    {
        $contract = $this->createContract(['id' => 'active-contract']);
        $this->markAsActive($contract);

        $this->assertTrue($contract->isActive());
    }

    /**
     * Test that isActive() returns false for inactive contracts.
     */
    public function test_is_active_returns_false_for_inactive_contracts(): void
    {
        $contract = $this->createContract(['id' => 'inactive-contract']);

        $this->assertFalse($contract->isActive());
    }

    /**
     * Test the activeContract relationship.
     */
    public function test_active_contract_relationship(): void
    {
        $activeContract = $this->createContract(['id' => 'active-contract']);
        $inactiveContract = $this->createContract(['id' => 'inactive-contract']);

        $this->markAsActive($activeContract);

        $this->assertNotNull($activeContract->activeContract);
        $this->assertInstanceOf(ActiveContract::class, $activeContract->activeContract);
        $this->assertNull($inactiveContract->activeContract);
    }

    // ========================================
    // ContractsList Livewire Component Tests
    // ========================================

    /**
     * Test that ContractsList only shows active contracts.
     */
    public function test_contracts_list_shows_only_active_contracts(): void
    {
        $activeContract = $this->createContract(['id' => 'active-contract', 'name' => 'Active Sähkö']);
        $inactiveContract = $this->createContract(['id' => 'inactive-contract', 'name' => 'Inactive Sähkö']);

        $this->markAsActive($activeContract);

        $component = Livewire::test('contracts-list');
        $contracts = $component->viewData('contracts');

        $this->assertTrue($contracts->contains('id', 'active-contract'));
        $this->assertFalse($contracts->contains('id', 'inactive-contract'));
    }

    /**
     * Test that ContractsList shows no contracts when none are active.
     */
    public function test_contracts_list_shows_no_contracts_when_none_active(): void
    {
        $this->createContract(['id' => 'inactive-1']);
        $this->createContract(['id' => 'inactive-2']);

        $component = Livewire::test('contracts-list');
        $contracts = $component->viewData('contracts');

        $this->assertCount(0, $contracts);
    }

    // ========================================
    // ContractDetail Livewire Component Tests
    // ========================================

    /**
     * Test that inactive contracts are still accessible on detail page.
     */
    public function test_contract_detail_shows_inactive_contract(): void
    {
        $inactiveContract = $this->createContract(['id' => 'inactive-contract', 'name' => 'Inactive Sähkö']);

        // The contract should still be accessible (not 404)
        Livewire::test('contract-detail', ['contractId' => 'inactive-contract'])
            ->assertStatus(200)
            ->assertSee('Inactive Sähkö');
    }

    /**
     * Test that inactive contracts show the warning banner.
     */
    public function test_contract_detail_shows_inactive_banner(): void
    {
        $inactiveContract = $this->createContract(['id' => 'inactive-contract', 'name' => 'Inactive Sähkö']);

        Livewire::test('contract-detail', ['contractId' => 'inactive-contract'])
            ->assertSee('Tämä sopimus ei ole enää tarjolla.');
    }

    /**
     * Test that active contracts do NOT show the warning banner.
     */
    public function test_contract_detail_does_not_show_banner_for_active_contracts(): void
    {
        $activeContract = $this->createContract(['id' => 'active-contract', 'name' => 'Active Sähkö']);
        $this->markAsActive($activeContract);

        Livewire::test('contract-detail', ['contractId' => 'active-contract'])
            ->assertDontSee('Tämä sopimus ei ole enää tarjolla.');
    }

    /**
     * Test the isActive computed property in ContractDetail.
     */
    public function test_contract_detail_is_active_property(): void
    {
        $activeContract = $this->createContract(['id' => 'active-contract']);
        $inactiveContract = $this->createContract(['id' => 'inactive-contract']);

        $this->markAsActive($activeContract);

        // Active contract
        $component = Livewire::test('contract-detail', ['contractId' => 'active-contract']);
        $this->assertTrue($component->get('isActive'));

        // Inactive contract
        $component = Livewire::test('contract-detail', ['contractId' => 'inactive-contract']);
        $this->assertFalse($component->get('isActive'));
    }

    // ========================================
    // HomePage Tests
    // ========================================

    /**
     * Test that HomePage count only includes active contracts.
     */
    public function test_homepage_contract_count_only_includes_active_contracts(): void
    {
        $activeContract1 = $this->createContract(['id' => 'active-1']);
        $activeContract2 = $this->createContract(['id' => 'active-2']);
        $inactiveContract = $this->createContract(['id' => 'inactive-1']);

        $this->markAsActive($activeContract1);
        $this->markAsActive($activeContract2);

        $component = Livewire::test('home-page');
        $contractCount = $component->viewData('contractCount');

        $this->assertEquals(2, $contractCount);
    }

    // ========================================
    // Edge Case Tests
    // ========================================

    /**
     * Test that active scope works with other query constraints.
     */
    public function test_active_scope_works_with_other_constraints(): void
    {
        $activeSpot = $this->createContract(['id' => 'active-spot', 'pricing_model' => 'Spot']);
        $activeFixed = $this->createContract(['id' => 'active-fixed', 'pricing_model' => 'FixedPrice']);
        $inactiveSpot = $this->createContract(['id' => 'inactive-spot', 'pricing_model' => 'Spot']);

        $this->markAsActive($activeSpot);
        $this->markAsActive($activeFixed);

        $activeSpotContracts = ElectricityContract::active()
            ->where('pricing_model', 'Spot')
            ->get();

        $this->assertCount(1, $activeSpotContracts);
        $this->assertTrue($activeSpotContracts->contains('id', 'active-spot'));
    }

    /**
     * Test that making a contract active/inactive updates isActive() correctly.
     */
    public function test_is_active_updates_with_active_contract_table_changes(): void
    {
        $contract = $this->createContract(['id' => 'test-contract']);

        // Initially inactive
        $this->assertFalse($contract->isActive());

        // Mark as active
        $this->markAsActive($contract);

        // Refresh the model to clear relationship cache
        $contract->refresh();
        $this->assertTrue($contract->isActive());

        // Remove from active contracts
        ActiveContract::where('id', $contract->id)->delete();

        // Refresh the model to clear relationship cache
        $contract->refresh();
        $this->assertFalse($contract->isActive());
    }
}
