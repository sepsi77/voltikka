<?php

namespace Tests\Feature;

use App\Livewire\ContractTypeComparison;
use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContractTypeComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create([
            'name' => 'Test Energia Oy',
            'name_slug' => 'test-energia-oy',
            'company_url' => 'https://example.test',
        ]);
    }

    public function test_initial_render_does_not_dump_all_contract_names(): void
    {
        $this->createContract('spot-cheapest', 'Halpa Spot', 'Spot', 0.2);
        $this->createContract('spot-extra', 'Crawler Dump Spot Name', 'Spot', 5.0);
        $this->createContract('fixed-cheapest', 'Halpa Kiinteä', 'FixedPrice', 4.0);
        $this->createContract('fixed-extra', 'Crawler Dump Fixed Name', 'FixedPrice', 25.0);

        Livewire::test(ContractTypeComparison::class)
            ->assertSee('Vaihda sopimus')
            ->assertSee('Halpa Spot')
            ->assertSee('Halpa Kiinteä')
            ->assertDontSee('Crawler Dump Spot Name')
            ->assertDontSee('Crawler Dump Fixed Name');
    }

    public function test_contract_search_renders_matching_results_only_after_interaction(): void
    {
        $this->createContract('spot-cheapest', 'Halpa Spot', 'Spot', 0.2);
        $this->createContract('spot-extra', 'Searchable Spot Name', 'Spot', 5.0);
        $this->createContract('fixed-cheapest', 'Halpa Kiinteä', 'FixedPrice', 4.0);

        Livewire::test(ContractTypeComparison::class)
            ->assertDontSee('Searchable Spot Name')
            ->call('openSelectorA')
            ->set('contractSearchA', 'Searchable')
            ->assertSee('Searchable Spot Name');
    }

    private function createContract(string $id, string $name, string $pricingModel, float $energyPrice): ElectricityContract
    {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => 'Test Energia Oy',
            'name' => $name,
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => $pricingModel,
            'target_group' => 'Household',
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        PriceComponent::create([
            'id' => 'pc-general-' . $id,
            'electricity_contract_id' => $id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => $energyPrice,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-' . $id,
            'electricity_contract_id' => $id,
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 2.95,
            'payment_unit' => 'EUR/month',
        ]);

        return $contract;
    }
}
