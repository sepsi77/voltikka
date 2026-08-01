<?php

namespace Tests\Unit;

use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\DTO\ContractPricingIntegrity;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\ContractPricing\CanonicalContractMetric;
use App\Services\ContractPricing\ContractMetricSet;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\DTO\ContractPricingResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContractPricingReadModelTest extends TestCase
{
    public function test_legacy_result_adapter_keeps_the_exact_transport_shape(): void
    {
        $result = new ContractPricingResult(
            totalCost: 540.0,
            avgMonthlyCost: 45.0,
            monthlyCosts: array_fill(0, 12, 45.0),
            generalKwhPrice: 9.84,
        );

        $this->assertSame($result->toArray(), ContractPricingViewData::fromLegacyResult($result)->toArray());
    }

    public function test_canonical_outcome_adapter_and_batch_metric_round_trip_exactly(): void
    {
        $outcome = new CanonicalPricingOutcome(
            comparability: ContractComparability::ComparableExact,
            estimateMethod: EstimateMethod::None,
            totalCost: 540.0,
            monthlyCosts: array_fill(0, 12, 45.0),
            baseTotalCost: 540.0,
            baseMonthlyCosts: array_fill(0, 12, 45.0),
            measuredDiscountSavingsTotal: 0.0,
            monthlyDiscountSavings: array_fill(0, 12, 0.0),
            structuredOnlyTotal: 540.0,
            isSpotContract: false,
            generalKwhPrice: 9.84,
        );
        $pricing = ContractPricingViewData::fromCanonicalOutcome($outcome);
        $metric = CanonicalContractMetric::fromEvaluation($outcome, ContractPricingIntegrity::none());

        $this->assertSame($outcome->toCalculatedCostArray(), $pricing->toArray());
        $this->assertSame($metric->toArray(), CanonicalContractMetric::fromArray($metric->toArray())->toArray());
        $this->assertSame(540.0, $metric->sortKey());
        $this->assertTrue($metric->isListed());
    }

    public function test_valid_legacy_payload_round_trips_exactly(): void
    {
        $payload = $this->legacyPricing();

        $pricing = ContractPricingViewData::fromArray($payload);

        $this->assertSame($payload, $pricing->toArray());
        $this->assertSame(540.0, $pricing->total());
        $this->assertNull($pricing->pricingBasis());
    }

    public function test_valid_exact_canonical_payload_round_trips_exactly(): void
    {
        $payload = $this->canonicalPricing();
        $payload['harmless_auxiliary'] = ['source' => 'test'];

        $pricing = ContractPricingViewData::fromArray($payload);

        $this->assertSame($payload, $pricing->toArray());
        $this->assertSame('canonical', $pricing->pricingBasis());
        $this->assertFalse($pricing->isEstimate());
    }

    public function test_valid_excluded_payload_and_metric_set_round_trip_exactly(): void
    {
        $pricing = $this->canonicalPricing([
            'total_cost' => null,
            'avg_monthly_cost' => null,
            'monthly_costs' => [],
            'base_total_cost' => null,
            'base_avg_monthly_cost' => null,
            'base_monthly_costs' => [],
            'comparability' => 'excluded_incomplete',
            'is_estimate' => false,
            'estimate_method' => 'none',
            'structured_only_total' => null,
        ]);
        foreach ($this->rateKeys() as $key) {
            $pricing[$key] = null;
        }

        $payload = $this->metricSet($pricing, listed: false, sortKey: null, serializedTotal: PHP_FLOAT_MAX);

        $set = ContractMetricSet::fromArray($payload);

        $this->assertSame($payload, $set->toArray());
        $this->assertNull($set->metric('contract-a')?->pricing()->total());
        $this->assertSame(['contract-a'], $set->excludedIds());
    }

    public function test_valid_package_payload_round_trips_exactly(): void
    {
        $payload = $this->canonicalPricing([
            'energy_package' => [
                'monthly_fee_eur' => 21.0,
                'included_kwh' => 150.0,
                'allowance_cadence' => 'monthly',
                'excess_rate_cents_per_kwh' => 16.6,
                'auxiliary' => 'kept',
            ],
        ]);

        $pricing = ContractPricingViewData::fromArray($payload);

        $this->assertSame($payload, $pricing->toArray());
        $this->assertSame(150.0, $pricing->energyPackage()?->number('included_kwh'));
    }

    public function test_valid_hybrid_payload_round_trips_exactly(): void
    {
        $payload = $this->canonicalPricing([
            'comparability' => 'base_only_hybrid',
            'is_estimate' => true,
            'estimate_method' => 'hybrid_base_only',
            'consumption_effect' => $this->consumptionEffect(),
        ]);

        $pricing = ContractPricingViewData::fromArray($payload);

        $this->assertSame($payload, $pricing->toArray());
        $this->assertTrue($pricing->consumptionEffect()?->boolean('present'));
    }

    public function test_valid_short_term_payload_round_trips_exactly(): void
    {
        $payload = $this->canonicalPricing([
            'comparability' => 'term_price_only',
            'is_estimate' => true,
            'estimate_method' => 'term_price_annualized',
            'term_months' => 6,
            'contract_term' => [
                'months' => 6,
                'total_cost' => 260.0,
                'base_total_cost' => 290.0,
                'discount_savings_total' => 30.0,
            ],
        ]);

        $pricing = ContractPricingViewData::fromArray($payload);

        $this->assertSame($payload, $pricing->toArray());
        $this->assertSame(6, $pricing->contractTerm()?->integer('months'));
    }

    public function test_valid_reset_phase_and_offer_payloads_round_trip_with_auxiliary_keys(): void
    {
        $payload = $this->canonicalPricing([
            'comparability' => 'comparable_estimate',
            'is_estimate' => true,
            'estimate_method' => 'recurring_forward_curve_shift',
            'reset_estimate' => $this->resetEstimate(),
            'phase_breakdown' => [$this->phase()],
            'offer_terms' => [$this->offerTerm()],
        ]);

        $pricing = ContractPricingViewData::fromArray($payload);

        $this->assertSame($payload, $pricing->toArray());
        $this->assertSame('forward_curve_shift', $pricing->resetEstimate()?->string('basis'));
        $this->assertCount(1, $pricing->phases());
        $this->assertCount(1, $pricing->offerTerms());
    }

    public function test_valid_reset_hybrid_zero_beta_and_empty_phase_label_are_supported(): void
    {
        $reset = $this->resetEstimate();
        $reset['beta'] = 0.0;
        $phase = $this->phase();
        $phase['label'] = '';
        $payload = $this->canonicalPricing([
            'comparability' => 'base_only_hybrid',
            'is_estimate' => true,
            'estimate_method' => 'recurring_forward_curve_shift',
            'consumption_effect' => null,
            'reset_estimate' => $reset,
            'phase_breakdown' => [$phase],
        ]);

        $pricing = ContractPricingViewData::fromArray($payload);

        $this->assertSame($payload, $pricing->toArray());
        $this->assertSame(0.0, $pricing->resetEstimate()?->number('beta'));
        $this->assertSame('', $pricing->phases()[0]->string('label'));
    }

    public function test_missing_required_key_fails(): void
    {
        $payload = $this->legacyPricing();
        unset($payload['total_cost']);

        $this->expectException(InvalidArgumentException::class);
        ContractPricingViewData::fromArray($payload);
    }

    #[DataProvider('nonFiniteProvider')]
    public function test_non_finite_total_fails(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContractPricingViewData::fromArray($this->legacyPricing(['total_cost' => $value]));
    }

    public static function nonFiniteProvider(): array
    {
        return [[INF], [-INF], [NAN]];
    }

    public function test_non_finite_sort_key_fails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContractMetricSet::fromArray($this->metricSet($this->canonicalPricing(), sortKey: INF));
    }

    public function test_listed_metric_without_total_fails(): void
    {
        $pricing = $this->canonicalPricing(['total_cost' => null, 'avg_monthly_cost' => null]);

        $this->expectException(InvalidArgumentException::class);
        ContractMetricSet::fromArray($this->metricSet($pricing));
    }

    public function test_excluded_metric_with_sort_key_fails(): void
    {
        $pricing = $this->excludedPricing();

        $this->expectException(InvalidArgumentException::class);
        ContractMetricSet::fromArray($this->metricSet($pricing, listed: false, sortKey: 1.0));
    }

    #[DataProvider('malformedOptionalFactsProvider')]
    public function test_malformed_optional_facts_fail(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContractPricingViewData::fromArray($this->canonicalPricing($changes));
    }

    public static function malformedOptionalFactsProvider(): array
    {
        return [
            'package missing excess rate' => [['energy_package' => [
                'monthly_fee_eur' => 10.0,
                'included_kwh' => 100.0,
                'allowance_cadence' => 'monthly',
            ]]],
            'term has zero months' => [['contract_term' => [
                'months' => 0,
                'total_cost' => 100.0,
                'base_total_cost' => 110.0,
                'discount_savings_total' => 10.0,
            ]]],
            'hybrid has the wrong estimate method' => [[
                'comparability' => 'base_only_hybrid',
                'is_estimate' => true,
                'estimate_method' => 'term_price_annualized',
                'consumption_effect' => null,
            ]],
            'estimate has unsupported method' => [[
                'comparability' => 'comparable_estimate',
                'is_estimate' => true,
                'estimate_method' => 'unsupported',
            ]],
            'reset misses required basis' => [['reset_estimate' => [
                'beta' => 1.0,
            ]]],
            'phase has malformed date' => [['phase_breakdown' => [[
                'label' => 'Current',
                'phase_kind' => 'current_structured',
                'starts' => 'contract_start',
                'ends' => 'date',
                'ends_value' => '2026-08-31',
                'window_start' => 'not-a-date',
                'window_end' => '2026-08-31',
                'uses_spot' => false,
                'energy_cents' => 7.0,
                'spot_margin_cents' => null,
                'monthly_fee' => 4.0,
                'energy_package' => null,
            ]]]],
            'offer has no components' => [['offer_terms' => [[
                'end_kind' => 'after_months',
                'starts_on' => '2026-08-01',
                'ends_on' => '2026-08-31',
                'duration_months' => 1,
                'starts_after_months' => 0,
                'ends_after_months' => 1,
                'starts_at_window_start' => true,
                'components' => [],
            ]]]],
        ];
    }

    public function test_excluded_canonical_public_rate_leakage_fails(): void
    {
        $pricing = $this->excludedPricing();
        $pricing['general_kwh_price'] = 0.0;

        $this->expectException(InvalidArgumentException::class);
        ContractPricingViewData::fromArray($pricing);
    }

    private function legacyPricing(array $changes = []): array
    {
        return array_replace([
            'total_cost' => 540.0,
            'avg_monthly_cost' => 45.0,
            'monthly_costs' => array_fill(0, 12, 45.0),
            'monthly_fixed_fee' => 4.0,
            'spot_price_margin' => null,
            'general_kwh_price' => 9.84,
            'nighttime_kwh_price' => null,
            'daytime_kwh_price' => null,
            'seasonal_winter_day_kwh_price' => null,
            'seasonal_other_kwh_price' => null,
            'spot_price_day_avg' => null,
            'spot_price_night_avg' => null,
            'is_spot_contract' => false,
            'base_total_cost' => 600.0,
            'base_avg_monthly_cost' => 50.0,
            'base_monthly_costs' => array_fill(0, 12, 50.0),
            'discount_savings_total' => 60.0,
            'monthly_discount_savings' => array_fill(0, 12, 5.0),
            'includes_discounts' => true,
        ], $changes);
    }

    private function canonicalPricing(array $changes = []): array
    {
        return array_replace($this->legacyPricing(), [
            'pricing_basis' => 'canonical',
            'comparability' => 'comparable_exact',
            'is_estimate' => false,
            'estimate_method' => 'none',
            'term_months' => null,
            'energy_package' => null,
            'contract_term' => null,
            'phase_breakdown' => [],
            'offer_terms' => [],
            'structured_only_total' => 540.0,
            'consumption_effect' => null,
            'assumptions' => [],
            'reset_estimate' => null,
        ], $changes);
    }

    private function excludedPricing(): array
    {
        $pricing = $this->canonicalPricing([
            'total_cost' => null,
            'avg_monthly_cost' => null,
            'monthly_costs' => [],
            'base_total_cost' => null,
            'base_avg_monthly_cost' => null,
            'base_monthly_costs' => [],
            'comparability' => 'excluded_incomplete',
            'structured_only_total' => null,
        ]);
        foreach ($this->rateKeys() as $key) {
            $pricing[$key] = null;
        }

        return $pricing;
    }

    private function metricSet(array $pricing, bool $listed = true, ?float $sortKey = 540.0, float $serializedTotal = 540.0): array
    {
        return [
            'contracts' => [
                'contract-a' => [
                    'calculated_cost' => $pricing,
                    'emission_factor' => 12.5,
                    'exceeds_consumption_limit' => false,
                    'total_cost' => $serializedTotal,
                    'comparability' => $pricing['comparability'] ?? null,
                    'is_listed' => $listed,
                    'sort_key' => $sortKey,
                    'pricing_integrity' => null,
                ],
            ],
            'sorted_ids' => $listed ? ['contract-a'] : [],
            'excluded_ids' => $listed ? [] : ['contract-a'],
            'consumption' => 5000,
        ];
    }

    private function consumptionEffect(): array
    {
        return [
            'present' => true,
            'applies_to' => 'base_contract',
            'expected_cents_per_kwh' => null,
            'typical_min_cents_per_kwh' => -2.0,
            'typical_max_cents_per_kwh' => 2.0,
            'hard_min_cents_per_kwh' => null,
            'hard_max_cents_per_kwh' => null,
            'uncapped' => true,
        ];
    }

    private function resetEstimate(): array
    {
        return [
            'basis' => 'forward_curve_shift',
            'beta' => 1.0,
            'cadence' => 'quarterly',
            'current_period_energy_price' => 6.6,
            'annual_equivalent_energy_price' => 9.28,
            'reference_kind' => 'quarter_month_average',
            'reference_price' => 4.72,
            'curve_trade_date' => '2026-07-30',
            'reference_trade_date' => '2026-06-30',
            'anchor_period' => 'Q3 2026',
            'tail_starts' => '2026-10',
            'higher_confidence' => true,
            'flags' => [],
            'auxiliary' => ['kept' => true],
        ];
    }

    private function phase(): array
    {
        return [
            'label' => 'Intro',
            'phase_kind' => 'introductory',
            'starts' => 'contract_start',
            'ends' => 'after_months',
            'ends_value' => 1,
            'window_start' => '2026-08-01',
            'window_end' => '2026-08-31',
            'uses_spot' => false,
            'energy_cents' => 7.0,
            'spot_margin_cents' => null,
            'monthly_fee' => 0.0,
            'energy_package' => null,
            'auxiliary' => 'kept',
        ];
    }

    private function offerTerm(): array
    {
        return [
            'end_kind' => 'after_months',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-31',
            'duration_months' => 1,
            'starts_after_months' => 0,
            'ends_after_months' => 1,
            'starts_at_window_start' => true,
            'components' => [[
                'component_type' => 'monthly_fee',
                'unit' => 'eur_per_month',
                'amount' => 0.0,
                'normal_amount' => 4.0,
            ]],
            'auxiliary' => 'kept',
        ];
    }

    private function rateKeys(): array
    {
        return [
            'monthly_fixed_fee', 'spot_price_margin', 'general_kwh_price',
            'nighttime_kwh_price', 'daytime_kwh_price',
            'seasonal_winter_day_kwh_price', 'seasonal_other_kwh_price',
            'spot_price_day_avg', 'spot_price_night_avg',
        ];
    }
}
