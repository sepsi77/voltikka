<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\ContractReplacement\ContractReplacementLinker;
use App\Services\ContractReplacement\ContractReplacementMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/** `replaced_by_linked_on`: the Helsinki date from which historical statistics trust a link. */
class DatedReplacementLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Company::create(['name' => 'Link Energy Oy', 'name_slug' => 'link-energy-oy']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_linker_sets_the_helsinki_date_of_a_new_link_and_never_touches_an_existing_one(): void
    {
        $old = $this->contract('old');
        $new = $this->contract('new');
        $kept = $this->contract('kept', 'new', '2026-03-01');
        // 22:30 UTC on 16 October is already 17 October in Helsinki.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16 22:30:00', 'UTC'));
        $matcher = Mockery::mock(ContractReplacementMatcher::class);
        $matcher->shouldReceive('findBestReplacement')->andReturnUsing(
            fn (ElectricityContract $inactive): ?array => $inactive->id === 'old' ? ['candidate' => $new, 'confidence' => 'high'] : null,
        );
        (new ContractReplacementLinker($matcher))->linkHighConfidenceMatches();

        $this->assertSame('new', $old->fresh()->replaced_by_contract_id);
        $this->assertSame('2026-10-17', substr((string) $old->fresh()->replaced_by_linked_on, 0, 10));
        $this->assertSame('2026-03-01', substr((string) $kept->fresh()->replaced_by_linked_on, 0, 10));
    }

    public function test_backfill_uses_first_successor_evidence_or_the_day_after_the_predecessor_was_last_seen(): void
    {
        // Successor first seen in components (2026-05-03) before its first source observation;
        // it can be linked only from the next import, after its first validation made it active.
        $this->contract('succ-a');
        $this->contract('pred-a', 'succ-a');
        $this->priceComponent('pred-a', '2026-05-02');
        $this->priceComponent('succ-a', '2026-05-03');
        $this->observation('succ-a', '2026-07-23 08:00:00', '2026-07-30 08:00:00');
        // Both on sale together: the link can only exist after the predecessor disappeared.
        // 2026-08-10 21:30 UTC is 2026-08-11 in Helsinki: the first import without it is 2026-08-12.
        $this->contract('succ-b');
        $this->contract('pred-b', 'succ-b');
        $this->observation('succ-b', '2026-08-01 08:00:00', '2026-08-20 08:00:00');
        $this->observation('pred-b', '2026-07-23 08:00:00', '2026-08-10 21:30:00');
        // A successor without any dated evidence keeps a null date: not trusted historically.
        $this->contract('succ-c');
        $this->contract('pred-c', 'succ-c');
        // An existing date is never changed.
        $this->contract('pred-d', 'succ-a', '2026-01-30');

        DB::table('electricity_contracts')->whereIn('id', ['pred-a', 'pred-b', 'pred-c'])->update(['replaced_by_linked_on' => null]);
        (require database_path('migrations/2026_10_16_000001_add_replaced_by_linked_on_to_electricity_contracts.php'))->up();

        $dates = DB::table('electricity_contracts')->whereNotNull('replaced_by_contract_id')->pluck('replaced_by_linked_on', 'id')
            ->map(fn ($date): ?string => $date === null ? null : substr((string) $date, 0, 10))->all();
        $this->assertSame(['pred-a' => '2026-05-04', 'pred-b' => '2026-08-12', 'pred-c' => null, 'pred-d' => '2026-01-30'], collect($dates)->sortKeys()->all());
    }

    public function test_lineage_as_of_date_counts_only_links_dated_on_or_before_it(): void
    {
        $this->contract('current');
        $this->contract('dated', 'current', '2026-06-02');
        $this->contract('undated', 'current');
        $this->contract('older', 'dated', '2026-05-01');

        $this->assertEqualsCanonicalizing(['current', 'dated', 'undated', 'older'],
            ElectricityContract::getReplacementLineageIdsByContractIds(['current'])['current']->all());
        $this->assertSame(['current'],
            ElectricityContract::getReplacementLineageIdsByContractIds(['current'], CarbonImmutable::parse('2026-06-01'))['current']->all());
        $this->assertEqualsCanonicalizing(['current', 'dated', 'older'],
            ElectricityContract::getReplacementLineageIdsByContractIds(['current'], CarbonImmutable::parse('2026-06-02'))['current']->all());

        $identity = ElectricityContract::getLineageIdentitiesByContractIds(['current'], CarbonImmutable::parse('2026-06-02'))['current'];
        $this->assertSame(['older'], $identity['root_ids']);
        $this->assertSame(['current'], ElectricityContract::getLineageIdentitiesByContractIds(['current'], CarbonImmutable::parse('2026-06-01'))['current']['root_ids']);
    }

    private function contract(string $id, ?string $replacedBy = null, ?string $linkedOn = null): ElectricityContract
    {
        $contract = ElectricityContract::factory()->forCompany('Link Energy Oy')->create(['id' => $id, 'name' => $id]);
        if ($replacedBy !== null) {
            DB::table('electricity_contracts')->where('id', $id)->update(['replaced_by_contract_id' => $replacedBy, 'replaced_by_linked_on' => $linkedOn]);
        }

        return $contract;
    }

    private function priceComponent(string $id, string $date): void
    {
        DB::table('price_components')->insert([
            'id' => $id.'-'.$date, 'electricity_contract_id' => $id, 'price_date' => $date,
            'price_component_type' => 'General', 'price' => 7, 'payment_unit' => 'CentPerKiloWattHour', 'has_discount' => false,
        ]);
    }

    private function observation(string $id, string $first, string $last): void
    {
        $source = ContractSourceSnapshot::create([
            'contract_id' => $id, 'source_fingerprint' => hash('sha256', $id.$first),
            'source_payload' => ['Details' => []], 'first_observed_at' => $first, 'last_observed_at' => $last,
        ]);
        ContractSourceObservation::create([
            'contract_id' => $id, 'source_snapshot_id' => $source->id, 'first_observed_at' => $first, 'last_observed_at' => $last,
        ]);
    }
}
