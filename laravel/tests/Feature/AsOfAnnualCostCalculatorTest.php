<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\ContractStatistics\AsOfAnnualCostCalculator;
use App\Services\ContractStatistics\AsOfAnnualCostEvidenceResolver;
use App\Services\ContractStatistics\Enums\AnnualCostCalculationBasis;
use Carbon\CarbonImmutable;
use Database\Factories\Support\CanonicalPricingFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AsOfAnnualCostCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-06-01';

    protected function setUp(): void
    {
        parent::setUp();
        Company::create(['name' => 'As Of Energy Oy', 'name_slug' => 'as-of-energy-oy']);
    }

    public function test_historical_universe_uses_exact_date_evidence_not_current_active_state(): void
    {
        $historical = $this->contract('historical', active: false);
        $this->snapshot($historical);
        $this->priceComponent($historical, 'General', 8.0);

        $currentOnly = $this->contract('current-only', active: true);
        $this->priceComponent($currentOnly, 'General', 6.0, '2026-06-02');

        $results = app(AsOfAnnualCostCalculator::class)->calculate(self::DATE);

        $this->assertSame(['historical'], collect($results)->pluck('contractId')->unique()->values()->all());
        $this->assertCount(3, $results);
        $this->assertTrue(collect($results)->every->isAvailable());
    }

    public function test_component_only_identity_returns_three_typed_historical_identity_exclusions(): void
    {
        $contract = $this->contract('component-only', active: true);
        $this->priceComponent($contract, 'General', 8.0);

        $results = app(AsOfAnnualCostCalculator::class)->calculate(self::DATE);

        $this->assertCount(3, $results);
        $this->assertSame([2000, 5000, 18000], collect($results)->pluck('consumptionKwh')->sort()->values()->all());
        foreach ($results as $result) {
            $this->assertSame($contract->id, $result->contractId);
            $this->assertSame('unclassified', $result->segmentKey);
            $this->assertNull($result->totalCost);
            $this->assertSame('missing_historical_snapshot_identity', $result->unavailableReason);
            $this->assertContains('missing_historical_snapshot_identity', $result->provenanceFlags);
            $this->assertNull($result->sourceEvidenceIds['price_snapshot_id']);
        }
    }

    public function test_snapshot_only_canonical_evidence_remains_in_the_union_and_is_calculated(): void
    {
        $contract = $this->contract('snapshot-only');
        $this->snapshot($contract);
        $this->strictInterpretation(
            $contract,
            CanonicalPricingFixture::fixedAttributes(),
            '2026-05-31 12:00:00',
            '2026-06-01 12:00:00',
        );

        $results = app(AsOfAnnualCostCalculator::class)->calculate(self::DATE);

        $this->assertCount(3, $results);
        $this->assertTrue(collect($results)->every->isAvailable());
        $this->assertTrue(collect($results)->every(
            fn ($result): bool => $result->calculationBasis === AnnualCostCalculationBasis::CanonicalOutcome,
        ));
    }

    public function test_later_source_observation_and_interpretation_are_ignored(): void
    {
        $contract = $this->contract('chronology');
        $this->snapshot($contract);
        $this->priceComponent($contract, 'General', 11.0);
        $old = $this->strictInterpretation($contract, CanonicalPricingFixture::fixedAttributes(), '2026-05-31 12:00:00', '2026-06-01 12:00:00');
        $this->strictInterpretation($contract, $this->fixedAttributes(2.0), '2026-06-02 12:00:00', '2026-06-03 12:00:00');

        $evidence = app(AsOfAnnualCostEvidenceResolver::class)->resolveDate(self::DATE)[$contract->id];
        $result = $this->annualResult($contract->id, 5000);

        $this->assertSame($old->id, $evidence->sourceEvidenceIds['interpretation_id']);
        $this->assertSame(AnnualCostCalculationBasis::CanonicalOutcome, $result->calculationBasis);
        $this->assertEqualsWithDelta(481.3, $result->totalCost, 0.1);
    }

    public function test_ambiguous_covering_source_snapshots_fall_back_to_relational_components(): void
    {
        $contract = $this->contract('ambiguous');
        $this->snapshot($contract);
        $this->priceComponent($contract, 'General', 7.0);
        $this->strictInterpretation($contract, $this->fixedAttributes(2.0), '2026-05-30 00:00:00', '2026-06-01 23:00:00');
        $this->strictInterpretation($contract, $this->fixedAttributes(3.0), '2026-05-31 00:00:00', '2026-06-01 22:00:00');

        $result = $this->annualResult($contract->id, 5000);

        $this->assertSame(AnnualCostCalculationBasis::ObservedRelationalComponents, $result->calculationBasis);
        $this->assertContains('canonical_omitted_ambiguous_covering_source_snapshots', $result->provenanceFlags);
        $this->assertEqualsWithDelta(350.0, $result->totalCost, 0.1);
    }

    public function test_relational_spot_uses_rolling_fallback_before_a_curve_and_forward_when_complete(): void
    {
        $contract = $this->contract('spot', pricingModel: 'Spot');
        $this->snapshot($contract, pricingModel: 'Spot', segment: 'spot');
        $this->priceComponent($contract, 'General', 0.5);
        $this->rollingSpot();

        $fallbackCurve = new FakeAsOfCurve(false);
        $this->app->instance(MarketReferenceCurveProvider::class, $fallbackCurve);
        $fallback = $this->annualResult($contract->id, 5000);

        $this->assertSame('rolling_365_fallback', $fallback->estimateBasis);
        $this->assertSame('rolling_365_spot', $fallback->estimateMethod);

        $this->app->forgetInstance(AsOfAnnualCostCalculator::class);
        $this->app->forgetScopedInstances();
        $forwardCurve = new FakeAsOfCurve(true);
        $this->app->instance(MarketReferenceCurveProvider::class, $forwardCurve);
        $forward = $this->annualResult($contract->id, 5000);

        $this->assertSame('forward_curve', $forward->estimateBasis);
        $this->assertSame('forward_curve_spot', $forward->estimateMethod);
        $this->assertNotSame($fallback->compatibilityKey, $forward->compatibilityKey);
        $this->assertGreaterThan(0, $forwardCurve->forwardCalls);
    }

    public function test_historical_spot_result_carries_partial_stored_coverage_provenance(): void
    {
        $contract = $this->contract('partial-spot', pricingModel: 'Spot');
        $this->snapshot($contract, pricingModel: 'Spot', segment: 'spot');
        $this->priceComponent($contract, 'General', 0.5);
        $this->rollingSpot(8702);
        $this->app->instance(MarketReferenceCurveProvider::class, new FakeAsOfCurve(true));

        $result = $this->annualResult($contract->id, 5000);

        $this->assertTrue($result->isAvailable());
        $this->assertContains('spot_assumptions_partial_above_threshold', $result->provenanceFlags);
        $this->assertContains('spot_assumptions_expected_hours_8760', $result->provenanceFlags);
        $this->assertContains('spot_assumptions_actual_hours_8702', $result->provenanceFlags);
        $this->assertContains('spot_assumptions_coverage_ratio_0.993379', $result->provenanceFlags);
        $this->assertSame('forward_curve_spot', $result->estimateMethod);
        $this->assertSame('forward_curve', $result->estimateBasis);
    }

    public function test_missing_spot_evidence_is_unavailable_and_never_zero(): void
    {
        $contract = $this->contract('missing-spot', pricingModel: 'Spot');
        $this->snapshot($contract, pricingModel: 'Spot', segment: 'spot');
        $this->priceComponent($contract, 'General', 0.5);

        $result = $this->annualResult($contract->id, 5000);

        $this->assertNull($result->totalCost);
        $this->assertSame('spot_assumptions_unavailable', $result->unavailableReason);
    }

    public function test_relational_open_ended_fixed_price_stays_flat_without_inferred_adjustment(): void
    {
        $contract = $this->contract('relational-flat');
        $this->snapshot($contract);
        $this->priceComponent($contract, 'General', 8.0);

        $result = $this->annualResult($contract->id, 5000);

        $this->assertEqualsWithDelta(400.0, $result->totalCost, 0.1);
        $this->assertSame('relational_open_ended_conservative_hold_flat', $result->estimateBasis);
        $this->assertContains('relational_open_ended_no_proven_historical_adjustment_mechanism', $result->provenanceFlags);
    }

    public function test_strict_supplier_interpretation_uses_dated_episode_and_supplier_adjusted_estimator(): void
    {
        $contract = $this->contract('supplier');
        $this->snapshot($contract, energy: 8.45, fee: 4.9);
        $this->snapshot($contract, date: '2026-05-31', energy: 7.45, fee: 4.9);
        $this->priceComponent($contract, 'General', 8.45);
        $this->priceComponent($contract, 'Monthly', 4.9, paymentUnit: 'EurPerMonth');
        $this->strictInterpretation($contract, CanonicalPricingFixture::fixedAttributes(), '2026-05-31 00:00:00', '2026-06-01 23:00:00');
        $this->app->instance(MarketReferenceCurveProvider::class, new FakeAsOfCurve(true));

        $result = $this->annualResult($contract->id, 5000);

        $this->assertSame('supplier_adjusted_forward_curve_shift', $result->estimateMethod);
        $this->assertSame('supplier_adjusted_forward_curve_shift', $result->estimateBasis);
        $this->assertSame(self::DATE, $result->priceEpisodeStartedAt?->toDateString());
    }

    public function test_supplier_seasonal_fallback_is_replaced_by_exact_date_hold_flat(): void
    {
        $contract = $this->contract('supplier-seasonal');
        $this->snapshot($contract, energy: 8.45, fee: 4.9);
        $this->snapshot($contract, date: '2026-05-31', energy: 7.45, fee: 4.9);
        $this->priceComponent($contract, 'General', 8.45);
        $this->priceComponent($contract, 'Monthly', 4.9, paymentUnit: 'EurPerMonth');
        $this->strictInterpretation($contract, CanonicalPricingFixture::fixedAttributes(), '2026-05-31 00:00:00', '2026-06-01 23:00:00');

        $this->app->instance(MarketReferenceCurveProvider::class, new FakeAsOfCurve(false, array_fill(1, 12, 1.0)));
        $first = $this->annualResult($contract->id, 5000);

        $this->app->forgetInstance(AsOfAnnualCostCalculator::class);
        $this->app->forgetScopedInstances();
        $futureChangedIndex = array_fill(1, 12, 1.0);
        $futureChangedIndex[12] = 5.0;
        $this->app->instance(MarketReferenceCurveProvider::class, new FakeAsOfCurve(false, $futureChangedIndex));
        $second = $this->annualResult($contract->id, 5000);

        $this->assertSame('hold_current_supplier_price', $first->estimateMethod);
        $this->assertSame('supplier_adjusted_exact_date_relational_hold_flat', $first->estimateBasis);
        $this->assertSame(AnnualCostCalculationBasis::ObservedRelationalComponents, $first->calculationBasis);
        $this->assertContains('supplier_seasonal_index_rejected_not_date_bounded', $first->provenanceFlags);
        $this->assertSame($first->totalCost, $second->totalCost);
        $this->assertSame($first->compatibilityKey, $second->compatibilityKey);
    }

    public function test_recurring_canonical_data_uses_exact_date_relational_hold_flat(): void
    {
        $contract = $this->contract('reset');
        $this->snapshot($contract, energy: 7.25, fee: 4.9, segment: 'market_reset');
        $this->priceComponent($contract, 'General', 7.25);
        $this->priceComponent($contract, 'Monthly', 4.9, paymentUnit: 'EurPerMonth');
        $attributes = CanonicalPricingFixture::attributes(
            phases: [CanonicalPricingFixture::phase(
                'Kesäkuu',
                PhaseKind::RecurringPeriod,
                CanonicalPricingFixture::boundary(BoundaryKind::PeriodBoundary),
                CanonicalPricingFixture::boundary(BoundaryKind::PeriodBoundary),
                [CanonicalPricingFixture::component(ComponentType::EnergyGeneral, 7.25, ComponentUnit::CentsPerKwh)],
            )],
            calculationStatus: CalculationStatus::EstimateRequired,
            recurringSchedule: CanonicalPricingFixture::recurringSchedule('monthly', self::DATE, '2026-06-30', false),
            issueCodes: ['recurring_reset_requires_estimate'],
        );
        $this->strictInterpretation($contract, $attributes, '2026-05-31 00:00:00', '2026-06-01 23:00:00');
        $curve = new FakeAsOfCurve(true);
        $this->app->instance(MarketReferenceCurveProvider::class, $curve);

        $result = $this->annualResult($contract->id, 5000);

        $this->assertSame('hold_current_recurring_price', $result->estimateMethod);
        $this->assertSame('exact_date_recurring_price_held_flat', $result->estimateBasis);
        $this->assertSame(AnnualCostCalculationBasis::ObservedRelationalComponents, $result->calculationBasis);
        $this->assertSame(0, $curve->forwardCalls);
    }

    public function test_legacy_annual_value_is_only_a_per_consumption_mask(): void
    {
        $contract = $this->contract('mask');
        $this->snapshot($contract, masks: [2000 => 9999.0, 5000 => null, 18000 => 12345.0]);
        $this->priceComponent($contract, 'General', 8.0);

        $results = collect(app(AsOfAnnualCostCalculator::class)->calculate(self::DATE))->keyBy('consumptionKwh');

        $this->assertEqualsWithDelta(160.0, $results[2000]->totalCost, 0.1);
        $this->assertNull($results[5000]->totalCost);
        $this->assertSame('legacy_annual_cost_mask_unavailable', $results[5000]->unavailableReason);
        $this->assertEqualsWithDelta(1440.0, $results[18000]->totalCost, 0.1);
    }

    public function test_batch_query_count_is_bounded_by_evidence_tables_not_contract_count(): void
    {
        foreach (range(1, 12) as $index) {
            $contract = $this->contract('batch-'.$index);
            $this->snapshot($contract);
            $this->priceComponent($contract, 'General', 8.0);
        }

        DB::enableQueryLog();
        $results = app(AsOfAnnualCostCalculator::class)->calculate(self::DATE);
        $queries = DB::getQueryLog();

        $this->assertCount(36, $results);
        $this->assertLessThanOrEqual(7, count($queries), collect($queries)->pluck('query')->implode("\n"));
    }

    private function annualResult(string $contractId, int $consumption)
    {
        return collect(app(AsOfAnnualCostCalculator::class)->calculate(self::DATE))
            ->first(fn ($result): bool => $result->contractId === $contractId
                && $result->consumptionKwh === $consumption);
    }

    private function contract(string $id, bool $active = false, string $pricingModel = 'FixedPrice'): ElectricityContract
    {
        $factory = ElectricityContract::factory()->forCompany('As Of Energy Oy')->legacy();
        if ($active) {
            $factory = $factory->active();
        }

        return $factory->create([
            'id' => $id,
            'name' => $id,
            'pricing_model' => $pricingModel,
            'pricing_name' => $pricingModel,
        ]);
    }

    /** @param array<int, float|null> $masks */
    private function snapshot(
        ElectricityContract $contract,
        string $date = self::DATE,
        string $pricingModel = 'FixedPrice',
        string $segment = 'open_ended',
        ?float $energy = 8.0,
        ?float $fee = 0.0,
        array $masks = [2000 => 1.0, 5000 => 1.0, 18000 => 1.0],
    ): void {
        DB::table('contract_price_snapshots')->insert([
            'snapshot_date' => $date,
            'contract_id' => $contract->id,
            'company_name' => $contract->company_name,
            'contract_name' => $contract->name,
            'pricing_model' => $pricingModel,
            'contract_type' => 'OpenEnded',
            'fixed_time_range' => null,
            'metering' => 'General',
            'segment_key' => $segment,
            'pricing_basis' => 'observed_seller_data',
            'energy_price_cents_per_kwh' => $energy,
            'monthly_fee_eur' => $fee,
            'annual_cost_2000_kwh' => $masks[2000] ?? null,
            'annual_cost_5000_kwh' => $masks[5000] ?? null,
            'annual_cost_18000_kwh' => $masks[18000] ?? null,
            'has_discount' => false,
            'includes_spot_price' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function priceComponent(
        ElectricityContract $contract,
        string $type,
        float $price,
        string $date = self::DATE,
        string $paymentUnit = 'CentPerKiloWattHour',
    ): void {
        DB::table('price_components')->insert([
            'id' => $contract->id.'-'.$type.'-'.$date,
            'price_date' => $date,
            'price_component_type' => $type,
            'electricity_contract_id' => $contract->id,
            'has_discount' => false,
            'price' => $price,
            'payment_unit' => $paymentUnit,
        ]);
    }

    /** @param array{canonical_pricing: array<string, mixed>, canonical_source_consistency: array<string, mixed>, canonical_calculation: array<string, mixed>} $attributes */
    private function strictInterpretation(
        ElectricityContract $contract,
        array $attributes,
        string $firstObserved,
        string $lastObserved,
    ): ContractInterpretation {
        $snapshot = ContractSourceSnapshot::create([
            'contract_id' => $contract->id,
            'source_fingerprint' => hash('sha256', $contract->id.$firstObserved.$lastObserved),
            'source_payload' => ['id' => $contract->id],
            'first_observed_at' => $firstObserved,
            'last_observed_at' => $lastObserved,
        ]);
        $observation = ContractSourceObservation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $snapshot->id,
            'first_observed_at' => $firstObserved,
            'last_observed_at' => $lastObserved,
        ]);

        return ContractInterpretation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $snapshot->id,
            'analysis_source_observation_id' => $observation->id,
            'analysis_fingerprint' => hash('sha256', 'interpretation-'.$snapshot->id),
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
            'completed_at' => CarbonImmutable::parse($firstObserved, 'Europe/Helsinki')->addHour(),
        ]);
    }

    /** @return array{canonical_pricing: array<string, mixed>, canonical_source_consistency: array<string, mixed>, canonical_calculation: array<string, mixed>} */
    private function fixedAttributes(float $price): array
    {
        return CanonicalPricingFixture::attributes(
            phases: [CanonicalPricingFixture::phase(
                'Hinta',
                PhaseKind::CurrentStructured,
                CanonicalPricingFixture::boundary(BoundaryKind::ContractStart),
                CanonicalPricingFixture::boundary(BoundaryKind::None),
                [CanonicalPricingFixture::component(ComponentType::EnergyGeneral, $price, ComponentUnit::CentsPerKwh)],
            )],
            calculationStatus: CalculationStatus::Exact,
        );
    }

    private function rollingSpot(?int $hoursCount = null): void
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
            'hours_count' => $hoursCount ?? (int) $start->utc()->diffInHours($end->addDay()->utc()),
        ]);
    }
}

class FakeAsOfCurve implements MarketReferenceCurveProvider
{
    public int $forwardCalls = 0;

    /** @param array<int, float>|null $seasonalIndex */
    public function __construct(
        private readonly bool $available,
        private readonly ?array $seasonalIndex = null,
    ) {}

    public function tradeDate(CarbonImmutable $asOfDate): ?CarbonImmutable
    {
        return $this->available ? $asOfDate->subDay() : null;
    }

    public function referencePrice(CarbonImmutable $asOfDate, CarbonImmutable $anchorMonth, array $kindPreference): ?array
    {
        return $this->available ? [
            'kind' => 'month',
            'price_cents_per_kwh' => 4.0,
            'trade_date' => $asOfDate->subDay()->toDateString(),
        ] : null;
    }

    public function forwardPriceForMonth(CarbonImmutable $asOfDate, CarbonImmutable $deliveryMonth): ?array
    {
        $this->forwardCalls++;

        return $this->available ? ['kind' => 'month', 'price_cents_per_kwh' => 6.0] : null;
    }

    public function spotSeasonalIndex(): ?array
    {
        return $this->seasonalIndex;
    }

    public function fixedTermMedianEnergyPrice(): ?float
    {
        return null;
    }
}
