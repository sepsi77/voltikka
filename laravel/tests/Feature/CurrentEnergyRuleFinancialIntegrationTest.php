<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\CurrentSourcePromotionEvidence;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingRequest;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\ContractInterpretation\ContractInterpretationInputBuilder;
use App\Services\ContractInterpretation\ContractInterpretationValidator;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\EnergyRulesFixture as F;
use Tests\Support\FullSourceEnergyFixture;
use Tests\TestCase;

class CurrentEnergyRuleFinancialIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private EnergyRuleFinancialCurve $curve;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-15 12:00:00 Europe/Helsinki');
        Http::preventStrayRequests();
        Http::fake();
        config()->set('canonical_pricing.enabled', true);
        $this->app->forgetScopedInstances();
        $this->curve = new EnergyRuleFinancialCurve;
        $this->app->instance(MarketReferenceCurveProvider::class, $this->curve);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_real_service_fixed_and_formula_use_normal_nine_and_real_fee_on_all_paths(): void
    {
        $fixed = $this->contract('fixed');
        $formula = $this->contract('formula', 'absolute_discount');
        $service = $this->service();
        $a = $service->evaluate($fixed, $this->usage(), startDate: $this->date())['outcome'];
        $b = $service->evaluate($formula, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertTrue($a->isListed());
        $this->assertTrue($b->isListed());
        $this->assertTrue($a->phaseBreakdown[0]['energy_price_guaranteed']);
        $this->assertFalse($a->phaseBreakdown[1]['energy_price_guaranteed']);
        $this->assertFalse($b->phaseBreakdown[0]['energy_price_guaranteed']);
        $projection = $b->energyRuleComparison->projection;
        $this->assertSame(['energy_general' => 9.0], $projection->currentRates);
        $this->assertSame(9.0, $projection->estimate->currentEnergyPriceCentsPerKwh);
        $this->assertSame(6.0, $projection->estimate->monthlyFeeEur);
        $this->assertSame('2026-09-01', $projection->estimate->priceEpisodeAnchor->startedAt->toDateString());
        $this->assertSame(3.0, $projection->offset($this->date()->addMonth(), 'energy_general'));
        $this->assertEqualsWithDelta(3, $b->monthlyCosts[1] - $a->monthlyCosts[1], .00001);
        $this->assertEqualsWithDelta(12 + 6, $b->baseMonthlyCosts[1], .00001);
        $this->assertSame('source_energy_rules_v1', $b->estimateMethod->value);
        $this->assertNull($b->supplierAdjustedEstimate);
        $this->assertNull($b->resetEstimate);
        $metrics = $service->metricsForContracts(collect([$fixed, $formula]), $this->usage(), $this->date());
        $this->assertEquals($b->totalCost, $metrics['formula']->pricing()->toArray()['total_cost']);
        $stats = $service->outcomesForContractsAtConsumptions(collect([$formula]), [1200, 2400], new SpotAssumptions(null, null), $this->date());
        $this->assertEquals($b->totalCost, $stats['formula'][1200]->totalCost);
        $this->assertEqualsWithDelta(2 * ($b->totalCost - 72) + 72, $stats['formula'][2400]->totalCost, .00001);
        $period = $service->periodEvaluationsForContracts(collect([$formula]), new CanonicalPeriodPricingRequest($this->date(), $this->date()->endOfMonth(), 100, 1200, []), new SpotAssumptions(null, null))['formula'];
        $this->assertEquals($b->totalCost, $period['annual']->totalCost);
        $this->assertEqualsWithDelta(4 + 6, $period['period']->periodTotal, .00001);
        Http::assertNothingSent();
    }

    public function test_new_current_offer_can_be_compared_against_a_past_bill_without_projection_in_the_bill(): void
    {
        $contract = $this->contract('new', 'absolute_discount', observed: '2026-09-15');
        $start = $this->date()->subMonths(2);
        $result = $this->service()->periodEvaluationsForContracts(collect([$contract]), new CanonicalPeriodPricingRequest($start, $start->endOfMonth(), 100, 1200, []), new SpotAssumptions(null, null))['new'];
        $this->assertNotNull($result['annual']->energyRuleComparison);
        $this->assertTrue($result['annual']->isListed());
        $this->assertEqualsWithDelta(4 + 6 * 31 / 30, $result['period']->periodTotal, .00001);
    }

    public function test_missing_own_reference_uses_proved_normal_peer_not_its_promotional_price(): void
    {
        $target = $this->contract('target', 'absolute_discount', observed: '2026-02-01');
        $this->contract('peer', observed: '2026-08-01');
        $this->curve->missingMonths = [2];
        $result = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $estimate = $result->energyRuleComparison->projection->estimate;
        $this->assertSame('forward_premium', $estimate->basis->value);
        $this->assertSame('same_company', $estimate->premium->source->value);
        $this->assertSame(['energy_general' => 4.0], $estimate->premium->premiumsByBucket);
        $this->assertEqualsWithDelta(7 + 6, $result->monthlyCosts[1], .00001);
    }

    public function test_forged_source_rule_never_enables_the_energy_kernel(): void
    {
        $contract = $this->contract('forged');
        $output = ContractInterpretation::findOrFail($contract->published_interpretation_id)->output;
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['kind'] = 'absolute_discount';
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['discount_value'] = 5;
        $contract->update(['canonical_pricing' => $output['pricing']]);
        $this->assertFalse((new CurrentSourcePromotionEvidence)->forContracts(collect([$contract]), $this->date())['forged']['energy_rules_valid']);
        $result = $this->service()->evaluate($contract, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertFalse($result->isListed());
        $this->assertNull($result->energyRuleComparison);
        ContractInterpretation::whereKey($contract->published_interpretation_id)->update(['output' => $output]);
        $this->assertFalse($this->service()->evaluate($contract, $this->usage(), startDate: $this->date())['outcome']->isListed());
        $contract->update(['current_source_observation_id' => null, 'published_interpretation_id' => null]);
        $this->assertFalse($this->service()->evaluate($contract, $this->usage(), startDate: $this->date())['outcome']->isListed());
    }

    public function test_own_reference_keeps_peer_queries_lazy_and_batch_counts_flat_at_one_eight_and_thirty_two(): void
    {
        $counts = [];
        $contracts = collect();
        for ($i = 1; $i <= 32; $i++) {
            $contracts->push($this->contract('batch-'.$i, 'absolute_discount'));
            if (! in_array($i, [1, 8, 32], true)) {
                continue;
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $results = $this->service()->outcomesForContractsAtConsumptions($contracts, [1200, 2400, 5000], new SpotAssumptions(null, null), $this->date());
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $counts[] = count($queries);
            $this->assertCount($i, $results);
            foreach ($queries as $query) {
                $this->assertStringNotContainsString('premium_observation', $query['query']);
            }
        }
        $this->assertSame($counts[0], $counts[1]);
        $this->assertSame($counts[0], $counts[2]);
        $this->assertSame([5, 5, 5], $counts);
    }

    public function test_real_full_source_cheap_costs_announced_continuation_without_inventing_normal_proof(): void
    {
        $contract = $this->contract('cheap', example: FullSourceEnergyFixture::cheap());
        $result = $this->service()->evaluate($contract, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertTrue($result->isListed());
        $this->assertFalse($result->energyRuleComparison->normalAvailable);
        $this->assertNull($result->baseTotalCost);
        $this->assertNull($result->energyRuleComparison->projection);
        $this->assertNull($result->energyRuleComparison->currentNormalRates);
        $this->assertContains('energy_rule_latest_known_price_continuation', $result->assumptions);
        $this->assertEqualsWithDelta(7.49, $result->monthlyCosts[0], .00001);
        $this->assertEqualsWithDelta(9.95 + 4.9, $result->monthlyCosts[1], .00001);
        $this->assertEqualsWithDelta(7.49 + 11 * (9.95 + 4.9), $result->totalCost, .00001);
        $this->assertSame('source_energy_rules_v1', $result->estimateMethod->value);
        $this->assertFalse($result->toCalculatedCostArray()['includes_discounts']);
        $period = $this->service()->periodEvaluationsForContracts(collect([$contract]), new CanonicalPeriodPricingRequest($this->date(), $this->date()->endOfMonth(), 100, 1200, []), new SpotAssumptions(null, null))['cheap']['period'];
        $this->assertEqualsWithDelta(7.49, $period->periodTotal, .00001);
        $this->assertNull($period->normalPeriodTotal);
        $this->assertSame(0.0, $period->measuredDiscountSavings);
        $this->assertNotContains('energy_rule_latest_known_price_continuation', $period->assumptions);
    }

    public function test_wholly_fixed_full_source_term_does_not_query_forecasts(): void
    {
        $contract = $this->contract('whole', example: FullSourceEnergyFixture::wholeTerm());
        $result = $this->service()->evaluate($contract, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertTrue($result->isListed());
        $this->assertFalse($result->isEstimate());
        $this->assertNull($result->energyRuleComparison->projection);
        $this->assertSame(0, $this->curve->queries);
        $this->assertEqualsWithDelta(108, $result->totalCost, .00001);
    }

    public function test_source_authorized_percentage_zero_hundred_and_floors_use_existing_forecast(): void
    {
        foreach ([['percentage_discount', 9, 0, null, 12], ['percentage_discount', 9, 50, null, 6], ['percentage_discount', 9, 100, null, 0], ['percentage_discount', 0, 50, null, 1.5], ['absolute_discount', 9, 5, 3, 3]] as $i => [$kind, $normal, $operand, $floor, $expected]) {
            $this->curve->forwardRate = $floor === null ? 8.0 : 0.0;
            $contract = $this->contract('operator-'.$i, example: F::example($kind, $normal, $operand, $floor));
            $result = $this->service()->evaluate($contract, $this->usage(), startDate: $this->date())['outcome'];
            $this->assertTrue($result->isListed());
            $this->assertEqualsWithDelta($expected, $result->monthlyCosts[1], .00001);
            $this->assertSame((float) $normal, $result->energyRuleComparison->projection->currentRates['energy_general']);
        }
    }

    public function test_fixed_normal_target_uses_peer_premium_but_is_not_its_own_monthly_reference(): void
    {
        [$input, $output, $source] = F::example();
        $old = 'General normal energy tariff is adjustable and is currently 9 cents/kWh';
        $quote = 'General normal energy price is fixed at 9 cents/kWh for the first 3 months';
        $source['Details']['ShortDescription'] = str_replace($old, $quote, $source['Details']['ShortDescription']);
        $basis = &$output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis'];
        $basis['kind'] = 'fixed_price';
        $basis['ends'] = ['kind' => 'after_months', 'value' => '3'];
        $basis['evidence'] = [...F::scope(), F::citation('short_description', $quote)];
        unset($basis);
        $target = $this->contract('fixed-normal', example: [$input, $output, $source]);
        $this->contract('ordinary-normal-peer', observed: '2026-08-01');
        $result = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $estimate = $result->energyRuleComparison->projection->estimate;
        $this->assertNull($estimate->priceEpisodeAnchor->startedAt);
        $this->assertSame('forward_premium', $estimate->basis->value);
        $this->assertSame(1, $estimate->premium->observationCount);
        $this->assertEqualsWithDelta(9, $result->baseMonthlyCosts[1], .00001);
        $this->assertEqualsWithDelta(12, $result->monthlyCosts[3], .00001);
        $this->assertTrue($result->phaseBreakdown[0]['energy_price_guaranteed']);

        $actual = 'General energy price is the normal tariff minus 5 cents/kWh for the first 3 months';
        $source['Details']['ShortDescription'] = str_replace('General energy price is fixed at 4 cents/kWh for the first 3 months', $actual, $source['Details']['ShortDescription']);
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['kind'] = 'absolute_discount';
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['discount_value'] = 5;
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['evidence'][2]['quote'] = $actual;
        $formula = $this->contract('normal-fixed-formula', example: [$input, $output, $source]);
        $formulaResult = $this->service()->evaluate($formula, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertEqualsWithDelta(4, $formulaResult->monthlyCosts[0], .00001);
        $this->assertFalse($formulaResult->phaseBreakdown[0]['energy_price_guaranteed']);
    }

    public function test_reset_normal_reprices_on_cadence_not_actual_guarantee_end_for_each_tariff_and_vat(): void
    {
        config()->set('canonical_pricing.reset_forward_shift.enabled', true);
        $this->app->forgetScopedInstances();
        $this->app->instance(MarketReferenceCurveProvider::class, $this->curve);
        foreach (['General', 'Time', 'Season'] as $metering) {
            foreach (['Household', 'Company'] as $audience) {
                [$input, $output, $source] = $this->tariffExample($metering);
                $source['Details']['TargetGroup'] = $audience;
                $output['calculation']['status'] = 'estimate_required';
                $output['classification']['pricing_mechanisms'][] = 'periodic_market_reset';
                $output['classification']['periodic_reset_cadence'] = 'monthly';
                $output['classification']['schedule_kinds'][] = 'recurring_market_reset';
                $output['pricing']['recurring_schedule'] = ['present' => true, 'cadence' => 'monthly', 'current_period_start' => '2026-09-01', 'current_period_end' => '2026-09-30', 'future_price_known' => false, 'description' => null, 'evidence' => []];
                $contract = $this->contract('reset-'.$metering.'-'.$audience, example: [$input, $output, $source]);
                $result = $this->service()->evaluate($contract, $this->usage(), startDate: $this->date())['outcome'];
                $this->assertTrue($result->isListed());
                $projection = $result->energyRuleComparison->projection;
                $this->assertSame('2026-10-01', $projection->tail()->toDateString());
                $multiplier = $audience === 'Company' ? 1 / 1.255 : 1;
                $normalScalar = match ($metering) {
                    'General' => 9, 'Time' => 8.55, 'Season' => 7.0625
                };
                $this->assertEqualsWithDelta($normalScalar * $multiplier, $projection->estimate->currentPeriodEnergyPriceCentsPerKwh, .00001);
                $this->assertCount($metering === 'General' ? 1 : 2, $projection->currentRates);
                $this->assertEqualsWithDelta(4 * $multiplier, $result->monthlyCosts[1], .00001);
                $this->assertGreaterThan($result->baseMonthlyCosts[0], $result->baseMonthlyCosts[1]);
                $this->assertEqualsWithDelta(4 * $multiplier, $result->monthlyCosts[2], .00001);
                $this->assertEqualsWithDelta($result->baseMonthlyCosts[3], $result->monthlyCosts[3], .00001);
            }
        }
    }

    public function test_six_month_hybrid_uses_normal_forecast_and_keeps_real_term_and_actual_certainty_separate(): void
    {
        [$input, $output, $source] = F::example();
        $source['Details']['ContractType'] = 'FixedTerm';
        $source['Details']['FixedTimeRange'] = 'Fixed6';
        $source['Details']['PricingModel'] = 'Hybrid';
        $source['Details']['ShortDescription'] = str_replace('first 3 months', 'first 6 months', $source['Details']['ShortDescription']);
        $source['Details']['Pricing']['PriceComponents'][0]['Discount']['NfirstMonths'] = 6;
        $output['classification']['term_type'] = $output['source_consistency']['recommended_contract_type'] = 'FixedTerm';
        $output['classification']['fixed_duration_months'] = 6;
        $output['classification']['primary_pricing_model'] = $output['source_consistency']['recommended_pricing_model'] = 'Hybrid';
        $output['classification']['pricing_mechanisms'][] = 'consumption_effect';
        $output['pricing']['consumption_effect']['present'] = true;
        $output['pricing']['consumption_effect']['applies_to'] = 'base_contract';
        $output['calculation']['status'] = 'unsupported';
        $output['pricing']['phases'][0]['ends']['value'] = '6';
        $output['pricing']['phases'][1]['starts']['value'] = '6';
        $rule = &$output['pricing']['phases'][0]['components'][0]['energy_rule'];
        $rule['ends']['value'] = '6';
        $rule['evidence'][2]['quote'] = str_replace('first 3 months', 'first 6 months', $rule['evidence'][2]['quote']);
        unset($rule);
        foreach ($output['pricing']['phases'][0]['components'][0]['evidence'] as &$citation) {
            if ($citation['source'] === 'components[0].discount_n_first_months') {
                $citation['quote'] = '6';
            }
        }
        unset($citation);
        $contract = $this->contract('hybrid-six', example: [$input, $output, $source]);
        $peerSource = $source;
        $peerSource['Details']['ContractType'] = 'OpenEnded';
        unset($peerSource['Details']['FixedTimeRange']);
        $peerOutput = $output;
        $peerOutput['classification']['term_type'] = $peerOutput['source_consistency']['recommended_contract_type'] = 'OpenEnded';
        $peerOutput['classification']['fixed_duration_months'] = null;
        $this->contract('hybrid-peer', observed: '2026-08-01', example: [$input, $peerOutput, $peerSource]);
        $result = $this->service()->evaluate($contract, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertTrue($result->isListed());
        $this->assertEqualsWithDelta(24, $result->contractTermTotalCost, .00001);
        $this->assertEqualsWithDelta(48, $result->totalCost, .00001);
        $this->assertFalse($result->energyRuleComparison->actualEstimated);
        $this->assertTrue($result->energyRuleComparison->normalEstimated);
        $this->assertNotNull($result->energyRuleComparison->projection);
        $this->assertSame('forward_premium', $result->energyRuleComparison->projection->estimate->basis->value);
        $this->assertEqualsWithDelta(138, $result->baseTotalCost, .00001);
        $this->assertSame('hybrid_base_only', $result->estimateMethod->value);
    }

    public function test_normal_energy_episode_crosses_trusted_id_and_fee_changes_without_reanchoring(): void
    {
        $old = $this->contract('old-id', observed: '2026-08-01');
        $publication = ContractInterpretation::findOrFail($old->published_interpretation_id);
        $output = $publication->output;
        $source = ContractSourceSnapshot::findOrFail($publication->source_snapshot_id)->source_payload;
        $source['Details']['Pricing']['PriceComponents'][1]['OriginalPayment']['Price'] = 9;
        foreach ($output['pricing']['phases'] as &$phase) {
            $phase['components'][1]['amount'] = 9;
            $phase['components'][1]['evidence'] = [F::citation('components[1].price', 9)];
        }
        unset($phase);
        $current = $this->contract('new-id', example: [[], $output, $source]);
        $old->update(['replaced_by_contract_id' => $current->id]);
        ContractSourceObservation::whereKey($old->current_source_observation_id)->update(['last_observed_at' => '2026-08-31 20:00:00']);
        $result = $this->service()->evaluate($current, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('2026-08-01', $result->energyRuleComparison->projection->estimate->priceEpisodeAnchor->startedAt->toDateString());
        $this->assertSame(9.0, $result->energyRuleComparison->projection->estimate->monthlyFeeEur);
        $this->assertEqualsWithDelta(13, $result->monthlyCosts[1], .00001);
    }

    public function test_expired_absolute_offer_preserves_normal_nine_without_extending_actual_four(): void
    {
        [$input, $output, $source] = F::example();
        $source['Details']['ShortDescription'] = str_replace('for the first 3 months', 'from 2026-08-01 through 2026-08-31', $source['Details']['ShortDescription']);
        $source['Details']['Pricing']['PriceComponents'][0]['Discount']['DiscountType'] = 'UntilDate';
        $source['Details']['Pricing']['PriceComponents'][0]['Discount']['UntilDate'] = '2026-08-31';
        $first = &$output['pricing']['phases'][0];
        $first['starts'] = $first['components'][0]['energy_rule']['starts'] = ['kind' => 'date', 'value' => '2026-08-01'];
        $first['ends'] = $first['components'][0]['energy_rule']['ends'] = ['kind' => 'date', 'value' => '2026-08-31'];
        $first['components'][0]['energy_rule']['evidence'][2]['quote'] = explode(';', $source['Details']['ShortDescription'])[0];
        foreach ($first['components'][0]['evidence'] as &$citation) {
            if ($citation['source'] === 'components[0].discount_type') {
                $citation['quote'] = 'UntilDate';
            } elseif ($citation['source'] === 'components[0].discount_n_first_months') {
                $citation = F::citation('components[0].discount_until_date', '2026-08-31');
            }
        }
        unset($first, $citation);
        $output['pricing']['phases'][1]['starts'] = ['kind' => 'date', 'value' => '2026-09-01'];
        $contract = $this->contract('expired', observed: '2026-08-01', example: [$input, $output, $source]);
        $result = $this->service()->evaluate($contract, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertTrue($result->isListed());
        $this->assertEqualsWithDelta(9, $result->monthlyCosts[0], .00001);
        $this->assertEqualsWithDelta(12, $result->monthlyCosts[1], .00001);
        $this->assertEqualsWithDelta(0, $result->energyRuleComparison->netDifference, .00001);
        $this->assertSame([], $result->offerTerms);
    }

    public function test_transferred_normal_peers_keep_batch_query_counts_flat_with_repeated_consumptions(): void
    {
        $this->curve->missingMonths = [2];
        $this->contract('donor', observed: '2026-08-01');
        $contracts = collect();
        $counts = [];
        for ($i = 1; $i <= 32; $i++) {
            $contracts->push($this->contract('transfer-'.$i, 'absolute_discount', '2026-02-01'));
            if (! in_array($i, [1, 8, 32], true)) {
                continue;
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $results = $this->service()->outcomesForContractsAtConsumptions($contracts, [1200, 2400], new SpotAssumptions(null, null), $this->date());
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertSame('forward_premium', $results['transfer-'.$i][1200]->energyRuleComparison->projection->estimate->basis->value);
        }
        $this->assertSame($counts[0], $counts[1]);
        $this->assertSame($counts[0], $counts[2]);
        $this->assertSame([13, 13, 13], $counts);
    }

    public function test_proved_future_eleven_wins_and_its_unknown_tail_never_returns_to_old_nine(): void
    {
        [$input, $output, $source] = F::example();
        $source['Details']['ShortDescription'] = str_replace('for the first 3 months', 'from 2026-09-01 through 2026-11-30', $source['Details']['ShortDescription']);
        $source['Details']['Pricing']['PriceComponents'][0]['Discount']['DiscountType'] = 'UntilDate';
        $source['Details']['Pricing']['PriceComponents'][0]['Discount']['UntilDate'] = '2026-11-30';
        $first = &$output['pricing']['phases'][0];
        $first['starts'] = $first['components'][0]['energy_rule']['starts'] = ['kind' => 'date', 'value' => '2026-09-01'];
        $first['ends'] = $first['components'][0]['energy_rule']['ends'] = ['kind' => 'date', 'value' => '2026-11-30'];
        $first['components'][0]['energy_rule']['evidence'][2]['quote'] = explode(';', $source['Details']['ShortDescription'])[0];
        foreach ($first['components'][0]['evidence'] as &$citation) {
            if ($citation['source'] === 'components[0].discount_type') {
                $citation['quote'] = 'UntilDate';
            } elseif ($citation['source'] === 'components[0].discount_n_first_months') {
                $citation = F::citation('components[0].discount_until_date', '2026-11-30');
            }
        }
        unset($first, $citation);
        $output['pricing']['phases'][1]['starts'] = ['kind' => 'date', 'value' => '2026-12-01'];
        $quote = 'General energy price is fixed at 11 cents/kWh from 2027-01-01 through 2027-01-31';
        $source['Details']['ShortDescription'] .= '; '.$quote;
        $future = $output['pricing']['phases'][1];
        $future['phase_kind'] = 'future';
        $future['starts'] = ['kind' => 'date', 'value' => '2027-01-01'];
        $future['ends'] = ['kind' => 'date', 'value' => '2027-01-31'];
        $future['components'][0]['amount'] = 11;
        $future['components'][0]['price_role'] = 'future';
        $future['components'][0]['evidence'] = [F::citation('short_description', $quote)];
        $future['components'][0]['energy_rule'] = ['kind' => 'fixed_price', 'starts' => $future['starts'], 'ends' => $future['ends'],
            'discount_value' => null, 'floor_amount' => null, 'normal_basis' => null, 'evidence' => [...F::scope(), F::citation('short_description', $quote)]];
        $output['pricing']['phases'][] = $future;
        $contract = $this->contract('future-eleven', example: [$input, $output, $source]);
        $result = $this->service()->evaluate($contract, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertTrue($result->isListed());
        $this->assertEqualsWithDelta(12, $result->monthlyCosts[3], .00001);
        foreach (range(4, 11) as $month) {
            $this->assertEqualsWithDelta(11, $result->monthlyCosts[$month], .00001);
        }
        $this->assertSame('2026-09-01', $result->energyRuleComparison->projection->estimate->priceEpisodeAnchor->startedAt->toDateString());
        $this->assertContains('energy_rule_latest_known_price_continuation', $result->assumptions);
        $this->assertTrue($result->energyRuleComparison->actualEstimated);

        $later = $this->service()->evaluate($contract, $this->usage(), startDate: CarbonImmutable::parse('2027-02-01', 'Europe/Helsinki'))['outcome'];
        $this->assertTrue($later->isListed());
        $this->assertEqualsWithDelta(132, $later->totalCost, .00001);
        $this->assertSame(11.0, $later->generalKwhPrice);
        $this->assertNull($later->energyRuleComparison->projection);
        $this->assertNull($later->energyRuleComparison->currentNormalRates);
        $this->assertSame([], $later->phaseBreakdown);

        $returnQuote = 'General energy price is fixed at 9 cents/kWh from 2027-03-01 through 2027-03-31';
        $source['Details']['ShortDescription'] .= '; '.$returnQuote;
        $future['starts'] = $future['components'][0]['energy_rule']['starts'] = ['kind' => 'date', 'value' => '2027-03-01'];
        $future['ends'] = $future['components'][0]['energy_rule']['ends'] = ['kind' => 'date', 'value' => '2027-03-31'];
        $future['components'][0]['amount'] = 9;
        $future['components'][0]['evidence'] = [F::citation('short_description', $returnQuote)];
        $future['components'][0]['energy_rule']['evidence'] = [...F::scope(), F::citation('short_description', $returnQuote)];
        $output['pricing']['phases'][] = $future;
        $returned = $this->contract('future-nine-return', example: [$input, $output, $source]);
        $returnPlan = $this->service()->evaluate($returned, $this->usage(), startDate: $this->date())['outcome'];
        foreach (range(6, 11) as $month) {
            $this->assertEqualsWithDelta(9, $returnPlan->monthlyCosts[$month], .00001);
        }
        $afterReturn = $this->service()->evaluate($returned, $this->usage(), startDate: CarbonImmutable::parse('2027-04-01', 'Europe/Helsinki'))['outcome'];
        $this->assertEqualsWithDelta(108, $afterReturn->totalCost, .00001);
        $this->assertNull($afterReturn->energyRuleComparison->projection);
        $this->assertNull($afterReturn->energyRuleComparison->currentNormalRates);
    }

    public function test_plain_finite_ordinary_guarantee_uses_a_regular_quote_for_its_unknown_tail(): void
    {
        [$input, $output, $source] = FullSourceEnergyFixture::wholeTerm();
        $source['Details']['ContractType'] = 'OpenEnded';
        $source['Name'] = $source['Details']['Pricing']['Name'] = 'Energy test';
        unset($source['Details']['FixedTimeRange']);
        $source['Details']['LongDescription'] = null;
        $source['Details']['ShortDescription'] = 'General energy price is fixed at 9 cents/kWh for the first 3 months';
        $output['classification']['term_type'] = $output['source_consistency']['recommended_contract_type'] = 'OpenEnded';
        $output['classification']['fixed_duration_months'] = null;
        $output['calculation']['status'] = 'estimate_required';
        $phase = &$output['pricing']['phases'][0];
        $phase['ends']['value'] = $phase['components'][0]['energy_rule']['ends']['value'] = '3';
        $phase['components'][0]['energy_rule']['evidence'] = [...F::scope(), F::citation('short_description', $source['Details']['ShortDescription'])];
        unset($phase);
        $target = $this->contract('ordinary-finite', example: [$input, $output, $source]);
        $this->contract('regular-quote-peer', observed: '2026-08-01');
        $result = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertTrue($result->isListed());
        $this->assertEqualsWithDelta(9, $result->monthlyCosts[0], .00001);
        $this->assertEqualsWithDelta(9, $result->monthlyCosts[2], .00001);
        $this->assertEqualsWithDelta(12, $result->monthlyCosts[3], .00001);
        $this->assertEqualsWithDelta(0, $result->energyRuleComparison->netDifference, .00001);
        $this->assertNull($result->energyRuleComparison->projection->estimate->priceEpisodeAnchor->startedAt);
        $this->assertSame('forward_premium', $result->energyRuleComparison->projection->estimate->basis->value);
        $publication = ContractInterpretation::findOrFail($target->published_interpretation_id);
        $incomplete = $publication->output;
        $incomplete['calculation']['status'] = 'incomplete';
        $incomplete['calculation']['missing_facts'] = ['Future rate unknown'];
        $publication->update(['output' => $incomplete]);
        $target->update(['canonical_calculation' => $incomplete['calculation']]);
        $this->assertTrue((new CurrentSourcePromotionEvidence)->forContracts(collect([$target]), $this->date())[$target->id]['energy_rules_valid']);
        $estimated = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertEquals($result->totalCost, $estimated->totalCost);
        $this->assertSame('forward_premium', $estimated->energyRuleComparison->projection->estimate->basis->value);

        $source['Details']['ShortDescription'] = 'General energy price is fixed at 9 cents/kWh from 2026-09-01 through 2026-11-30';
        $phase = &$output['pricing']['phases'][0];
        $phase['ends'] = ['kind' => 'none', 'value' => null];
        $phase['components'][0]['energy_rule']['starts'] = ['kind' => 'date', 'value' => '2026-09-01'];
        $phase['components'][0]['energy_rule']['ends'] = ['kind' => 'date', 'value' => '2026-11-30'];
        $phase['components'][0]['energy_rule']['evidence'] = [...F::scope(), F::citation('short_description', $source['Details']['ShortDescription'])];
        unset($phase);
        $expired = $this->contract('ordinary-expired-lock', example: [$input, $output, $source]);
        $current = $this->service()->evaluate($expired, $this->usage(), startDate: CarbonImmutable::parse('2026-12-01', 'Europe/Helsinki'))['outcome'];
        $this->assertTrue($current->isListed());
        $this->assertEqualsWithDelta(9, $current->monthlyCosts[0], .00001);
        $this->assertSame(['energy_general' => 9.0], $current->energyRuleComparison->projection->currentRates);
        $this->assertNull($current->energyRuleComparison->projection->estimate->priceEpisodeAnchor->startedAt);
        $this->assertFalse($current->phaseBreakdown[0]['energy_price_guaranteed']);
    }

    public function test_exact_actual_whole_year_keeps_none_method_while_normal_uses_own_forecast(): void
    {
        [$input, $output, $source] = F::example();
        $source['Details']['ShortDescription'] = str_replace('first 3 months', 'first 12 months', $source['Details']['ShortDescription']);
        $source['Details']['Pricing']['PriceComponents'][0]['Discount']['NfirstMonths'] = 12;
        $output['pricing']['phases'][0]['ends']['value'] = '12';
        $output['pricing']['phases'][1]['starts']['value'] = '12';
        $component = &$output['pricing']['phases'][0]['components'][0];
        $component['energy_rule']['ends']['value'] = '12';
        $component['energy_rule']['evidence'][2]['quote'] = str_replace('first 3 months', 'first 12 months', $component['energy_rule']['evidence'][2]['quote']);
        foreach ($component['evidence'] as &$citation) {
            if ($citation['source'] === 'components[0].discount_n_first_months') {
                $citation['quote'] = '12';
            }
        }
        unset($component, $citation);
        $target = $this->contract('actual-exact', example: [$input, $output, $source]);
        $result = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('none', $result->estimateMethod->value);
        $this->assertFalse($result->energyRuleComparison->actualEstimated);
        $this->assertTrue($result->energyRuleComparison->normalEstimated);
        $this->assertSame('forward_curve_shift', $result->energyRuleComparison->projection->estimate->basis->value);
        $this->assertEqualsWithDelta(48, $result->totalCost, .00001);
        $this->assertEqualsWithDelta(141, $result->baseTotalCost, .00001);
        $this->assertEqualsWithDelta(4, $result->energyRuleComparison->annualEquivalentEnergyPrice, .00001);
    }

    public function test_reset_missing_reference_transfers_proved_normal_peer_rates(): void
    {
        config()->set('canonical_pricing.reset_forward_shift.enabled', true);
        $this->app->forgetScopedInstances();
        $this->app->instance(MarketReferenceCurveProvider::class, $this->curve);
        $this->curve->missingReferenceDates = ['2026-09-01'];
        foreach (['reset-target' => '2026-09-01', 'reset-peer' => '2026-09-10'] as $id => $periodStart) {
            [$input, $output, $source] = F::example();
            $output['calculation']['status'] = 'estimate_required';
            $output['classification']['pricing_mechanisms'][] = 'periodic_market_reset';
            $output['classification']['periodic_reset_cadence'] = 'monthly';
            $output['classification']['schedule_kinds'][] = 'recurring_market_reset';
            $output['pricing']['recurring_schedule'] = ['present' => true, 'cadence' => 'monthly', 'current_period_start' => $periodStart, 'current_period_end' => '2026-09-30', 'future_price_known' => false, 'description' => null, 'evidence' => []];
            $contracts[$id] = $this->contract($id, observed: $periodStart, example: [$input, $output, $source]);
        }
        $result = $this->service()->evaluate($contracts['reset-target'], $this->usage(), startDate: $this->date()->addDays(14))['outcome'];
        $projection = $result->energyRuleComparison->projection;
        $this->assertSame('forward_premium', $projection->estimate->basis->value);
        $this->assertSame(['energy_general' => 4.0], $projection->estimate->premium->premiumsByBucket);
        $this->assertSame('same_company', $projection->estimate->premium->source->value);
        $this->assertEqualsWithDelta(3, $result->baseMonthlyCosts[1] / $result->monthlyCosts[1], .00001);
    }

    public function test_zero_current_normal_reset_is_a_rate_not_missing_energy(): void
    {
        config()->set('canonical_pricing.reset_forward_shift.enabled', true);
        $this->app->forgetScopedInstances();
        $this->app->instance(MarketReferenceCurveProvider::class, $this->curve);
        [$input, $output, $source] = F::example('percentage_discount', 0, 50);
        $output['calculation']['status'] = 'estimate_required';
        $output['classification']['pricing_mechanisms'][] = 'periodic_market_reset';
        $output['classification']['periodic_reset_cadence'] = 'monthly';
        $output['classification']['schedule_kinds'][] = 'recurring_market_reset';
        $output['pricing']['recurring_schedule'] = ['present' => true, 'cadence' => 'monthly', 'current_period_start' => '2026-09-01', 'current_period_end' => '2026-09-30', 'future_price_known' => false, 'description' => null, 'evidence' => []];
        $contract = $this->contract('reset-zero', example: [$input, $output, $source]);
        $result = $this->service()->evaluate($contract, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertTrue($result->isListed());
        $this->assertSame(['energy_general' => 0.0], $result->energyRuleComparison->projection->currentRates);
        $this->assertEqualsWithDelta(0, $result->monthlyCosts[0], .00001);
        $this->assertEqualsWithDelta(1.5, $result->monthlyCosts[1], .00001);
    }

    public function test_known_future_normal_map_is_scoped_not_a_current_anchor_and_unknown_tail_holds_latest_map(): void
    {
        [$input, $output, $source] = F::example();
        $source['Details']['ContractType'] = 'FixedTerm';
        $source['Details']['FixedTimeRange'] = 'Fixed6';
        $source['Details']['Pricing']['HasDiscount'] = false;
        $source['Details']['Pricing']['PriceComponents'][0]['HasDiscount'] = false;
        $source['Details']['Pricing']['PriceComponents'][0]['OriginalPayment']['Price'] = 4;
        $output['classification']['term_type'] = $output['source_consistency']['recommended_contract_type'] = 'FixedTerm';
        $output['classification']['fixed_duration_months'] = 6;
        $clauses = [];
        foreach ([['2026-09-01', '2026-11-30', 9], ['2026-12-01', '2027-02-28', 12]] as $index => [$start, $end, $normal]) {
            $actualQuote = "General energy price is fixed at 4 cents/kWh from {$start} through {$end}";
            $normalQuote = "General normal energy price is fixed at {$normal} cents/kWh from {$start} through {$end}";
            $clauses = [...$clauses, $actualQuote, $normalQuote];
            $phase = &$output['pricing']['phases'][$index];
            $phase['phase_kind'] = 'introductory';
            $phase['starts'] = ['kind' => 'date', 'value' => $start];
            $phase['ends'] = ['kind' => 'date', 'value' => $end];
            $component = &$phase['components'][0];
            $component['amount'] = 4;
            $component['normal_amount'] = $normal;
            $component['price_role'] = 'introductory';
            $component['evidence'] = [F::citation('short_description', $actualQuote), F::citation('short_description', $normalQuote)];
            $component['energy_rule'] = ['kind' => 'fixed_price', 'starts' => $phase['starts'], 'ends' => $phase['ends'],
                'discount_value' => null, 'floor_amount' => null, 'evidence' => [...F::scope(), F::citation('short_description', $actualQuote)],
                'normal_basis' => ['kind' => 'fixed_price', 'starts' => $phase['starts'], 'ends' => $phase['ends'],
                    'evidence' => [...F::scope(), F::citation('short_description', $normalQuote)]]];
            unset($phase, $component);
        }
        $source['Details']['ShortDescription'] = implode('; ', $clauses);
        $contract = $this->contract('changing-normal', example: [$input, $output, $source]);
        $result = $this->service()->evaluate($contract, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertTrue($result->isListed());
        $this->assertFalse($result->energyRuleComparison->actualEstimated);
        $this->assertFalse($result->energyRuleComparison->normalEstimated);
        $this->assertSame(['energy_general' => 9.0], $result->energyRuleComparison->currentNormalRates);
        $this->assertNull($result->energyRuleComparison->projection);
        $this->assertEqualsWithDelta(24, $result->contractTermTotalCost, .00001);
        $this->assertEqualsWithDelta(63, $result->contractTermBaseTotalCost, .00001);
        $this->assertSame(0, $this->curve->queries);

        $source['Details']['ContractType'] = 'OpenEnded';
        unset($source['Details']['FixedTimeRange']);
        $output['classification']['term_type'] = $output['source_consistency']['recommended_contract_type'] = 'OpenEnded';
        $output['classification']['fixed_duration_months'] = null;
        $output['calculation']['status'] = 'estimate_required';
        $open = $this->contract('changing-normal-tail', example: [$input, $output, $source]);
        $tail = $this->service()->evaluate($open, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertTrue($tail->isListed());
        $this->assertSame(['energy_general' => 9.0], $tail->energyRuleComparison->currentNormalRates);
        $this->assertTrue($tail->energyRuleComparison->actualEstimated);
        $this->assertContains('energy_rule_latest_known_price_continuation', $tail->assumptions);
        foreach (range(6, 11) as $month) {
            $this->assertEqualsWithDelta(12, $tail->monthlyCosts[$month], .00001);
            $this->assertEqualsWithDelta(12, $tail->baseMonthlyCosts[$month], .00001);
        }
        $oldQuote = 'General normal energy price is fixed at 9 cents/kWh from 2026-09-01 through 2026-11-30';
        $currentQuote = 'General normal energy tariff is adjustable and is currently 9 cents/kWh';
        $source['Details']['ShortDescription'] = str_replace($oldQuote, $currentQuote, $source['Details']['ShortDescription']);
        $component = &$output['pricing']['phases'][0]['components'][0];
        $component['evidence'][1] = F::citation('short_description', $currentQuote);
        $component['energy_rule']['normal_basis'] = ['kind' => 'adjustable_tariff', 'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'none', 'value' => null], 'evidence' => [...F::scope(), F::citation('short_description', $currentQuote)]];
        unset($component);
        $changed = $this->contract('normal-now-twelve', example: [$input, $output, $source]);
        $nowTwelve = $this->service()->evaluate($changed, $this->usage(), startDate: CarbonImmutable::parse('2027-01-01', 'Europe/Helsinki'))['outcome'];
        $this->assertTrue($nowTwelve->isListed());
        $this->assertSame(['energy_general' => 12.0], $nowTwelve->energyRuleComparison->currentNormalRates);
        $this->assertEqualsWithDelta(12, $nowTwelve->baseMonthlyCosts[0], .00001);
        $this->assertNull($nowTwelve->energyRuleComparison->projection->estimate->priceEpisodeAnchor->startedAt);
        $afterTwelve = $this->service()->evaluate($changed, $this->usage(), startDate: CarbonImmutable::parse('2027-03-01', 'Europe/Helsinki'))['outcome'];
        $this->assertTrue($afterTwelve->isListed());
        $this->assertEqualsWithDelta(144, $afterTwelve->totalCost, .00001);
        $this->assertSame(12.0, $afterTwelve->generalKwhPrice);
        $this->assertNull($afterTwelve->energyRuleComparison->projection);
        $this->assertNull($afterTwelve->energyRuleComparison->currentNormalRates);
    }

    public function test_projected_model_floor_has_a_public_assumption_but_never_a_source_floor(): void
    {
        $this->curve->forwardRate = 0;
        foreach ([null, 3.0] as $floor) {
            $id = $floor === null ? 'model-floor' : 'source-floor';
            $contract = $this->contract($id, example: F::example('absolute_discount', 9, 5, $floor));
            $result = $this->service()->evaluate($contract, $this->usage(), startDate: $this->date())['outcome'];
            $this->assertEqualsWithDelta($floor ?? 0, $result->monthlyCosts[1], .00001);
            $payload = $result->toCalculatedCostArray();
            $this->assertSame($floor === null, in_array('energy_rule_nonnegative_model_floor_applied', $payload['assumptions'], true));
            $this->assertSame($floor, $result->offerTerms[0]->components[0]->floorAmount);
            $period = $this->service()->periodEvaluationsForContracts(collect([$contract]), new CanonicalPeriodPricingRequest($this->date(), $this->date()->endOfMonth(), 100, 1200, []), new SpotAssumptions(null, null))[$id]['period'];
            $this->assertEqualsWithDelta(4, $period->periodTotal, .00001);
            $this->assertNotContains('energy_rule_nonnegative_model_floor_applied', $period->assumptions);
        }
        $this->curve->forwardRate = 5;
        $zero = $this->contract('observed-zero', example: F::example('percentage_discount', 0, 50));
        $result = $this->service()->evaluate($zero, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertEqualsWithDelta(0, $result->monthlyCosts[0], .00001);
        $this->assertNotContains('energy_rule_nonnegative_model_floor_applied', $result->assumptions);
    }

    private function tariffExample(string $metering): array
    {
        if ($metering === 'General') {
            return F::example();
        }
        [$input, $output, $source] = F::example();
        [$names, $sourceTypes, $types, $mechanism] = $metering === 'Time'
            ? [['Day', 'Night'], ['DayTime', 'NightTime'], ['energy_day', 'energy_night'], 'time_of_use']
            : [['Winter', 'Other-season'], ['SeasonalWinterDay', 'SeasonalOther'], ['energy_seasonal_winter', 'energy_seasonal_other'], 'seasonal'];
        $source['Details']['Metering'] = $output['classification']['metering'] = $output['source_consistency']['recommended_metering'] = $metering;
        $output['classification']['pricing_mechanisms'][] = $mechanism;
        $clauses = [];
        foreach ($types as $i => $type) {
            [, $part, $partSource] = F::example('fixed_price', $i === 0 ? 9 : 6, $i === 0 ? 5 : 2);
            $sourceComponent = $partSource['Details']['Pricing']['PriceComponents'][0];
            $sourceComponent['PriceComponentType'] = $sourceTypes[$i];
            $sourceComponent['Id'] = 'energy-'.$i;
            $source['Details']['Pricing']['PriceComponents'][$i] = $sourceComponent;
            $clauses[] = str_replace('General', $names[$i], $partSource['Details']['ShortDescription']);
            foreach ($part['pricing']['phases'] as $p => $phase) {
                $component = $phase['components'][0];
                $component['component_type'] = $type;
                $json = json_encode($component, JSON_THROW_ON_ERROR);
                $json = str_replace(['components[0]', 'General'], ['components['.$i.']', $names[$i]], $json);
                $component = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                if ($p === 0) {
                    $component['energy_rule']['evidence'][0]['quote'] = $sourceTypes[$i];
                    $component['energy_rule']['normal_basis']['evidence'][0]['quote'] = $sourceTypes[$i];
                }
                $output['pricing']['phases'][$p]['components'][$i] = $component;
            }
        }
        $source['Details']['ShortDescription'] = implode('; ', $clauses);

        return [$input, $output, $source];
    }

    private function date(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-01', 'Europe/Helsinki');
    }

    private function usage(): EnergyUsage
    {
        return new EnergyUsage(total: 1200, basicLiving: 1200);
    }

    private function service(): CanonicalContractPricingService
    {
        return app(CanonicalContractPricingService::class)->withSpotAssumptions(new SpotAssumptions(null, null));
    }

    private function contract(string $id, string $kind = 'fixed_price', string $observed = '2026-09-01', ?array $example = null): ElectricityContract
    {
        [, $output, $source] = $example ?? F::example($kind);
        $output['contract_id'] = $id;
        if ($example === null) {
            $source['Details']['Pricing']['PriceComponents'][] = ['Id' => 'fee', 'PriceComponentType' => 'Monthly', 'HasDiscount' => false,
                'OriginalPayment' => ['Price' => 6, 'PaymentUnit' => 'EurPerMonth']];
            foreach ($output['pricing']['phases'] as &$phase) {
                $phase['components'][] = ['component_type' => 'monthly_fee', 'amount' => 6, 'normal_amount' => null, 'unit' => 'eur_per_month',
                    'vat_status' => 'included', 'price_role' => 'current', 'source_kind' => 'structured', 'evidence' => [F::citation('components[1].price', 6)], 'energy_rule' => null];
            }
            unset($phase);
        }
        Company::firstOrCreate(['name' => 'Energy test company'], ['name_slug' => 'energy-test-company']);
        $contract = ElectricityContract::factory()->active()->forCompany('Energy test company')->create(['id' => $id, 'name' => 'Energy test',
            'pricing_model' => $source['Details']['PricingModel'], 'contract_type' => $source['Details']['ContractType'], 'metering' => $source['Details']['Metering'], 'target_group' => $source['Details']['TargetGroup'],
            'fixed_time_range' => $source['Details']['FixedTimeRange'] ?? null,
            'canonical_pricing' => $output['pricing'], 'canonical_calculation' => $output['calculation'], 'canonical_source_consistency' => $output['source_consistency']]);
        $snapshot = ContractSourceSnapshot::create(['contract_id' => $id, 'source_fingerprint' => hash('sha256', $id), 'source_payload' => $source,
            'first_observed_at' => $observed, 'last_observed_at' => '2026-09-15']);
        $observation = ContractSourceObservation::create(['contract_id' => $id, 'source_snapshot_id' => $snapshot->id,
            'first_observed_at' => $observed, 'last_observed_at' => '2026-09-15']);
        $publication = ContractInterpretation::create(['contract_id' => $id, 'source_snapshot_id' => $snapshot->id,
            'analysis_source_observation_id' => $observation->id, 'analysis_fingerprint' => hash('sha256', 'analysis'.$id), 'status' => 'published',
            'schema_version' => 'schema-v5', 'prompt_version' => 'prompt-v20', 'validator_version' => 'validator-v18', 'provider' => 'test', 'model' => 'test',
            'output' => $output, 'validation_errors' => [], 'completed_at' => $observed.' 01:00:00', 'published_at' => $observed.' 01:00:00']);
        $contract->update(['current_source_observation_id' => $observation->id, 'published_interpretation_id' => $publication->id]);
        $input = (new ContractInterpretationInputBuilder)->build($snapshot, $observed, F::profile());
        $this->assertSame([], (new ContractInterpretationValidator)->validate($output, $input, F::profile()), $id);
        $this->assertTrue((new CurrentSourcePromotionEvidence)->forContracts(collect([$contract]), CarbonImmutable::now())[$id]['energy_rules_valid'], $id);

        return $contract;
    }
}

class EnergyRuleFinancialCurve implements MarketReferenceCurveProvider
{
    public array $missingMonths = [];

    public array $missingReferenceDates = [];

    public int $queries = 0;

    public float $forwardRate = 8.0;

    public function tradeDate(CarbonImmutable $asOfDate): ?CarbonImmutable
    {
        $this->queries++;

        return $asOfDate->subDay();
    }

    public function referencePrice(CarbonImmutable $asOfDate, CarbonImmutable $anchorMonth, array $kindPreference): ?array
    {
        $this->queries++;

        return in_array($anchorMonth->month, $this->missingMonths, true) || in_array($asOfDate->toDateString(), $this->missingReferenceDates, true) ? null
            : ['kind' => $kindPreference[0], 'price_cents_per_kwh' => 5.0, 'trade_date' => $asOfDate->subDay()->toDateString()];
    }

    public function forwardPriceForMonth(CarbonImmutable $asOfDate, CarbonImmutable $deliveryMonth): ?array
    {
        $this->queries++;

        return ['kind' => 'month', 'price_cents_per_kwh' => $this->forwardRate];
    }

    public function spotSeasonalIndex(CarbonImmutable $asOfDate): ?array
    {
        return null;
    }

    public function fixedTermMedianEnergyPrice(): ?float
    {
        return null;
    }
}
