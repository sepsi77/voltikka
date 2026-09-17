<?php

namespace Tests\Unit\CanonicalPricing;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingRequest;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\ComparisonPolicy;
use App\Services\CanonicalPricing\Enums\PeriodPricingUnavailableReason;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
use App\Services\CanonicalPricing\SpotForward\DTO\SpotEstimate;
use App\Services\CanonicalPricing\SpotForward\Enums\SpotEstimateBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedPriceEstimator;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class CurrentAmbiguousEnergyMechanismsTest extends TestCase
{
    private function phase(int $start = 0, int $end = 24): array
    {
        return [
            'phase_kind' => $start === 0 ? 'current_structured' : 'future',
            'starts' => ['kind' => $start === 0 ? 'contract_start' : 'after_months', 'value' => $start],
            'ends' => ['kind' => 'after_months', 'value' => $end],
            'components' => [
                ['component_type' => 'monthly_fee', 'amount' => 5.5, 'unit' => 'eur_per_month', 'price_role' => 'current'],
                ['component_type' => 'energy_general', 'amount' => 12.8, 'unit' => 'cents_per_kwh', 'price_role' => 'current'],
                ['component_type' => 'spot_margin', 'amount' => 0.55, 'unit' => 'cents_per_kwh', 'price_role' => 'current'],
            ],
        ];
    }

    private function calculate(array $phases, string $term = 'Fixed24', ComparisonPolicy $policy = ComparisonPolicy::Current, bool $forward = false): array
    {
        $data = (new CanonicalPricingParser)->parse([
            'phases' => $phases,
            'consumption_effect' => ['present' => true, 'applies_to' => 'base_contract'],
        ], ['status' => 'unsupported'], ['structured_pricing_status' => 'complete']);
        $curve = $this->createMock(MarketReferenceCurveProvider::class);
        $calculator = new CanonicalContractPriceCalculator(new MarketResetPriceEstimator($curve), new SupplierAdjustedPriceEstimator($curve));
        $context = new ContractContext('Hybrid', 'FixedTerm', 'General', $term, 'Household');
        $start = CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki');
        $spot = new SpotAssumptions(10, 10);
        $outcome = $calculator->calculate($data, $context, new EnergyUsage(total: 5000, basicLiving: 5000), $spot, $start, spotEstimate: $forward ? new SpotEstimate(SpotEstimateBasis::ForwardCurve, 10, 10, 10, 0, 0, null, null, '2025-12-31', '2025-12-31', [], 10, 10, 10, 'higher') : null, policy: $policy);

        return [$outcome, $calculator, $data, $context, $start, $spot];
    }

    public function test_simultaneous_billed_mechanisms_fail_current_and_factual_period_but_keep_historical_cost(): void
    {
        [$current, $calculator, $data, $context, $start, $spot] = $this->calculate([$this->phase()]);
        $this->assertSame('excluded_incomplete', $current->comparability->value);
        $this->assertContains('ambiguous_energy_mechanisms', $current->assumptions);
        $this->assertNull($current->totalCost);
        [$historical] = $this->calculate([$this->phase()], policy: ComparisonPolicy::Historical);
        $this->assertEqualsWithDelta(706.0, $historical->totalCost, 0.000001);
        $this->assertNotContains('ambiguous_energy_mechanisms', $historical->assumptions);
        [$historicalForward] = $this->calculate([$this->phase()], policy: ComparisonPolicy::Historical, forward: true);
        $this->assertEqualsWithDelta(593.5, $historicalForward->totalCost, 0.000001);
        [$currentForward] = $this->calculate([$this->phase()], forward: true);
        $this->assertNull($currentForward->totalCost);
        $this->assertContains('ambiguous_energy_mechanisms', $currentForward->assumptions);
        // Even a pre-existing annual result cannot authorize ambiguous factual pricing.
        $period = $calculator->calculatePeriod($data, $context, new CanonicalPeriodPricingRequest($start, $start->addDays(30), 400, 5000, []), $spot, $historical);
        $this->assertNull($period->periodTotal);
        $this->assertSame('excluded_incomplete', $period->comparability->value);
        $this->assertSame(PeriodPricingUnavailableReason::NoPricing, $period->unavailableReason);
    }

    public function test_sequential_fixed_to_spot_and_non_billed_references_stay_valid(): void
    {
        $fixed = $this->phase(0, 6);
        unset($fixed['components'][2]);
        $spot = $this->phase(6, 24);
        unset($spot['components'][1]);
        [$sequential] = $this->calculate([$fixed, $spot]);
        $this->assertNotNull($sequential->totalCost);
        $this->assertNotContains('ambiguous_energy_mechanisms', $sequential->assumptions);
        foreach (['typical', 'bound', 'estimated'] as $role) {
            foreach ([1, 2] as $reference) {
                $phase = $this->phase();
                $phase['components'][$reference]['price_role'] = $role;
                [$outcome] = $this->calculate([$phase]);
                $this->assertNotNull($outcome->totalCost);
                $this->assertNotContains('ambiguous_energy_mechanisms', $outcome->assumptions);
            }
        }
    }

    public function test_ambiguous_post_term_phase_is_irrelevant_to_a_known_short_term(): void
    {
        $fixed = $this->phase(0, 6);
        unset($fixed['components'][2]);
        [$current] = $this->calculate([$fixed, $this->phase(6, 24)], 'Fixed6');
        $this->assertNotNull($current->contractTermTotalCost);
        $this->assertEqualsWithDelta(706.0, $current->totalCost, 0.000001);
        $this->assertNotContains('ambiguous_energy_mechanisms', $current->assumptions);
    }
}
