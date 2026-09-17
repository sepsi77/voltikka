<?php

namespace Tests\Unit\CanonicalPricing;

use App\Enums\MeteringType;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\EnergyRulePlan;
use App\Services\CanonicalPricing\DTO\NormalEnergyProjection;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimate;
use App\Services\CanonicalPricing\MarketReset\Enums\ResetEstimateBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimate;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\SupplierAdjustedEstimateBasis;
use App\Services\CanonicalPricing\Support\PhaseTimelineBuilder;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\EnergyRulesFixture as F;
use Tests\TestCase;
use Tests\Unit\CanonicalPricing\Support\HoldFlatCanonicalCalculator;

class EnergyRuleEstimateDisclosureTest extends TestCase
{
    private function fixtureOutput(string $kind = 'fixed_price', ?float $floor = null): array
    {
        [, $output] = F::example($kind, 9, 5, $floor);

        return $output;
    }

    private function projection(float $offset = 0, float $fee = 0, bool $reset = false): NormalEnergyProjection
    {
        $offsets = array_fill_keys(array_map(fn ($m) => sprintf('2026-%02d', $m), range(2, 12)), $offset);
        $estimate = $reset
            ? new ResetEstimate(ResetEstimateBasis::ForwardCurveShift, $offsets, 1, 'monthly', 9, tailStartsMonthKey: '2026-02')
            : new SupplierAdjustedEstimate(SupplierAdjustedEstimateBasis::ForwardCurveShift, $offsets, 1, 9, $fee, null, null, null, null, null, '2026-02', PriceEpisodeAnchor::missing());

        return new NormalEnergyProjection(['energy_general' => 9.0], $estimate);
    }

    private function data(array $output)
    {
        return (new CanonicalPricingParser)->parse($output['pricing'], $output['calculation'], $output['source_consistency'], withEnergyRules: true);
    }

