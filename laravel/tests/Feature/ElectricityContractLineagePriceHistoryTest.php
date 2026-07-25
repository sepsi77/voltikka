<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ElectricityContractLineagePriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_lineage_price_history_collects_a_converging_graph_and_orders_all_rows(): void
    {
        Company::create(['name' => 'Lineage Energy']);
        $current = $this->contract('current');
        $oldA = $this->contract('old-a', $current->id);
        $oldB = $this->contract('old-b', $current->id);
        $oldest = $this->contract('oldest', $oldA->id);
        $unrelated = $this->contract('unrelated');

        $this->price('current-price', $current->id, '2026-07-25', 'Monthly', 4.90);
        $this->price('old-b-price', $oldB->id, '2026-07-24', 'General', 0.50);
        $this->price('old-a-price', $oldA->id, '2026-07-24', 'General', 0.60);
        $this->price('oldest-price', $oldest->id, '2026-06-01', 'General', 0.70);
        $this->price('unrelated-price', $unrelated->id, '2026-07-26', 'General', 9.99);

        $this->assertEqualsCanonicalizing(
            ['current', 'old-a', 'old-b', 'oldest'],
            $current->getReplacementLineageIds()->all(),
        );
        $this->assertSame(
            ['current-price', 'old-a-price', 'old-b-price', 'oldest-price'],
            $current->getLineagePriceComponents()->pluck('id')->all(),
        );
    }

    public function test_lineage_lookup_terminates_when_replacement_data_contains_a_cycle(): void
    {
        Company::create(['name' => 'Cycle Energy']);
        $first = $this->contract('first');
        $second = $this->contract('second', $first->id);
        $first->update(['replaced_by_contract_id' => $second->id]);

        $this->assertEqualsCanonicalizing(
            ['first', 'second'],
            $first->fresh()->getReplacementLineageIds()->all(),
        );
    }

    private function contract(string $id, ?string $replacedBy = null): ElectricityContract
    {
        return ElectricityContract::create([
            'id' => $id,
            'company_name' => str_contains($id, 'first') || str_contains($id, 'second') ? 'Cycle Energy' : 'Lineage Energy',
            'name' => $id,
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'Spot',
            'availability_is_national' => true,
            'replaced_by_contract_id' => $replacedBy,
        ]);
    }

    private function price(string $id, string $contractId, string $date, string $type, float $price): void
    {
        PriceComponent::create([
            'id' => $id,
            'electricity_contract_id' => $contractId,
            'price_date' => $date,
            'price_component_type' => $type,
            'price' => $price,
            'payment_unit' => $type === 'Monthly' ? 'EurPerMonth' : 'CentPerKiloWattHour',
        ]);
    }
}
