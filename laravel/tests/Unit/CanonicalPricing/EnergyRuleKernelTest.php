<?php

namespace Tests\Unit\CanonicalPricing;

use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingRequest;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\NormalEnergyProjection;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\ComparisonPolicy;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimate;
use App\Services\CanonicalPricing\MarketReset\Enums\ResetEstimateBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\CurrentNormalCandidateExtractor;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimate;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\SupplierAdjustedEstimateBasis;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\EnergyRulesFixture as F;
use Tests\TestCase;
use Tests\Unit\CanonicalPricing\Support\HoldFlatCanonicalCalculator;

class EnergyRuleKernelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    private function fixtureOutput(string $kind = 'fixed_price', float $normal = 9, float $operand = 5, ?float $floor = null): array
    {
        [, $output] = F::example($kind, $normal, $operand, $floor);
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis']['starts'] = ['kind' => 'contract_start', 'value' => null];

        return $output;
    }

    private function projection(array $offsets = ['2026-02' => 3.0], array $rates = ['energy_general' => 9.0], bool $reset = false): NormalEnergyProjection
    {
        $representative = isset($rates['energy_day']) ? ($rates['energy_day'] * 15 + $rates['energy_night'] * 9) / 24
            : (isset($rates['energy_seasonal_winter']) ? ($rates['energy_seasonal_winter'] * 5 + $rates['energy_seasonal_other'] * 7) / 12 : reset($rates));
        $estimate = $reset
            ? new ResetEstimate(ResetEstimateBasis::ForwardCurveShift, $offsets, 1, 'monthly', reset($rates), tailStartsMonthKey: '2026-02', tailStartsOn: '2026-02-15')
            : new SupplierAdjustedEstimate(SupplierAdjustedEstimateBasis::ForwardCurveShift, $offsets, 1, $representative, 0, null, null, null, null, null, '2026-02', PriceEpisodeAnchor::missing());

        return new NormalEnergyProjection($rates, $estimate);
    }

    private function calculate(array $output, ?NormalEnergyProjection $projection = null, ?ContractContext $context = null, string $start = '2026-01-01', ComparisonPolicy $policy = ComparisonPolicy::Current)
    {
        $data = (new CanonicalPricingParser)->parse($output['pricing'], $output['calculation'], $output['source_consistency'], withEnergyRules: true);

        return HoldFlatCanonicalCalculator::make()->calculate($data,
            $context ?? new ContractContext('FixedPrice', 'OpenEnded', 'General', null, 'Household'),
            new EnergyUsage(total: 1200, basicLiving: 1200), new SpotAssumptions(null, null), CarbonImmutable::parse($start, 'Europe/Helsinki'),
            policy: $policy, normalEnergyProjection: $projection);
    }

    public function test_fixed_and_formula_use_the_same_projected_normal_without_double_offsets(): void
    {
        $a = $this->calculate($this->fixtureOutput(), $this->projection());
        $b = $this->calculate($this->fixtureOutput('absolute_discount'), $this->projection());
        $this->assertEqualsWithDelta(4, $a->monthlyCosts[1], 0.00001);
        $this->assertEqualsWithDelta(7, $b->monthlyCosts[1], 0.00001);
        $this->assertEqualsWithDelta(12, $a->baseMonthlyCosts[1], 0.00001);
        $this->assertEqualsWithDelta(12, $b->baseMonthlyCosts[1], 0.00001);
        $this->assertNotEmpty($a->offerTerms);
        $this->assertTrue($a->toCalculatedCostArray()['includes_discounts']);
        $this->assertArrayHasKey('energy_rule_comparison', $a->toCalculatedCostArray());
    }

    public function test_whole_window_fixed_actual_stays_exact_and_signed_net_is_not_monthly_clipped(): void
    {
        $output = $this->fixtureOutput();
        $output['pricing']['phases'][0]['ends']['value'] = '12';
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['ends']['value'] = '12';
        $output['pricing']['phases'][1]['starts']['value'] = '12';
        $offsets = array_fill_keys(array_map(fn ($month) => sprintf('2026-%02d', $month), range(2, 12)), -5.0);
        $offsets['2026-02'] = $offsets['2026-03'] = -6.0;
        $result = $this->calculate($output, $this->projection($offsets));
        $this->assertFalse($result->isEstimate());
        $this->assertFalse($result->energyRuleComparison->actualEstimated);
        $this->assertTrue($result->energyRuleComparison->normalEstimated);
        $this->assertEqualsWithDelta(3, $result->energyRuleComparison->netDifference, 0.00001);
        $this->assertEqualsWithDelta(3, $result->discountSavingsTotal(), 0.00001);
        $this->assertEqualsWithDelta(-1, $result->monthlyDiscountSavings[1], 0.00001);
        $payload = $result->toCalculatedCostArray();
        $this->assertSame($result->monthlyDiscountSavings, $payload['monthly_discount_savings']);
        $this->assertSame($payload, ContractPricingViewData::fromArray($payload)->toArray());
        $this->assertEqualsWithDelta(-1, $result->energyRuleComparison->signedMonthlyDifferences[1], 0.00001);
        $this->assertEqualsWithDelta(48, $result->totalCost, 0.00001);
        $offsets['2026-04'] = -9;
        $loss = $this->calculate($output, $this->projection($offsets));
        $this->assertEqualsWithDelta(-1, $loss->energyRuleComparison->netDifference, 0.00001);
        $this->assertFalse($loss->toCalculatedCostArray()['includes_discounts']);
        $held = $this->calculate($output);
        $this->assertFalse($held->isEstimate());
        $this->assertTrue($held->energyRuleComparison->normalHeld);
    }

    public function test_percentage_zero_fifty_and_hundred_keep_the_operator_at_normal_zero(): void
    {
        foreach ([0, 50, 100] as $percent) {
            $result = $this->calculate($this->fixtureOutput('percentage_discount', 0, $percent), $this->projection(['2026-02' => 12.0], ['energy_general' => 0.0]));
            $this->assertEqualsWithDelta(12 * (1 - $percent / 100), $result->monthlyCosts[1], 0.00001);
            $this->assertEqualsWithDelta(12, $result->baseMonthlyCosts[1], 0.00001);
        }
        $full = $this->fixtureOutput('percentage_discount', 0, 100);
        $full['pricing']['phases'][0]['ends']['value'] = '12';
        $full['pricing']['phases'][0]['components'][0]['energy_rule']['ends']['value'] = '12';
        $full['pricing']['phases'][1]['starts']['value'] = '12';
        $result = $this->calculate($full, $this->projection(['2026-02' => 12.0], ['energy_general' => 0.0]));
        $this->assertSame(0.0, $result->totalCost);
        $this->assertFalse($result->isEstimate());
        $this->assertTrue($result->energyRuleComparison->normalEstimated);
    }

    public function test_source_floor_and_nonnegative_model_floor_apply_once(): void
    {
        $projection = $this->projection(['2026-02' => -7.0]);
        $floor = $this->calculate($this->fixtureOutput('absolute_discount', 9, 5, 1), $projection);
        $model = $this->calculate($this->fixtureOutput('absolute_discount'), $projection);
        $this->assertEqualsWithDelta(1, $floor->monthlyCosts[1], 0.00001);
        $this->assertEqualsWithDelta(0, $model->monthlyCosts[1], 0.00001);
        $zero = $this->calculate($this->fixtureOutput('absolute_discount', 0, 0, 1));
        $this->assertEqualsWithDelta(1, $zero->monthlyCosts[1], 0.00001);
        $this->assertEqualsWithDelta(1, $zero->baseMonthlyCosts[1], 0.00001);
        $this->assertEqualsWithDelta(0, $zero->energyRuleComparison->netDifference, 0.00001);
    }

    public function test_monthly_fee_changes_are_not_borrowed_from_energy_introduction(): void
    {
        $output = $this->fixtureOutput();
        $fee = ['component_type' => 'monthly_fee', 'amount' => 4, 'normal_amount' => null, 'unit' => 'eur_per_month', 'price_role' => 'current', 'vat_status' => 'included', 'energy_rule' => null];
        $output['pricing']['phases'][0]['components'][] = $fee;
        $fee['amount'] = 8;
        $output['pricing']['phases'][1]['components'][] = $fee;
        $result = $this->calculate($output, $this->projection());
        $this->assertEqualsWithDelta(16, $result->baseMonthlyCosts[1], 0.00001);
        $this->assertEqualsWithDelta(17, $result->baseMonthlyCosts[3], 0.00001);
        foreach ([0, 2, 4] as $normalFee) {
            $output['pricing']['phases'][0]['components'][1]['normal_amount'] = $normalFee;
            $result = $this->calculate($output, $this->projection());
            $this->assertEqualsWithDelta(12 + $normalFee, $result->baseMonthlyCosts[1], 0.00001);
        }
        $output['pricing']['phases'][0]['components'][1]['normal_amount'] = null;
        $output['pricing']['phases'][0]['components'][1]['price_role'] = 'introductory';
        $this->assertEqualsWithDelta(20, $this->calculate($output, $this->projection())->baseMonthlyCosts[1], 0.00001);
    }

    public function test_gap_fee_normalization_does_not_apply_the_fee_difference_twice(): void
    {
        $output = $this->fixtureOutput();
        $output['pricing']['phases'][1]['starts']['value'] = '6';
        $output['pricing']['phases'][0]['components'][] = ['component_type' => 'monthly_fee', 'amount' => 1, 'normal_amount' => 4, 'unit' => 'eur_per_month', 'price_role' => 'introductory', 'energy_rule' => null];
        $result = $this->calculate($output, $this->projection(['2026-04' => 3.0]));
        $this->assertEqualsWithDelta(16, $result->monthlyCosts[3], 0.00001);
        $this->assertEqualsWithDelta(16, $result->baseMonthlyCosts[3], 0.00001);
    }

    public function test_equal_fixed_metadata_does_not_remove_price_protection(): void
    {
        $output = $this->fixtureOutput();
        $output['pricing']['phases'][0]['components'][0]['normal_amount'] = 4;
        $output['pricing']['phases'][1]['components'][0]['amount'] = 4;
        $result = $this->calculate($output, $this->projection(['2026-02' => 8.0], ['energy_general' => 4.0]));
        $this->assertEqualsWithDelta(4, $result->baseMonthlyCosts[1], 0.00001);
        $this->assertEqualsWithDelta(0, $result->energyRuleComparison->netDifference, 0.00001);
    }

    public function test_reset_midmonth_tail_and_rule_boundary_are_independent(): void
    {
        $output = $this->fixtureOutput('absolute_discount');
        $result = $this->calculate($output, $this->projection(reset: true));
        $this->assertEqualsWithDelta(5.5, $result->monthlyCosts[1], 0.00001);
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['starts'] = ['kind' => 'date', 'value' => '2026-01-01'];
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['ends'] = ['kind' => 'date', 'value' => '2026-02-14'];
        $output['pricing']['phases'][0]['ends'] = ['kind' => 'date', 'value' => '2026-02-14'];
        $output['pricing']['phases'][1]['starts'] = ['kind' => 'date', 'value' => '2026-02-15'];
        $result = $this->calculate($output, $this->projection(reset: true));
        $this->assertEqualsWithDelta(8, $result->monthlyCosts[1], 0.00001);
    }

    public function test_six_month_hybrid_clips_before_unsupported_post_term_rates(): void
    {
        $output = $this->fixtureOutput();
        $output['pricing']['consumption_effect']['present'] = true;
        $output['pricing']['consumption_effect']['applies_to'] = 'base_contract';
        $output['calculation']['status'] = 'unsupported';
        $future = $output['pricing']['phases'][1];
        $future['starts']['value'] = '6';
        $future['components'][0]['amount'] = 11;
        $output['pricing']['phases'][] = $future;
        $result = $this->calculate($output, $this->projection(), new ContractContext('Hybrid', 'FixedTerm', 'General', 'Fixed6', 'Household'));
        $this->assertTrue($result->isListed());
        $this->assertSame(6, $result->termMonths);
        $this->assertEqualsWithDelta($result->contractTermTotalCost * 2, $result->totalCost, 0.00001);
        $this->assertEqualsWithDelta(39, $result->contractTermTotalCost, 0.00001);
    }

    public function test_short_term_net_loss_is_not_an_annualized_offer_and_public_round_trip_stays_valid(): void
    {
        $output = $this->fixtureOutput();
        $output['pricing']['phases'][0]['ends']['value'] = '6';
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['ends']['value'] = '6';
        $output['pricing']['phases'][1]['starts']['value'] = '6';
        $offsets = array_fill_keys(['2026-02', '2026-03', '2026-04', '2026-05', '2026-06'], -6.0);
        $offsets['2026-06'] = -7.0;
        $result = $this->calculate($output, $this->projection($offsets), new ContractContext('FixedPrice', 'FixedTerm', 'General', 'Fixed6', 'Household'));
        $this->assertEqualsWithDelta(-1, $result->contractTermDiscountSavingsTotal, 0.00001);
        $this->assertEqualsWithDelta(-2, $result->energyRuleComparison->netDifference, 0.00001);
        $this->assertFalse($result->energyRuleComparison->actualEstimated);
        $payload = $result->toCalculatedCostArray();
        $this->assertEqualsWithDelta(-1, $payload['contract_term']['discount_savings_total'], .00001);
        $this->assertFalse($payload['includes_discounts']);
        $this->assertSame($payload, ContractPricingViewData::fromArray($payload)->toArray());
    }

    public function test_wrong_baseline_and_future_only_evidence_fail_closed_without_legacy_forecast(): void
    {
        $output = $this->fixtureOutput();
        $adjustable = $output;
        $adjustable['pricing']['phases'][0]['components'][0]['energy_rule']['kind'] = 'adjustable_tariff';
        $this->assertFalse($this->calculate($adjustable, $this->projection())->isListed());
        $conflict = $output;
        $conflict['pricing']['phases'][0]['components'][] = $conflict['pricing']['phases'][0]['components'][0];
        $conflict['pricing']['phases'][0]['components'][1]['amount'] = 4.5;
        $this->assertFalse($this->calculate($conflict, $this->projection())->isListed());
        $this->assertFalse($this->calculate($output, $this->projection(rates: ['energy_general' => 4.0]))->isListed());
        $output['pricing']['phases'][1]['components'][0]['amount'] = 11;
        $changed = $this->calculate($output, $this->projection());
        $this->assertTrue($changed->isListed());
        $this->assertEqualsWithDelta(11, $changed->monthlyCosts[3], .00001);
        $output = $this->fixtureOutput();
        $output['pricing']['phases'][0]['starts']['kind'] = 'after_months';
        $output['pricing']['phases'][0]['starts']['value'] = '1';
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['starts'] = ['kind' => 'after_months', 'value' => '1'];
        $this->assertFalse($this->calculate($output, $this->projection())->isListed());
    }

    public function test_time_and_season_buckets_keep_independent_guarantees_and_vat(): void
    {
        foreach (['Time' => ['energy_day', 'energy_night'], 'Season' => ['energy_seasonal_winter', 'energy_seasonal_other']] as $metering => [$first, $second]) {
            $output = $this->fixtureOutput();
            $formula = $this->fixtureOutput('absolute_discount', 6, 2);
            foreach ([0, 1] as $index) {
                $output['pricing']['phases'][$index]['components'][0]['component_type'] = $first;
                $component = $formula['pricing']['phases'][$index]['components'][0];
                $component['component_type'] = $second;
                $output['pricing']['phases'][$index]['components'][] = $component;
            }
            foreach (['Household' => 1.0, 'Company' => 1.255] as $target => $divisor) {
                $result = $this->calculate($output, $this->projection(['2026-02' => 3 / $divisor], [$first => 9 / $divisor, $second => 6 / $divisor]), new ContractContext('FixedPrice', 'OpenEnded', $metering, null, $target));
                $this->assertEqualsWithDelta(4.45 / $divisor, $result->monthlyCosts[1], 0.00001);
                $this->assertEqualsWithDelta(11.55 / $divisor, $result->baseMonthlyCosts[1], 0.00001);
            }
        }
    }

    public function test_phase_guarantee_flag_requires_one_protected_actual_rate_and_excludes_hybrid_effects(): void
    {
        foreach (['Time' => ['energy_day', 'energy_night'], 'Season' => ['energy_seasonal_winter', 'energy_seasonal_other']] as $metering => [$first, $second]) {
            $output = $this->fixtureOutput();
            foreach ([0, 1] as $index) {
                $component = $output['pricing']['phases'][$index]['components'][0];
                $output['pricing']['phases'][$index]['components'][0]['component_type'] = $first;
                $component['component_type'] = $second;
                $output['pricing']['phases'][$index]['components'][] = $component;
            }
            $context = new ContractContext('FixedPrice', 'OpenEnded', $metering, null, 'Household');
            $equal = $this->calculate($output, context: $context);
            $this->assertTrue($equal->phaseBreakdown[0]['energy_price_guaranteed']);
            $output['pricing']['phases'][0]['components'][1]['amount'] = 3;
            $different = $this->calculate($output, context: $context);
            $this->assertFalse($different->phaseBreakdown[0]['energy_price_guaranteed']);
            $this->assertSame(4.0, $different->phaseBreakdown[0]['energy_cents']);
        }
        $output = $this->fixtureOutput();
        $output['pricing']['consumption_effect']['present'] = true;
        $output['pricing']['consumption_effect']['applies_to'] = 'base_contract';
        $output['calculation']['status'] = 'unsupported';
        $hybrid = $this->calculate($output, context: new ContractContext('Hybrid', 'FixedTerm', 'General', 'Fixed6', 'Household'));
        $this->assertFalse($hybrid->phaseBreakdown[0]['energy_price_guaranteed']);
        $this->assertSame('base_only_hybrid', $hybrid->comparability->value);
    }

    public function test_multi_bucket_reset_projection_checks_the_normal_usage_weighted_anchor(): void
    {
        foreach (['Time' => ['energy_day', 'energy_night', 8.55], 'Season' => ['energy_seasonal_winter', 'energy_seasonal_other', 7.0625]] as $metering => [$first, $second, $anchor]) {
            $output = $this->fixtureOutput();
            $formula = $this->fixtureOutput('absolute_discount', 6, 2);
            foreach ([0, 1] as $index) {
                $output['pricing']['phases'][$index]['components'][0]['component_type'] = $first;
                $component = $formula['pricing']['phases'][$index]['components'][0];
                $component['component_type'] = $second;
                $output['pricing']['phases'][$index]['components'][] = $component;
            }
            $estimate = new ResetEstimate(ResetEstimateBasis::ForwardCurveShift, ['2026-02' => 3.0], 1, 'monthly', $anchor, tailStartsOn: '2026-02-15');
            $result = $this->calculate($output, new NormalEnergyProjection([$first => 9.0, $second => 6.0], $estimate), new ContractContext('FixedPrice', 'OpenEnded', $metering, null, 'Household'));
            $this->assertEqualsWithDelta(4.225, $result->monthlyCosts[1], 0.00001);
            $this->assertEqualsWithDelta(10.05, $result->baseMonthlyCosts[1], 0.00001);
            $wrong = new ResetEstimate(ResetEstimateBasis::ForwardCurveShift, ['2026-02' => 3.0], 1, 'monthly', 4, tailStartsOn: '2026-02-15');
            $this->assertFalse($this->calculate($output, new NormalEnergyProjection([$first => 9.0, $second => 6.0], $wrong), new ContractContext('FixedPrice', 'OpenEnded', $metering, null, 'Household'))->isListed());
        }
    }

    public function test_future_ordinary_price_cannot_reuse_the_old_normal_reference(): void
    {
        $output = $this->fixtureOutput();
        $output['pricing']['phases'][1]['starts']['value'] = '6';
        $future = $output['pricing']['phases'][0];
        $future['phase_kind'] = 'future';
        $future['starts'] = ['kind' => 'after_months', 'value' => '4'];
        $future['ends'] = ['kind' => 'after_months', 'value' => '5'];
        $future['components'][0]['amount'] = 11;
        $future['components'][0]['price_role'] = 'future';
        $future['components'][0]['normal_amount'] = null;
        $future['components'][0]['energy_rule']['normal_basis'] = null;
        $future['components'][0]['energy_rule']['starts'] = $future['starts'];
        $future['components'][0]['energy_rule']['ends'] = $future['ends'];
        $output['pricing']['phases'][] = $future;
        $result = $this->calculate($output, $this->projection(['2026-04' => 3.0, '2026-05' => 3.0, '2026-06' => 3.0]));
        $this->assertTrue($result->isListed());
        $this->assertEqualsWithDelta(12, $result->monthlyCosts[3], .00001);
        foreach (range(4, 11) as $month) {
            $this->assertEqualsWithDelta(11, $result->monthlyCosts[$month], .00001);
        }
        $this->assertTrue($result->energyRuleComparison->actualEstimated);
    }

    public function test_future_promotional_overlay_keeps_an_explicit_current_normal_reference(): void
    {
        $output = $this->fixtureOutput();
        $output['pricing']['phases'][1]['starts']['value'] = '6';
        $future = $output['pricing']['phases'][0];
        $future['phase_kind'] = 'future';
        $future['starts'] = ['kind' => 'after_months', 'value' => '4'];
        $future['ends'] = ['kind' => 'after_months', 'value' => '5'];
        $future['components'][0]['amount'] = 5;
        $future['components'][0]['energy_rule']['starts'] = $future['starts'];
        $future['components'][0]['energy_rule']['ends'] = $future['ends'];
        $output['pricing']['phases'][] = $future;
        $result = $this->calculate($output, $this->projection(['2026-04' => 3.0, '2026-05' => 3.0, '2026-06' => 3.0]));
        $this->assertTrue($result->isListed());
        $this->assertEqualsWithDelta(12, $result->monthlyCosts[3], 0.00001);
        $this->assertEqualsWithDelta(5, $result->monthlyCosts[4], 0.00001);
        $this->assertEqualsWithDelta(12, $result->baseMonthlyCosts[4], 0.00001);
        $this->assertEqualsWithDelta(12, $result->monthlyCosts[5], 0.00001);
        $future['components'][0]['price_role'] = 'future';
        $output['pricing']['phases'][2] = $future;
        $component = (new CanonicalPricingParser)->parse($output['pricing'], $output['calculation'], $output['source_consistency'], withEnergyRules: true)->phases[2]->components[0];
        $this->assertSame($component, $component->withoutEnergyOffer());
        $this->assertFalse($this->calculate($output)->isListed());
    }

    public function test_offer_removal_uses_own_normal_rule_and_fee_only_removal_retains_energy_lock(): void
    {
        $output = $this->fixtureOutput();
        $parser = new CanonicalPricingParser;
        $component = $parser->parse($output['pricing'], $output['calculation'], $output['source_consistency'], withEnergyRules: true)->phases[0]->components[0];
        $this->assertSame('adjustable_tariff', $component->withoutEnergyOffer()->energyRule->kind->value);
        $this->assertSame(9.0, $component->withoutEnergyOffer()->amount);
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis']['kind'] = 'fixed_price';
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis']['ends'] = ['kind' => 'after_months', 'value' => '12'];
        $fixedNormal = $this->calculate($output, $this->projection());
        $this->assertEqualsWithDelta(9, $fixedNormal->baseMonthlyCosts[1], 0.00001);
        $this->assertFalse($fixedNormal->energyRuleComparison->normalEstimated);
        $output = $this->fixtureOutput();
        $output['pricing']['phases'][0]['components'][0]['normal_amount'] = 4;
        $output['pricing']['phases'][1]['components'][0]['amount'] = 4;
        $output['pricing']['phases'][0]['components'][] = ['component_type' => 'monthly_fee', 'amount' => 0, 'normal_amount' => 4, 'unit' => 'eur_per_month', 'price_role' => 'introductory', 'energy_rule' => null];
        $result = $this->calculate($output, $this->projection(['2026-02' => 8.0], ['energy_general' => 4.0]));
        $this->assertEqualsWithDelta(4, $result->monthlyCosts[1], 0.00001);
        $this->assertEqualsWithDelta(8, $result->baseMonthlyCosts[1], 0.00001);
        $component = $parser->parse($output['pricing'], $output['calculation'], $output['source_consistency'], withEnergyRules: true)->phases[0]->components[0];
        $this->assertSame($component, $component->withoutEnergyOffer());
    }

    public function test_no_overflow_boundaries_and_percentage_vat_use_existing_billing(): void
    {
        $fixed = $this->fixtureOutput();
        $fixed['pricing']['phases'][0]['ends']['value'] = '1';
        $fixed['pricing']['phases'][0]['components'][0]['energy_rule']['ends']['value'] = '1';
        $fixed['pricing']['phases'][1]['starts']['value'] = '1';
        $result = $this->calculate($fixed, start: '2026-01-31');
        $this->assertEqualsWithDelta(4 * (1 / 31 + 27 / 28), $result->monthlyCosts[0], 0.00001);
        $percent = $this->calculate($this->fixtureOutput('percentage_discount', 9, 50),
            $this->projection(['2026-02' => 3 / 1.255], ['energy_general' => 9 / 1.255]),
            new ContractContext('FixedPrice', 'OpenEnded', 'General', null, 'Company'));
        $this->assertEqualsWithDelta(6 / 1.255, $percent->monthlyCosts[1], 0.00001);
        $floor = $this->calculate($this->fixtureOutput('absolute_discount', 9, 5, 1),
            $this->projection(['2026-02' => -7 / 1.255], ['energy_general' => 9 / 1.255]),
            new ContractContext('FixedPrice', 'OpenEnded', 'General', null, 'Company'));
        $this->assertEqualsWithDelta(1 / 1.255, $floor->monthlyCosts[1], 0.00001);
    }

    public function test_plain_fixed_terms_need_no_normal_tariff_or_futures(): void
    {
        foreach ([6, 12, 24] as $months) {
            $output = $this->plainFixed($months);
            $result = $this->calculate($output, context: new ContractContext('FixedPrice', 'FixedTerm', 'General', 'Fixed'.$months, 'Household'));
            $this->assertTrue($result->isListed());
            $this->assertEqualsWithDelta(108, $result->totalCost, 0.00001);
            $this->assertSame($result->monthlyCosts, $result->baseMonthlyCosts);
            $this->assertFalse($result->energyRuleComparison->actualEstimated);
            $this->assertFalse($result->energyRuleComparison->normalEstimated);
            $output['pricing']['phases'][0]['components'][] = ['component_type' => 'monthly_fee', 'amount' => 2, 'normal_amount' => 4, 'unit' => 'eur_per_month', 'price_role' => 'introductory', 'energy_rule' => null];
            $fees = $this->calculate($output, context: new ContractContext('FixedPrice', 'FixedTerm', 'General', 'Fixed'.$months, 'Household'));
            $this->assertEqualsWithDelta(132, $fees->totalCost, 0.00001);
            $this->assertEqualsWithDelta(156, array_sum($fees->baseMonthlyCosts), 0.00001);
            $this->assertFalse($fees->energyRuleComparison->normalEstimated);
        }
    }

    public function test_complete_fixed_actual_is_available_without_a_normal_counterfactual(): void
    {
        foreach ([6, 12] as $months) {
            $output = $this->plainFixed($months);
            $energy = &$output['pricing']['phases'][0]['components'][0];
            $energy['amount'] = 4;
            $energy['normal_amount'] = 9;
            $energy['price_role'] = 'introductory';
            $energy['energy_rule']['normal_basis'] = ['kind' => 'unknown', 'starts' => null, 'ends' => null, 'evidence' => []];
            $result = $this->calculate($output, context: new ContractContext('FixedPrice', 'FixedTerm', 'General', 'Fixed'.$months, 'Household'));
            $this->assertTrue($result->isListed());
            $this->assertEqualsWithDelta(48, $result->totalCost, 0.00001);
            $this->assertNull($result->baseTotalCost);
            $this->assertSame([], $result->baseMonthlyCosts);
            $this->assertSame([], $result->monthlyDiscountSavings);
            $this->assertFalse($result->energyRuleComparison->normalAvailable);
            $this->assertNull($result->energyRuleComparison->netDifference);
            $this->assertFalse($result->energyRuleComparison->actualEstimated);
            $this->assertContains('energy_rule_normal_unavailable', $result->assumptions);
            $this->assertNotContains('energy_rule_normal_known', $result->assumptions);
            $this->assertSame([], $result->offerTerms);
            if ($months === 6) {
                $this->assertEqualsWithDelta(24, $result->contractTermTotalCost, 0.00001);
                $this->assertNull($result->contractTermBaseTotalCost);
                $this->assertNull($result->contractTermDiscountSavingsTotal);
                $payload = $result->toCalculatedCostArray();
                $this->assertSame($payload, ContractPricingViewData::fromArray($payload)->toArray());
            } else {
                $payload = $result->toCalculatedCostArray();
                $this->assertFalse($payload['includes_discounts']);
                $this->assertSame($payload, ContractPricingViewData::fromArray($payload)->toArray());
            }
        }
    }

    public function test_complete_ordinary_fixed_changes_keep_actual_and_fee_only_comparison(): void
    {
        $output = $this->plainFixed(6);
        $future = $output['pricing']['phases'][0];
        $future['phase_kind'] = 'future';
        $future['starts'] = ['kind' => 'after_months', 'value' => '6'];
        $future['ends'] = ['kind' => 'after_months', 'value' => '12'];
        $future['components'][0]['amount'] = 11;
        $future['components'][0]['price_role'] = 'future';
        $future['components'][0]['energy_rule']['starts'] = $future['starts'];
        $future['components'][0]['energy_rule']['ends'] = $future['ends'];
        $output['pricing']['phases'][] = $future;
        $result = $this->calculate($output);
        $this->assertTrue($result->isListed());
        $this->assertEqualsWithDelta(120, $result->totalCost, 0.00001);
        $this->assertSame($result->monthlyCosts, $result->baseMonthlyCosts);
        $this->assertTrue($result->energyRuleComparison->normalAvailable);
        $this->assertFalse($result->energyRuleComparison->normalEstimated);
        $this->assertFalse($result->energyRuleComparison->actualEstimated);
        $projected = $this->calculate($output, $this->projection(['2026-07' => 20.0]));
        $this->assertSame($result->monthlyCosts, $projected->monthlyCosts);
        $this->assertSame($result->baseMonthlyCosts, $projected->baseMonthlyCosts);
        $this->assertNull($projected->energyRuleComparison->projection);
        $output['pricing']['phases'][0]['components'][] = ['component_type' => 'monthly_fee', 'amount' => 0, 'normal_amount' => 4, 'unit' => 'eur_per_month', 'price_role' => 'introductory', 'energy_rule' => null];
        $output['pricing']['phases'][1]['components'][] = ['component_type' => 'monthly_fee', 'amount' => 4, 'normal_amount' => null, 'unit' => 'eur_per_month', 'price_role' => 'normal', 'energy_rule' => null];
        $fees = $this->calculate($output);
        $this->assertEqualsWithDelta(144, $fees->totalCost, 0.00001);
        $this->assertEqualsWithDelta(168, $fees->baseTotalCost, 0.00001);
        $this->assertEqualsWithDelta(24, $fees->energyRuleComparison->netDifference, 0.00001);
        $output['pricing']['phases'][1]['components'][0]['energy_rule']['ends']['value'] = '11';
        $tail = $this->calculate($output);
        $this->assertTrue($tail->isListed());
        $this->assertEqualsWithDelta(11 + 4, $tail->monthlyCosts[11], .00001);
        $this->assertTrue($tail->energyRuleComparison->actualEstimated);
    }

    public function test_actual_only_fallback_keeps_complete_guarantee_and_source_guards(): void
    {
        $output = $this->plainFixed();
        $output['pricing']['phases'][0]['components'][0]['amount'] = 4;
        $output['pricing']['phases'][0]['components'][0]['normal_amount'] = 9;
        $output['pricing']['phases'][0]['components'][0]['price_role'] = 'introductory';
        $this->assertTrue($this->calculate($output)->isListed());
        $ordinary = $this->calculate($this->plainFixed(4));
        $this->assertTrue($ordinary->isListed());
        $this->assertTrue($ordinary->energyRuleComparison->actualEstimated);
        $this->assertSame('source_energy_rules_v1', $ordinary->estimateMethod->value);
        $conflict = $output;
        $conflict['pricing']['phases'][0]['components'][] = $conflict['pricing']['phases'][0]['components'][0];
        $conflict['pricing']['phases'][0]['components'][1]['amount'] = 5;
        $this->assertFalse($this->calculate($conflict)->isListed());
        $future = $output;
        $future['pricing']['phases'][0]['starts'] = ['kind' => 'after_months', 'value' => '1'];
        $this->assertFalse($this->calculate($future)->isListed());
        $time = $output;
        $time['pricing']['phases'][0]['components'][0]['component_type'] = 'energy_day';
        $this->assertFalse($this->calculate($time, context: new ContractContext('FixedPrice', 'OpenEnded', 'Time', null, 'Household'))->isListed());
        $night = $time['pricing']['phases'][0]['components'][0];
        $night['component_type'] = 'energy_night';
        $night['amount'] = 3;
        $time['pricing']['phases'][0]['components'][] = $night;
        $complete = $this->calculate($time, context: new ContractContext('FixedPrice', 'OpenEnded', 'Time', null, 'Household'));
        $this->assertTrue($complete->isListed());
        $this->assertNull($complete->baseTotalCost);
        $time['pricing']['phases'][0]['components'][1]['energy_rule']['ends']['value'] = '11';
        $this->assertFalse($this->calculate($time, context: new ContractContext('FixedPrice', 'OpenEnded', 'Time', null, 'Household'))->isListed());
        $output['pricing']['phases'][0]['components'][] = ['component_type' => 'monthly_fee', 'amount' => 4, 'normal_amount' => null, 'unit' => 'percent', 'price_role' => 'current', 'energy_rule' => null];
        $this->assertFalse($this->calculate($output)->isListed());
    }

    private function plainFixed(int $months = 12): array
    {
        $output = $this->fixtureOutput();
        $phase = &$output['pricing']['phases'][0];
        $phase['phase_kind'] = 'current_structured';
        $phase['ends'] = ['kind' => 'after_months', 'value' => (string) $months];
        $energy = &$phase['components'][0];
        $energy['amount'] = 9;
        $energy['normal_amount'] = null;
        $energy['price_role'] = 'current';
        $energy['energy_rule']['normal_basis'] = null;
        $energy['energy_rule']['ends'] = $phase['ends'];
        unset($output['pricing']['phases'][1]);
        $output['pricing']['phases'] = array_values($output['pricing']['phases']);

        return $output;
    }

    public function test_expired_actual_preserves_independent_normal_proof(): void
    {
        $output = $this->fixtureOutput();
        $expiry = ['kind' => 'date', 'value' => '2026-01-31'];
        $output['pricing']['phases'][0]['ends'] = $expiry;
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['starts'] = ['kind' => 'date', 'value' => '2026-01-01'];
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['ends'] = $expiry;
        $output['pricing']['phases'][1]['starts'] = ['kind' => 'date', 'value' => '2026-02-01'];
        $output['pricing']['phases'][1]['components'][0]['energy_rule'] = null;
        $before = $this->calculate($output);
        $this->assertEqualsWithDelta(4, $before->monthlyCosts[0], 0.00001);
        $after = $this->calculate($output, start: '2026-02-01');
        $this->assertTrue($after->isListed());
        $this->assertEqualsWithDelta(108, $after->totalCost, 0.00001);
        $ongoing = $output;
        $ongoing['pricing']['phases'][0]['components'][0]['energy_rule']['ends'] = ['kind' => 'date', 'value' => '2027-01-31'];
        $this->assertFalse($this->calculate($ongoing, start: '2026-02-01')->isListed());
        $output['pricing']['phases'][1]['components'][0]['amount'] = 11;
        $changed = $this->calculate($output, start: '2026-02-01');
        $this->assertTrue($changed->isListed());
        $this->assertEqualsWithDelta(11, $changed->monthlyCosts[0], .00001);
    }

    public function test_expired_fee_phase_keeps_its_ongoing_energy_guarantee(): void
    {
        $output = $this->plainFixed();
        $output['pricing']['phases'][0]['ends'] = ['kind' => 'date', 'value' => '2026-01-31'];
        $fee = ['component_type' => 'monthly_fee', 'amount' => 0, 'normal_amount' => 4, 'unit' => 'eur_per_month', 'price_role' => 'introductory', 'energy_rule' => null];
        $output['pricing']['phases'][0]['components'][] = $fee;
        $next = $output['pricing']['phases'][0];
        $next['phase_kind'] = 'normal';
        $next['starts'] = ['kind' => 'date', 'value' => '2026-02-01'];
        $next['ends'] = ['kind' => 'none', 'value' => null];
        $fee['amount'] = 4;
        $fee['normal_amount'] = null;
        $fee['price_role'] = 'normal';
        $next['components'] = [$fee];
        $output['pricing']['phases'][] = $next;
        $before = $this->calculate($output);
        $this->assertEqualsWithDelta(9, $before->monthlyCosts[0], 0.00001);
        $this->assertEqualsWithDelta(13, $before->baseMonthlyCosts[0], 0.00001);
        $after = $this->calculate($output, start: '2026-02-01');
        $this->assertTrue($after->isListed());
        $this->assertEqualsWithDelta(156, $after->totalCost, 0.00001);
        $this->assertFalse($after->energyRuleComparison->actualEstimated);
        $repeated = $output;
        $repeated['pricing']['phases'][1]['components'][] = $output['pricing']['phases'][0]['components'][0];
        $this->assertSame($before->monthlyCosts, $this->calculate($repeated)->monthlyCosts);
        $this->assertSame($after->monthlyCosts, $this->calculate($repeated, start: '2026-02-01')->monthlyCosts);
        $changed = $output;
        $energy = $output['pricing']['phases'][0]['components'][0];
        $energy['amount'] = 11;
        $energy['energy_rule'] = null;
        $changed['pricing']['phases'][1]['components'][] = $energy;
        $this->assertFalse($this->calculate($changed, start: '2026-02-01')->isListed());
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['starts'] = ['kind' => 'date', 'value' => '2026-01-01'];
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['ends'] = ['kind' => 'date', 'value' => '2026-01-31'];
        $this->assertFalse($this->calculate($output, start: '2026-02-01')->isListed());
    }

    public function test_shorter_guarantee_cannot_end_the_remaining_billed_promotion(): void
    {
        $output = $this->fixtureOutput();
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['ends']['value'] = '2';
        $this->assertFalse($this->calculate($output)->isListed());
        $rest = $output['pricing']['phases'][0];
        $rest['starts'] = ['kind' => 'after_months', 'value' => '2'];
        $rest['components'][0]['energy_rule']['starts'] = $rest['starts'];
        $rest['components'][0]['energy_rule']['ends'] = $rest['ends'];
        $output['pricing']['phases'][] = $rest;
        $covered = $this->calculate($output);
        $this->assertTrue($covered->isListed());
        $this->assertEqualsWithDelta(4, $covered->monthlyCosts[2], 0.00001);
    }

    public function test_future_parent_does_not_supply_an_earlier_independent_guarantee(): void
    {
        $output = $this->plainFixed();
        $output['pricing']['phases'][0]['phase_kind'] = 'future';
        $output['pricing']['phases'][0]['starts'] = ['kind' => 'after_months', 'value' => '1'];
        $feePhase = $output['pricing']['phases'][0];
        $feePhase['phase_kind'] = 'current_structured';
        $feePhase['starts'] = ['kind' => 'contract_start', 'value' => null];
        $feePhase['ends'] = ['kind' => 'after_months', 'value' => '1'];
        $feePhase['components'] = [['component_type' => 'monthly_fee', 'amount' => 4, 'normal_amount' => null, 'unit' => 'eur_per_month', 'price_role' => 'current', 'energy_rule' => null]];
        $output['pricing']['phases'][] = $feePhase;
        $this->assertFalse($this->calculate($output)->isListed());
    }

    public function test_unknown_promotional_normal_is_not_a_no_offer_fixed_tariff(): void
    {
        foreach (['introductory', 'current'] as $role) {
            $output = $this->fixtureOutput();
            $output['pricing']['phases'][0]['components'][0]['price_role'] = $role;
            $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis'] = null;
            $actualOnly = $this->calculate($output);
            $this->assertTrue($actualOnly->isListed());
            $this->assertFalse($actualOnly->energyRuleComparison->normalAvailable);
            $this->assertEqualsWithDelta(9, $actualOnly->monthlyCosts[3], .00001);
        }
        $output['pricing']['phases'][0]['components'][0]['price_role'] = 'introductory';
        $output['pricing']['phases'][0]['components'][0]['normal_amount'] = null;
        $this->assertTrue($this->calculate($output)->isListed());
        array_pop($output['pricing']['phases']);
        $this->assertFalse($this->calculate($output)->isListed());
    }

    public function test_new_dated_normal_quote_can_replace_an_expired_normal_lock_without_reusing_the_old_quote(): void
    {
        $output = $this->fixtureOutput();
        $past = $output['pricing']['phases'][0];
        $past['starts'] = $past['components'][0]['energy_rule']['starts'] = ['kind' => 'date', 'value' => '2025-10-01'];
        $past['ends'] = $past['components'][0]['energy_rule']['ends'] = ['kind' => 'date', 'value' => '2025-12-31'];
        $past['components'][0]['normal_amount'] = 12;
        $past['components'][0]['energy_rule']['normal_basis']['kind'] = 'fixed_price';
        $past['components'][0]['energy_rule']['normal_basis']['starts'] = $past['starts'];
        $past['components'][0]['energy_rule']['normal_basis']['ends'] = $past['ends'];
        $output['pricing']['phases'][] = $past;
        $context = new ContractContext('FixedPrice', 'OpenEnded', 'General', null, 'Household');
        $start = CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki');
        $parser = new CanonicalPricingParser;
        $extractor = new CurrentNormalCandidateExtractor;
        $old = $parser->parse($output['pricing'], $output['calculation'], $output['source_consistency'], withEnergyRules: true);
        $this->assertNull($extractor->candidate('target', $old, $context, $start));
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis']['starts'] = ['kind' => 'date', 'value' => '2026-01-01'];
        $new = $parser->parse($output['pricing'], $output['calculation'], $output['source_consistency'], withEnergyRules: true);
        $this->assertSame(['energy_general' => 9.0], $extractor->candidate('target', $new, $context, $start)->energyRates);
    }

    public function test_period_uses_own_energy_bounds_across_fee_phases_without_annual_forecasts(): void
    {
        foreach (['fixed_price', 'absolute_discount'] as $kind) {
            $output = $this->fixtureOutput($kind);
            $output['pricing']['phases'][0]['ends']['value'] = '1';
            $fee = ['component_type' => 'monthly_fee', 'amount' => 0, 'normal_amount' => 6, 'unit' => 'eur_per_month', 'price_role' => 'introductory', 'energy_rule' => null];
            $output['pricing']['phases'][0]['components'][] = $fee;
            $fee['amount'] = 6;
            $fee['normal_amount'] = null;
            $fee['price_role'] = 'normal';
            $output['pricing']['phases'][1]['starts']['value'] = '1';
            $output['pricing']['phases'][1]['components'] = [$fee];
            $data = (new CanonicalPricingParser)->parse($output['pricing'], $output['calculation'], $output['source_consistency'], withEnergyRules: true);
            $context = new ContractContext('FixedPrice', 'OpenEnded', 'General', null, 'Household');
            $start = CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki');
            $end = $start->addMonths(4);
            $calculator = HoldFlatCanonicalCalculator::make();
            $spot = new SpotAssumptions(null, null);
            $annual = $calculator->calculate($data, $context, new EnergyUsage(total: 1200, basicLiving: 1200), $spot, $start, normalEnergyProjection: $this->projection());
            $period = $calculator->calculatePeriod($data, $context, new CanonicalPeriodPricingRequest($start, $end->subDay(), 200, 1200, []), $spot, $annual);
            $this->assertTrue($period->isAvailable());
            $firstHours = $start->utc()->diffInHours($start->addMonths(3)->utc());
            $hours = $start->utc()->diffInHours($end->utc());
            $energy = 2 * (4 * $firstHours + 9 * ($hours - $firstHours)) / $hours;
            $this->assertEqualsWithDelta($energy + 6 * 89 / 30, $period->periodTotal, .00001);
            $this->assertEqualsWithDelta(42, $period->normalPeriodTotal, .00001);
            $this->assertSame(4.0, $period->phaseBreakdown[1]['energy_cents']);
            $this->assertSame(9.0, $period->phaseBreakdown[3]['energy_cents']);
            $this->assertSame($kind === 'fixed_price', $period->phaseBreakdown[1]['energy_price_guaranteed']);
            $this->assertContains('energy_rule_period_uses_published_rates_without_forecast', $period->assumptions);
            $this->assertNotContains('energy_rule_normal_projection', $period->assumptions);
            $this->assertNotContains('held_current_price_forward', $period->assumptions);
        }
    }

    public function test_post_year_unknown_normal_is_outside_the_plan(): void
    {
        $output = $this->fixtureOutput();
        $future = $output['pricing']['phases'][1];
        $future['starts'] = ['kind' => 'after_months', 'value' => '12'];
        $future['components'][0]['amount'] = 11;
        $future['components'][0]['energy_rule'] = null;
        $output['pricing']['phases'][] = $future;
        $this->assertTrue($this->calculate($output)->isListed());
    }

    public function test_default_parser_unknown_rules_keep_existing_current_behavior(): void
    {
        $output = $this->fixtureOutput();
        $data = (new CanonicalPricingParser)->parse($output['pricing'], $output['calculation'], $output['source_consistency']);
        $args = [$data, new ContractContext('FixedPrice', 'OpenEnded', 'General', null, 'Household'), new EnergyUsage(total: 1200, basicLiving: 1200), new SpotAssumptions(null, null), CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki')];
        $calculator = HoldFlatCanonicalCalculator::make();
        $before = $calculator->calculate(...$args);
        $after = $calculator->calculate(...$args, normalEnergyProjection: $this->projection());
        $this->assertSame($before->toCalculatedCostArray(), $after->toCalculatedCostArray());
        $this->assertNull($after->energyRuleComparison);
    }

    public function test_historical_ignores_rule_kernel_and_injected_projection(): void
    {
        $output = $this->fixtureOutput();
        $a = $this->calculate($output, policy: ComparisonPolicy::Historical);
        $b = $this->calculate($output, $this->projection(), policy: ComparisonPolicy::Historical);
        $this->assertSame($a->toCalculatedCostArray(), $b->toCalculatedCostArray());
        $this->assertNull($a->energyRuleComparison);
    }
}
