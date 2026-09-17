<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\ContractPriceHistory\ContractHistoryPresenter;
use App\Services\RetailPremium\RetailPremiumObservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    public function test_batch_lineages_keep_root_membership_separate_in_one_parameterized_query(): void
    {
        Company::create(['name' => 'Lineage Energy']);
        Company::create(['name' => 'Cycle Energy']);
        $current = $this->contract('current');
        $oldA = $this->contract('old-a', 'current');
        $this->contract('old-b', 'current');
        $this->contract('oldest', 'old-a');
        $this->contract('alone');
        $first = $this->contract('first');
        $this->contract('second', 'first');
        $first->update(['replaced_by_contract_id' => 'second']);
        $missing = "missing'root";
        $roots = ['current', 'old-a', 'alone', 'first', 'second', $missing, 'current'];

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $lineages = ElectricityContract::getReplacementLineageIdsByContractIds($roots);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('WITH RECURSIVE replacement_lineage(root_id, id)', $queries[0]['query']);
        $this->assertStringNotContainsString($missing, $queries[0]['query']);
        $this->assertSame(array_values(array_unique($roots)), $queries[0]['bindings']);
        $expected = [
            'current' => ['current', 'old-a', 'old-b', 'oldest'],
            'old-a' => ['old-a', 'oldest'],
            'alone' => ['alone'],
            'first' => ['first', 'second'],
            'second' => ['first', 'second'],
            $missing => [],
        ];
        $this->assertSame(array_keys($expected), array_keys($lineages));
        foreach ($expected as $root => $ids) {
            $this->assertInstanceOf(Collection::class, $lineages[$root]);
            $this->assertEqualsCanonicalizing($ids, $lineages[$root]->all());
            foreach ($lineages[$root] as $id) {
                $this->assertIsString($id);
            }
        }
        $this->assertSame([
            'key' => hash('sha256', 'old-b|oldest'),
            'contract_ids' => ['current', 'old-a', 'old-b', 'oldest'],
            'root_ids' => ['old-b', 'oldest'],
        ], app(RetailPremiumObservationService::class)->getLineageIdentity($current));
        $this->assertSame([
            'key' => hash('sha256', 'first|second'),
            'contract_ids' => ['first', 'second'],
            'root_ids' => ['first', 'second'],
        ], app(RetailPremiumObservationService::class)->getLineageIdentity($first));
        $this->assertEqualsCanonicalizing($expected['current'], $current->getReplacementLineageIds()->all());
        $this->assertEqualsCanonicalizing(['old-a', 'old-b', 'oldest'], $current->getReplacementChainBackward()->pluck('id')->all());
        $this->assertSame(['second'], $first->getReplacementChainBackward()->pluck('id')->all());
        $this->assertSame('current', $oldA->resolveLatestReplacement()->id);
        $this->assertSame(['current'], $oldA->getReplacementChainForward()->pluck('id')->all());
        $this->assertSame([], (new ElectricityContract(['id' => $missing]))->getReplacementLineageIds()->all());

        $this->price('first-price', 'first', '2026-07-25', 'General', 0.5);
        $this->price('second-price', 'second', '2026-07-24', 'General', 0.6);
        $history = app(ContractHistoryPresenter::class)->present($first);
        $this->assertSame(['first', 'second'], array_column($history['contractHistory'], 'id'));
        $this->assertSame(['first', 'second'], array_column($history['priceHistory']['General'], 'contract_id'));
    }

    public function test_empty_batch_does_not_query_the_database(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->assertSame([], ElectricityContract::getReplacementLineageIdsByContractIds([]));
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
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

    public function test_batch_lineage_identities_preserve_retail_hashes_in_two_reads(): void
    {
        Company::create(['name' => 'Lineage Energy']);
        $tip = $this->contract('tip');
        $this->contract('branch-a', 'tip');
        $this->contract('branch-b', 'tip');
        $this->contract('0');
        $this->contract('zero-old', '0');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $identities = ElectricityContract::getLineageIdentitiesByContractIds(['tip', 'branch-a', 'missing', '0']);
        $this->assertCount(2, $queries);
        $this->assertSame(hash('sha256', 'branch-a|branch-b'), $identities['tip']['key']);
        $this->assertSame(['branch-a', 'branch-b'], $identities['tip']['root_ids']);
        $this->assertSame(app(RetailPremiumObservationService::class)->getLineageIdentity($tip), $identities['tip']);
        $this->assertSame([], $identities['missing']['contract_ids']);
        $this->assertSame(hash('sha256', 'zero-old'), $identities['0']['key']);
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
