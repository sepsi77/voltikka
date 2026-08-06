<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractPriceAnnualCost;
use App\Models\ContractPriceSnapshot;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\ContractStatistics\AsOfAnnualCostCalculator;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\Support\CanonicalPricingFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentAsOfAnnualCostParityTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-06-01';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::DATE.' 09:00:00 Europe/Helsinki');
        Company::create(['name' => 'Parity Energy Oy', 'name_slug' => 'parity-energy-oy']);
        config()->set('canonical_pricing.enabled', true);
        config()->set('canonical_pricing.reset_forward_shift.enabled', true);
        $this->app->forgetScopedInstances();
        $this->app->instance(MarketReferenceCurveProvider::class, new CurrentAsOfPriorDateCurve);
        $this->rollingSpot();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_current_as_of_totals_reuse_all_current_canonical_outcomes_exactly(): void
    {
        $fixed = ElectricityContract::factory()->forCompany('Parity Energy Oy')->fixedTerm()->create([
            'id' => 'parity-fixed',
        ]);
        $shortAttributes = CanonicalPricingFixture::fixedAttributes();
        $shortAttributes['canonical_pricing']['phases'][0]['ends'] = ['kind' => 'after_months', 'value' => '6'];
        $shortAttributes['canonical_calculation']['status'] = 'incomplete';
        $short = ElectricityContract::factory()->forCompany('Parity Energy Oy')->fixedTerm()->create([
            'id' => 'parity-short',
            'fixed_time_range' => 'Fixed6',
        ]);
        $spot = ElectricityContract::factory()->forCompany('Parity Energy Oy')->spot()->create([
            'id' => 'parity-spot',
        ]);
        $supplier = ElectricityContract::factory()->forCompany('Parity Energy Oy')->create([
            'id' => 'parity-supplier',
        ]);
        $reset = ElectricityContract::factory()->forCompany('Parity Energy Oy')->reset()->create([
            'id' => 'parity-reset',
        ]);
        $package = ElectricityContract::factory()->forCompany('Parity Energy Oy')->create([
            'id' => 'parity-package',
        ]);
        $hybrid = ElectricityContract::factory()->forCompany('Parity Energy Oy')->hybrid()->create([
            'id' => 'parity-hybrid',
        ]);

        $this->sourceEvidence($fixed, CanonicalPricingFixture::fixedAttributes());
        $this->sourceEvidence($short, $shortAttributes);
        $this->sourceEvidence($spot, CanonicalPricingFixture::spotAttributes());
        $this->sourceEvidence($supplier, CanonicalPricingFixture::fixedAttributes());
        $this->sourceEvidence($reset, CanonicalPricingFixture::resetAttributes());
        $this->sourceEvidence($package, CanonicalPricingFixture::packageAttributes());
        $this->sourceEvidence($hybrid, CanonicalPricingFixture::hybridAttributes());

        $shapeEnd = CarbonImmutable::parse(self::DATE, 'Europe/Helsinki');
        $shape = new \App\Services\CanonicalPricing\DTO\SpotAssumptions(
            6.0,
            4.0,
            5.0,
            $shapeEnd->subDays(364),
            $shapeEnd,
        );
        $contracts = collect([
            $fixed->fresh(),
            $short->fresh(),
            $spot->fresh(),
            $supplier->fresh(),
            $reset->fresh(),
            $package->fresh(),
            $hybrid->fresh(),
        ]);
        $currentOutcomes = app(\App\Services\CanonicalPricing\CanonicalContractPricingService::class)
            ->outcomesForContractsAtConsumptions($contracts, [2000, 5000, 18000], $shape, $shapeEnd);
        $this->assertSame(
            'current_source_observation',
            $currentOutcomes[$supplier->id][5000]->supplierAdjustedEstimate['price_episode_evidence_basis'],
            'The supplier anchor must come only from the safe current source pointer.',
        );
        $this->mock(\App\Services\CanonicalPricing\CanonicalContractPricingService::class, function ($mock) use ($currentOutcomes): void {
            $mock->shouldReceive('outcomesForContractsAtConsumptions')->once()->andReturn($currentOutcomes);
        });

        app(ContractPriceStatisticsService::class)->calculateForDate(
            self::DATE,
            $contracts->pluck('id'),
            overwrite: false,
            useCanonical: true,
        );

        foreach ($contracts as $contract) {
            $snapshot = ContractPriceSnapshot::query()
                ->whereDate('snapshot_date', self::DATE)
                ->where('contract_id', $contract->id)
                ->sole();
            $annual = ContractPriceAnnualCost::query()
                ->whereDate('snapshot_date', self::DATE)
                ->where('contract_id', $contract->id)
                ->get()
                ->keyBy('consumption_kwh');

            $this->assertCount(3, $annual, $contract->id);
            foreach ([2000, 5000, 18000] as $consumption) {
                $this->assertSame(
                    $snapshot->getRawOriginal('annual_cost_'.$consumption.'_kwh'),
                    $annual[$consumption]->getRawOriginal('annual_cost'),
                    $contract->id.' at '.$consumption.' kWh',
                );
                $this->assertEqualsWithDelta(
                    (float) $currentOutcomes[$contract->id][$consumption]->totalCost,
                    (float) $annual[$consumption]->annual_cost,
                    0.00005,
                    $contract->id.' canonical outcome at '.$consumption.' kWh',
                );
                $this->assertNotNull($annual[$consumption]->source_interpretation_id);
            }
        }

        $historicalResults = collect(app(AsOfAnnualCostCalculator::class)->calculate(self::DATE))
            ->keyBy(fn ($result): string => $result->contractId.'|'.$result->consumptionKwh);
        foreach ([$fixed, $spot] as $equivalentContract) {
            foreach ([2000, 5000, 18000] as $consumption) {
                $currentKey = ContractPriceAnnualCost::query()
                    ->where('contract_id', $equivalentContract->id)
                    ->where('consumption_kwh', $consumption)
                    ->value('compatibility_key');
                $this->assertSame(
                    $currentKey,
                    $historicalResults[$equivalentContract->id.'|'.$consumption]->compatibilityKey,
                    $equivalentContract->id.' compatibility at '.$consumption.' kWh',
                );
            }
        }

        $this->assertSame('none', $this->annualMethod($fixed));
        $this->assertSame('term_price_annualized', $this->annualMethod($short));
        $this->assertSame('forward_curve_spot', $this->annualMethod($spot));
        $this->assertSame('supplier_adjusted_forward_curve_shift', $this->annualMethod($supplier));
        $this->assertSame('recurring_forward_curve_shift', $this->annualMethod($reset));
        $this->assertSame('none', $this->annualMethod($package));
        $this->assertSame('hybrid_base_only', $this->annualMethod($hybrid));
        $supplierAnnual = ContractPriceAnnualCost::query()
            ->where('contract_id', $supplier->id)
            ->where('consumption_kwh', 5000)
            ->sole();
        $this->assertSame(self::DATE, $supplierAnnual->price_episode_started_at?->toDateString());
        $this->assertSame('supplier_adjusted_forward_curve_shift', $supplierAnnual->estimate_basis);
        $this->assertNotNull($supplierAnnual->source_observation_id);
        $this->assertNotNull($supplierAnnual->source_snapshot_id);
        $this->assertNotNull($supplierAnnual->source_interpretation_id);
        $this->assertSame([], $supplierAnnual->provenance['source_evidence_ids']['price_component_ids']);

        /** @var CurrentAsOfPriorDateCurve $curve */
        $curve = $this->app->make(MarketReferenceCurveProvider::class);
        $this->assertNotEmpty($curve->asOfDates);
        $this->assertTrue(collect($curve->tradeDates)->every(
            fn (string $tradeDate): bool => $tradeDate < self::DATE,
        ));
    }

    private function annualMethod(ElectricityContract $contract): string
    {
        return (string) ContractPriceAnnualCost::query()
            ->where('contract_id', $contract->id)
            ->where('consumption_kwh', 5000)
            ->value('estimate_method');
    }

    /** @param array{canonical_pricing: array<string, mixed>, canonical_source_consistency: array<string, mixed>, canonical_calculation: array<string, mixed>} $attributes */
    private function sourceEvidence(ElectricityContract $contract, array $attributes): void
    {
        $source = ContractSourceSnapshot::create([
            'contract_id' => $contract->id,
            'source_fingerprint' => hash('sha256', 'current-parity-'.$contract->id),
            'source_payload' => ['id' => $contract->id],
            'first_observed_at' => self::DATE.' 00:00:00',
            'last_observed_at' => self::DATE.' 23:00:00',
        ]);
        $observation = ContractSourceObservation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $source->id,
            'first_observed_at' => self::DATE.' 00:00:00',
            'last_observed_at' => self::DATE.' 23:00:00',
        ]);
        $interpretation = ContractInterpretation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $source->id,
            'analysis_source_observation_id' => $observation->id,
            'analysis_fingerprint' => hash('sha256', 'current-parity-interpretation-'.$contract->id),
            'status' => 'published',
            'schema_version' => 'test',
            'prompt_version' => 'test',
            'validator_version' => 'test',
            'provider' => 'test',
            'model' => 'test',
            'output' => [
                'pricing' => $attributes['canonical_pricing'],
                'source_consistency' => $attributes['canonical_source_consistency'],
                'calculation' => $attributes['canonical_calculation'],
            ],
            'validation_errors' => [],
            'completed_at' => self::DATE.' 01:00:00',
        ]);
        $contract->update([
            ...$attributes,
            'current_source_observation_id' => $observation->id,
            'published_interpretation_id' => $interpretation->id,
        ]);
    }

    private function rollingSpot(): void
    {
        $end = CarbonImmutable::parse(self::DATE, 'Europe/Helsinki')->startOfDay();
        $start = $end->subDays(364);
        SpotPriceAverage::create([
            'region' => 'FI',
            'period_type' => SpotPriceAverage::PERIOD_ROLLING_365D,
            'period_start' => self::DATE,
            'period_end' => self::DATE,
            'avg_price_with_tax' => 5.0,
            'avg_price_without_tax' => 4.0,
            'day_avg_with_tax' => 6.0,
            'night_avg_with_tax' => 4.0,
            'hours_count' => (int) $start->utc()->diffInHours($end->addDay()->utc()),
        ]);
    }
}