    private function calculate(array $output, NormalEnergyProjection $projection, bool $short = false)
    {
        Http::preventStrayRequests();

        return HoldFlatCanonicalCalculator::make()->calculate($this->data($output),
            new ContractContext('FixedPrice', $short ? 'FixedTerm' : 'OpenEnded', 'General', $short ? 'Fixed6' : null, 'Household'),
            new EnergyUsage(total: 1200, basicLiving: 1200), new SpotAssumptions(null, null),
            CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki'), normalEnergyProjection: $projection);
    }

    public function test_fee_disclosure_uses_each_billed_side_and_the_real_window(): void
    {
        foreach ([
            'actual changes' => [0, 6, 6, 6, false, true],
            'normal changes' => [2, 2, 3, 6, false, true],
            'equal phases' => [2, 2, 2, 2, false, false],
            'zero fees' => [0, 0, 0, 0, false, false],
            'different constant sides' => [2, 2, 6, 6, false, false],
            'clipped future change' => [2, 6, 3, 7, true, false],
        ] as $name => [$a, $b, $na, $nb, $short, $changes]) {
            $output = $this->fixtureOutput();
            if ($short) {
                $output['pricing']['phases'][0]['ends']['value'] = '9';
                $output['pricing']['phases'][0]['components'][0]['energy_rule']['ends']['value'] = '9';
                $output['pricing']['phases'][1]['starts']['value'] = '9';
            }
            $output['pricing']['phases'][1]['ends'] = ['kind' => 'after_months', 'value' => '12'];
            foreach ([[$a, $na], [$b, $nb]] as $index => [$actual, $normal]) {
                $output['pricing']['phases'][$index]['components'][] = ['component_type' => 'monthly_fee', 'amount' => $actual, 'normal_amount' => $normal, 'unit' => 'eur_per_month', 'price_role' => 'normal', 'energy_rule' => null];
            }
            $result = $this->calculate($output, $this->projection(fee: $a), $short);
            $this->assertNotNull($result->energyRuleComparison, $name.' '.json_encode($result->assumptions));
            $payload = $result->toCalculatedCostArray();
            $estimate = $payload['energy_rule_comparison']['projection']['estimate'];
            $this->assertSame($changes ? 'disclosed_phases' : 'held_flat', $estimate['monthly_fee_assumption'], $name);
            $this->assertSame((float) $a, $estimate['monthly_fee']);
            $this->assertNull($payload['energy_rule_comparison']['actual_projection']);
            $this->assertEqualsWithDelta($short ? 48 + 12 * $a : 93 + 3 * $a + 9 * $b, $result->totalCost, .00001, $name);
            $this->assertEqualsWithDelta($short ? 108 + 12 * $na : 108 + 3 * $na + 9 * $nb, $result->baseTotalCost, .00001, $name);
            $this->assertSame($payload, ContractPricingViewData::fromArray($payload)->toArray());
            $resetPayload = $this->calculate($output, $this->projection(reset: true), $short)->toCalculatedCostArray();
            $this->assertArrayNotHasKey('monthly_fee_assumption', $resetPayload['energy_rule_comparison']['projection']['estimate']);
        }
    }

    public function test_normal_clipping_keeps_a_guaranteed_actual_exact(): void
    {
        $output = $this->fixtureOutput();
        $output['pricing']['phases'][0]['ends']['value'] = '12';
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['ends']['value'] = '12';
        $output['pricing']['phases'][1]['starts']['value'] = '12';
        foreach ([-12.0, 2.0] as $offset) {
            $result = $this->calculate($output, $this->projection($offset));
            $this->assertFalse($result->isEstimate());
            $this->assertFalse($result->energyRuleComparison->actualEstimated);
            $this->assertTrue($result->energyRuleComparison->normalEstimated);
            $this->assertNull($result->energyRuleComparison->actualProjection);
            $this->assertEqualsWithDelta(48, $result->totalCost, .00001);
            $this->assertEqualsWithDelta(9 + 11 * max(0, 9 + $offset), $result->baseTotalCost, .00001);
            $this->assertSame($offset < -9, in_array('energy_rule_nonnegative_model_floor_applied', $result->assumptions, true));
        }
    }

    public function test_direct_adjustable_and_formula_clamps_keep_source_floors_separate(): void
    {
        $start = CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki');
        foreach (['adjustable_tariff', 'absolute_discount'] as $kind) {
            foreach ([null, 0.0] as $floor) {
                foreach ([-12.0, -6.0, 2.0] as $offset) {
                    $output = $this->fixtureOutput('absolute_discount', $floor);
                    if ($kind === 'adjustable_tariff') {
                        $component = &$output['pricing']['phases'][0]['components'][0];
                        $component['amount'] = 9;
                        $component['normal_amount'] = null;
                        $component['price_role'] = 'normal';
                        $component['energy_rule'] = ['kind' => $kind, 'starts' => ['kind' => 'contract_start', 'value' => null], 'ends' => ['kind' => 'none', 'value' => null], 'discount_value' => null, 'floor_amount' => null, 'normal_basis' => null, 'evidence' => F::scope()];
                        unset($component);
                    }
                    $plan = EnergyRulePlan::build($this->data($output), MeteringType::General, $start, $start->addYear(), new PhaseTimelineBuilder, $this->projection($offset));
                    $this->assertNotNull($plan);
                    $rates = $plan->rates($start->addMonth());
                    $expected = $offset < -9 || ($kind === 'absolute_discount' && $floor === null && 9 + $offset - 5 < 0);
                    $this->assertSame($expected, $rates['model_floor_applied']);
                    $this->assertSame($kind === 'absolute_discount' ? max(0.0, max(0.0, 9 + $offset) - 5) : max(0.0, 9 + $offset), $rates['actual']['energy_general']);
                    $this->assertFalse($plan->rates($start)['model_floor_applied']);
                    $this->assertSame($kind === 'adjustable_tariff' ? null : $floor, $this->data($output)->phases[0]->components[0]->energyRule->floorAmount);
                }
            }
        }
    }
}
