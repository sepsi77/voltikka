<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ElectricityContractQueryOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_active_uses_loaded_active_contract_relation(): void
    {
        $this->createContract('active-test');
        ActiveContract::create(['id' => 'active-test']);

        $contract = ElectricityContract::with('activeContract')->findOrFail('active-test');

        DB::enableQueryLog();

        $this->assertTrue($contract->isActive());

        $activeContractQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains($query, 'from "active_contracts"'))
            ->count();

        $this->assertSame(0, $activeContractQueries);
    }

    public function test_discount_helpers_use_loaded_price_components_relation(): void
    {
        $this->createContract('discount-test');

        PriceComponent::create([
            'id' => 'pc-discount-test-general',
            'electricity_contract_id' => 'discount-test',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 7.0,
            'payment_unit' => 'c/kWh',
            'has_discount' => true,
            'discount_value' => 1.0,
            'discount_is_percentage' => false,
        ]);

        $contract = ElectricityContract::with('priceComponents')->findOrFail('discount-test');

        DB::enableQueryLog();

        $this->assertTrue($contract->hasActiveDiscounts());
        $this->assertSame(1.0, $contract->getActiveDiscountInfo()['value']);

        $priceComponentQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains($query, 'from "price_components"'))
            ->count();

        $this->assertSame(0, $priceComponentQueries);
    }

    private function createContract(string $id): void
    {
        Company::firstOrCreate([
            'name' => 'Query Test Energia Oy',
        ], [
            'name_slug' => 'query-test-energia-oy',
        ]);

        ElectricityContract::create([
            'id' => $id,
            'company_name' => 'Query Test Energia Oy',
            'name' => "Sopimus {$id}",
            'name_slug' => $id,
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'FixedPrice',
            'target_group' => 'Household',
            'availability_is_national' => true,
        ]);
    }
}