class CurrentAsOfPriorDateCurve implements MarketReferenceCurveProvider
{
    /** @var list<string> */
    public array $asOfDates = [];

    /** @var list<string> */
    public array $tradeDates = [];

    public function tradeDate(CarbonImmutable $asOfDate): ?CarbonImmutable
    {
        $this->asOfDates[] = $asOfDate->toDateString();
        $tradeDate = $asOfDate->subDay();
        $this->tradeDates[] = $tradeDate->toDateString();

        return $tradeDate;
    }

    public function referencePrice(CarbonImmutable $asOfDate, CarbonImmutable $anchorMonth, array $kindPreference): ?array
    {
        $tradeDate = $asOfDate->subDay();
        $this->asOfDates[] = $asOfDate->toDateString();
        $this->tradeDates[] = $tradeDate->toDateString();

        return [
            'kind' => 'month',
            'price_cents_per_kwh' => 4.0,
            'trade_date' => $tradeDate->toDateString(),
        ];
    }

    public function forwardPriceForMonth(CarbonImmutable $asOfDate, CarbonImmutable $deliveryMonth): ?array
    {
        $this->asOfDates[] = $asOfDate->toDateString();

        return ['kind' => 'month', 'price_cents_per_kwh' => 6.0];
    }

    public function spotSeasonalIndex(): ?array
    {
        return null;
    }

    public function fixedTermMedianEnergyPrice(): ?float
    {
        return null;
    }
}
