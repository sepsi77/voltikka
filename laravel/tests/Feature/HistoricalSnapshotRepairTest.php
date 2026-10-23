<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractPriceAnnualCost;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractPriceSnapshot;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\ContractInterpretation\ContractInterpretationValidator;
use App\Services\ContractStatistics\HistoricalSnapshotRepair;
use App\Services\ContractStatistics\SellerSetEnergyPriceIndexService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\Support\CanonicalPricingFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricalSnapshotRepairTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-06-01';

    protected function setUp(): void
    {
        parent::setUp();
        // Fixture outputs test the repair flow; exact-source validation has its own tests.
        $this->mock(ContractInterpretationValidator::class)->shouldReceive('validate')->andReturn([]);
        Carbon::setTestNow('2026-06-02 09:00:00 Europe/Helsinki');
        config()->set('contract_statistics.annual_cost.active_method_version', 'annual_cost_as_of_v3');
        Company::create(['name' => 'Repair Energy Oy', 'name_slug' => 'repair-energy-oy']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_missing_contract_gets_a_dated_snapshot_and_the_date_is_rebuilt(): void
    {
        $missing = $this->contract('missing');
        $this->sourceEvidence($missing, 'Household', 'published');
        $failed = $this->contract('failed');
        $this->sourceEvidence($failed, 'Household', 'failed');
        $business = $this->contract('business');
        $this->sourceEvidence($business, 'Company', 'published');
        // The current row has drifted since the date; the dated payload must win.
        $missing->update(['name' => 'Renamed later', 'company_name' => 'Repair Energy Oy']);

        $repair = app(HistoricalSnapshotRepair::class);
        $date = CarbonImmutable::parse(self::DATE, 'Europe/Helsinki');
        $plan = $repair->plan($date);

        $this->assertSame(['missing'], array_keys($plan['added']));
        $this->assertSame(['failed' => 'no_dated_interpretation'], $plan['skipped']);
        $added = $plan['added']['missing'];
        $this->assertSame('Dated name missing', $added['contract_name']);
        $this->assertSame('fixed_term_12', $added['segment_key']);
        $this->assertSame('canonical_calculation', $added['pricing_basis']);
        $this->assertEqualsWithDelta(481.3, $added['annual_cost_5000_kwh'], 0.01);
        $this->assertEqualsWithDelta(8.45, $added['energy_price_cents_per_kwh'], 0.0001);

        $dryRun = $repair->apply($date, $plan['added'], dryRun: true);
        $this->assertSame(3, $dryRun['annual_rows']);
        $this->assertSame(0, ContractPriceSnapshot::query()->count());
        $this->assertSame(0, ContractPriceAnnualCost::query()->count());

        $applied = $repair->apply($date, $plan['added'], dryRun: false);
        $this->assertSame(1, $applied['snapshots']);
        // One supplier is below the index family minimum, so the index writes no rows.
        $this->assertSame([], $applied['index_drift']);
        $this->assertSame(0, $applied['index_rows']);
        $this->assertSame(['missing'], ContractPriceSnapshot::query()->pluck('contract_id')->all());
        $annual = ContractPriceAnnualCost::query()->where('method_version', 'annual_cost_as_of_v3')->get();
        $this->assertSame(['missing'], $annual->pluck('contract_id')->unique()->values()->all());
        $this->assertEqualsWithDelta(481.3, $annual->firstWhere('consumption_kwh', 5000)->annual_cost, 0.01);
        $this->assertTrue(ContractPriceDailyStatistic::query()
            ->where('method_version', ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION)
            ->where('segment_key', 'fixed_term_12')->where('metric_key', 'energy_price')->exists());

        // A rebuild of the now-stored snapshot from dated evidence matches it exactly.
        $compared = $repair->plan($date, compareExisting: true);
        $this->assertSame([], $compared['added']);
        $this->assertSame(1, $compared['compared']['matched']);
        $this->assertSame([], $compared['compared']['differences']);
    }

    public function test_seller_set_index_is_not_rebuilt_when_a_contract_row_has_drifted(): void
    {
        $missing = $this->contract('missing');
        $this->sourceEvidence($missing, 'Household', 'published');
        $repair = app(HistoricalSnapshotRepair::class);
        $date = CarbonImmutable::parse(self::DATE, 'Europe/Helsinki');
        $plan = $repair->plan($date);
        // The index writer would read today's contract type, not the dated one.
        $missing->update(['contract_type' => 'OpenEnded', 'fixed_time_range' => null]);
        ContractPriceDailyStatistic::query()->create([
            'stat_date' => self::DATE, 'segment_key' => 'seller_set_overall', 'metric_key' => SellerSetEnergyPriceIndexService::METRIC_KEY,
            'pricing_basis' => 'canonical_calculation', 'avg_value' => 12.0, 'contract_count' => 3,
        ]);

        $applied = $repair->apply($date, $plan['added'], dryRun: false);

        $this->assertSame(['missing'], $applied['index_drift']);
        $this->assertNull($applied['index_rows']);
        $this->assertSame(1, ContractPriceSnapshot::query()->count());
        $this->assertEqualsWithDelta(12.0, ContractPriceDailyStatistic::query()
            ->where('metric_key', SellerSetEnergyPriceIndexService::METRIC_KEY)->sole()->avg_value, 0.0001);
    }

    public function test_repair_rejects_today(): void
    {
        $this->expectExceptionMessage('only dates before today');
        app(HistoricalSnapshotRepair::class)->apply(CarbonImmutable::parse('2026-06-02', 'Europe/Helsinki'), [], dryRun: true);
    }

    private function contract(string $id): ElectricityContract
    {
        return ElectricityContract::factory()->forCompany('Repair Energy Oy')->fixedTerm()->create(['id' => $id, 'name' => $id]);
    }

    private function sourceEvidence(ElectricityContract $contract, string $targetGroup, string $status): void
    {
        $attributes = CanonicalPricingFixture::fixedAttributes();
        $source = ContractSourceSnapshot::create([
            'contract_id' => $contract->id,
            'source_fingerprint' => hash('sha256', 'repair-'.$contract->id),
            'source_payload' => [
                'Id' => 'api-'.$contract->id,
                'Name' => 'Dated name '.$contract->id,
                'Company' => ['Name' => 'Repair Energy Oy'],
                'Details' => [
                    'PricingModel' => 'FixedPrice',
                    'ContractType' => 'FixedTerm',
                    'FixedTimeRange' => 'Fixed12',
                    'Metering' => 'General',
                    'TargetGroup' => $targetGroup,
                    'ConsumptionLimitation' => ['MinXKWhPerY' => null, 'MaxXKWhPerY' => null],
                ],
            ],
            'first_observed_at' => self::DATE.' 03:00:00',
            'last_observed_at' => self::DATE.' 03:00:00',
        ]);
        $observation = ContractSourceObservation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $source->id,
            'first_observed_at' => self::DATE.' 03:00:00',
            'last_observed_at' => self::DATE.' 03:00:00',
        ]);
        ContractInterpretation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $source->id,
            'analysis_source_observation_id' => $observation->id,
            'analysis_fingerprint' => hash('sha256', 'repair-interpretation-'.$contract->id),
            'status' => $status,
            'schema_version' => 'schema-v4',
            'prompt_version' => 'prompt-v19',
            'validator_version' => 'validator-v17',
            'provider' => 'test',
            'model' => 'test',
            'output' => $status === 'published' ? [
                'pricing' => $attributes['canonical_pricing'],
                'source_consistency' => $attributes['canonical_source_consistency'],
                'calculation' => $attributes['canonical_calculation'],
            ] : null,
            'validation_errors' => [],
            'completed_at' => self::DATE.' 04:00:00',
        ]);
        $contract->update(['current_source_observation_id' => $observation->id]);
    }
}
