<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Models\ElectricityFuturesEodPrice;
use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingRequest;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\ComparisonPolicy;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\ForwardPremium\CurrentPremiumEvidenceLoader;
use App\Services\CanonicalPricing\MarketReset\EexMarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\ResetEstimateCopy;
use App\Services\ContractCard\ContractCardCopy;
use App\Services\ContractCard\PricingCategoryResolver;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use Database\Factories\Support\CanonicalPricingFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CurrentResetPremiumIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private ResetPremiumCurve $curve;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('canonical_pricing.enabled', true);
        config()->set('canonical_pricing.reset_forward_shift.enabled', true);
        $this->app->forgetScopedInstances();
        $this->curve = new ResetPremiumCurve;
        $this->app->instance(MarketReferenceCurveProvider::class, $this->curve);
    }

    #[DataProvider('tariffs')]
    public function test_current_service_uses_named_peer_rates_in_all_entry_points(string $metering, array $rates): void
    {
        $target = $this->contract('target', 'Seller', '2026-04-01', $rates, $metering);
        $this->contract('peer', 'Seller', '2026-04-10', array_map(fn ($rate) => $rate * 1.5, $rates), $metering);
        $service = $this->service();
        $outcome = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('recurring_forward_premium', $outcome->estimateMethod->value);
        $estimate = $outcome->resetEstimate;
        $knownPricing = $target->canonical_pricing;
        $first = $knownPricing['phases'][0];
        $first['ends'] = ['kind' => 'date', 'value' => '2026-04-30'];
        $future = $knownPricing['phases'][0];
        $future['starts'] = ['kind' => 'date', 'value' => '2026-05-01'];
        foreach ($future['components'] as &$component) {
            if (isset($rates[$component['component_type']])) {
                $component['amount'] = $rates[$component['component_type']] * 1.5 + 1;
            }
        }
        unset($component);
        $knownPricing['phases'] = [$first, $future];
        $knownPricing['recurring_schedule']['present'] = false;
        $knownPricing['recurring_schedule']['cadence'] = 'none';
        $known = (new CanonicalPricingParser)->parse($knownPricing, $target->canonical_calculation, $target->canonical_source_consistency);
        $expected = app(CanonicalContractPriceCalculator::class)->calculate($known, new ContractContext('FixedPrice', 'FixedTerm', $metering, 'Fixed12', 'Household'), $this->usage(), new SpotAssumptions(null, null), $this->date(), policy: ComparisonPolicy::Historical);
        $this->assertEqualsWithDelta($expected->totalCost, $outcome->totalCost, .000001);
        foreach ($expected->monthlyCosts as $month => $cost) {
            $this->assertEqualsWithDelta($cost, $outcome->monthlyCosts[$month], .000001);
        }
        $this->assertSame('same_company', $estimate['premium']['source']);
        $this->assertSame(array_map(fn ($rate) => $rate * 1.5 - 5, $rates), $estimate['premium']['premiums_by_bucket']);
        $this->assertSame('2026-04-14', $estimate['premium']['evidence_through']);
        $this->assertEqualsWithDelta($outcome->totalCost, $outcome->baseTotalCost, .0001);
        $this->assertEqualsWithDelta($outcome->totalCost, $outcome->structuredOnlyTotal, .0001);
        $this->assertEqualsWithDelta(($outcome->totalCost - 48) / 50, $estimate['annual_equivalent_energy_price'], .0001);
        $view = ContractPricingViewData::fromCanonicalOutcome($outcome);
        $this->assertSame('forward_premium', $view->toArray()['reset_estimate']['basis']);
        $this->assertStringContainsString('nykyisiin sähköfutuureihin', ResetEstimateCopy::receiptNote($estimate));
        $metric = $service->metricsForContracts(collect([$target]), $this->usage(), $this->date())['target'];
        $this->assertSame($outcome->totalCost, $metric->pricing()->toArray()['total_cost']);
        $multi = $service->outcomesForContractsAtConsumptions(collect([$target]), [2000, 5000, 18000], new SpotAssumptions(null, null), $this->date());
        $this->assertSame($outcome->totalCost, $multi['target'][5000]->totalCost);
        $period = $service->periodEvaluationsForContracts(collect([$target]), new CanonicalPeriodPricingRequest($this->date(), $this->date()->addDays(10), 300, 5000, []))['target'];
        $this->assertSame($outcome->totalCost, $period['annual']->totalCost);
        $this->curve->currentAvailable = false;
        $service->resetMemoization();
        $without = $service->periodEvaluationsForContracts(collect([$target]), new CanonicalPeriodPricingRequest($this->date(), $this->date()->addDays(10), 300, 5000, []))['target'];
        $this->assertSame($period['period']->periodTotal, $without['period']->periodTotal);
        $this->assertNotSame($outcome->totalCost, $without['annual']->totalCost);
    }

    public static function tariffs(): array
    {
        return [
            ['General', ['energy_general' => 8]],
            ['Time', ['energy_day' => 10, 'energy_night' => 6]],
            ['Season', ['energy_seasonal_other' => 6, 'energy_seasonal_winter' => 12]],
        ];
    }

    public function test_trusted_own_lineage_precedes_same_company_and_announced_quarters_are_date_bounded(): void
    {
        $target = $this->contract('target', 'Seller', '2026-04-01', cadence: 'quarterly');
        $ancestor = $this->contract('ancestor', 'Seller', '2026-07-01', ['energy_general' => 9], cadence: 'quarterly', end: '2026-09-30');
        $ancestor->update(['replaced_by_contract_id' => $target->id]);
        $this->contract('competitor', 'Seller', '2026-04-10', ['energy_general' => 20], cadence: 'quarterly');
        $service = $this->service();
        $outcome = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $premium = $outcome->resetEstimate['premium'];
        $this->assertSame('own_lineage', $premium['source']);
        $this->assertSame(['energy_general' => 4.0], $premium['premiums_by_bucket']);
        $this->assertSame('2026-07-01', $premium['references'][0]['delivery_start']);
        $this->assertFalse($premium['references'][0]['reference_period_proxy']);
        $this->assertSame(['2026-04-14'], $premium['reference_trade_dates']);
        ContractInterpretation::whereKey($ancestor->published_interpretation_id)->update(['completed_at' => '2026-04-16 00:00:00']);
        $service->resetMemoization();
        $next = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('same_company', $next->resetEstimate['premium']['source']);
        $this->assertSame(['energy_general' => 15.0], $next->resetEstimate['premium']['premiums_by_bucket']);
    }

    public function test_known_current_period_followed_by_unknown_tail_keeps_the_same_forecast(): void
    {
        $target = $this->contract('target', 'Seller', '2026-04-01', status: 'exact');
        $this->contract('peer', 'Seller', '2026-04-10');
        $service = $this->service();
        $plain = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $pricing = $target->canonical_pricing;
        $pricing['phases'][0]['ends'] = ['kind' => 'date', 'value' => '2026-04-30'];
        $this->publishPricing($target, $pricing);
        $service->resetMemoization();
        $known = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('recurring_forward_premium', $known->estimateMethod->value);
        $this->assertSame('comparable_estimate', $plain->comparability->value);
        $this->assertSame('comparable_estimate', $known->comparability->value);
        $this->assertSame($plain->totalCost, $known->totalCost);
        $this->assertSame($plain->monthlyCosts, $known->monthlyCosts);
        $pricing['phases'][0]['starts'] = ['kind' => 'date', 'value' => '2026-04-20'];
        $this->publishPricing($target, $pricing);
        $service->resetMemoization();
        $gap = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertNull($gap->resetEstimate);
        $this->assertNull($gap->totalCost);
    }

    public function test_public_api_serializes_the_distinct_reset_premium_policy(): void
    {
        $this->contract('target', 'Seller', '2026-04-01');
        $this->contract('peer', 'Seller', '2026-04-10');
        $this->travelTo($this->date());
        $response = $this->getJson('/api/contracts/target?consumption=5000');
        $response->assertOk()->assertJsonPath('data.calculated_cost.estimate_method', 'recurring_forward_premium')
            ->assertJsonPath('data.calculated_cost.reset_estimate.current_policy', 'recurring_forward_premium_v1')
            ->assertJsonPath('data.calculated_cost.reset_estimate.premium.source', 'same_company');
        $this->assertArrayNotHasKey('price_components', $response->json('data'));
    }

    public function test_bad_model_reference_and_malformed_peer_do_not_hide_valid_market_evidence(): void
    {
        $target = $this->contract('target', 'Seller', '2026-04-01');
        $peer = $this->contract('peer', 'Seller', '2026-04-10');
        $pricing = $peer->canonical_pricing;
        $pricing['phases'][0]['components'][0]['unit'] = 'unknown_unit';
        $this->publishPricing($peer, $pricing);
        $this->contract('market', 'Market', '2026-04-10', ['energy_general' => 10]);
        $service = $this->service();
        $this->assertSame('market', $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome']->resetEstimate['premium']['source']);
        $this->curve->referenceTradeOverride = '2026-04-15';
        $service->resetMemoization();
        $this->assertNull($service->evaluate($target, $this->usage(), startDate: $this->date())['outcome']->resetEstimate);
    }

    public function test_market_is_company_balanced_and_fee_clones_do_not_add_weight(): void
    {
        $target = $this->contract('target', 'Target', '2026-04-01');
        $this->contract('a', 'A', '2026-04-10', ['energy_general' => 7]);
        $this->contract('b', 'B', '2026-04-10', ['energy_general' => 11]);
        for ($i = 0; $i < 4; $i++) {
            $this->contract('clone'.$i, 'A', '2026-04-10', ['energy_general' => 7], fee: $i);
        }
        $outcome = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $premium = $outcome->resetEstimate['premium'];
        $this->assertSame('market', $premium['source']);
        $this->assertSame(['energy_general' => 4.0], $premium['premiums_by_bucket']);
        $this->assertSame(2, $premium['company_count']);
        $this->assertSame(2, $premium['independent_variant_count']);
    }

    public function test_original_reference_wins_and_today_old_period_reference_cannot_bypass_peers(): void
    {
        $target = $this->contract('target', 'Seller', '2026-04-01');
        $this->contract('peer', 'Seller', '2026-04-10', ['energy_general' => 10]);
        $service = $this->service();
        $outcome = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('recurring_forward_premium', $outcome->estimateMethod->value);
        $this->assertNotContains('reference_vintage_fallback_today', $outcome->resetEstimate['flags']);
        $this->curve->originalAvailable = true;
        $service->resetMemoization();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $own = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('recurring_forward_curve_shift', $own->estimateMethod->value);
        $this->assertArrayNotHasKey('premium', $own->resetEstimate);
        $this->assertSame([], $this->peerQueries());
    }

    public function test_historical_retains_today_fallback_without_current_peer_reads(): void
    {
        $target = $this->contract('target', 'Seller', '2026-04-01');
        $this->contract('peer', 'Seller', '2026-04-10');
        $data = (new CanonicalPricingParser)->parse($target->canonical_pricing, $target->canonical_calculation, $target->canonical_source_consistency);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $outcome = app(CanonicalContractPriceCalculator::class)->calculate($data, ContractContext::fromContract($target), $this->usage(), new SpotAssumptions(null, null), $this->date(), policy: ComparisonPolicy::Historical);
        $this->assertSame('recurring_forward_curve_shift', $outcome->estimateMethod->value);
        $this->assertContains('reference_vintage_fallback_today', $outcome->resetEstimate['flags']);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_no_curve_skips_peers_and_retry_clears_peer_evidence(): void
    {
        $target = $this->contract('target', 'Seller', '2026-04-01');
        $peer = $this->contract('peer', 'Seller', '2026-04-10');
        $service = $this->service();
        $this->curve->currentAvailable = false;
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->evaluate($target, $this->usage(), startDate: $this->date());
        $this->assertSame([], $this->peerQueries());
        $this->curve->currentAvailable = true;
        $service->resetMemoization();
        $this->assertSame('recurring_forward_premium', $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome']->estimateMethod->value);
        $peer->update(['published_interpretation_id' => null]);
        $service->resetMemoization();
        $this->assertNull($service->evaluate($target, $this->usage(), startDate: $this->date())['outcome']->resetEstimate);
    }

    public function test_quarter_and_midmonth_known_boundary_stay_exact_and_short_term_needs_only_its_tail(): void
    {
        $target = $this->contract('target', 'Seller', '2026-04-01', cadence: 'quarterly', end: '2026-07-15', term: true);
        $this->contract('peer', 'Seller', '2026-04-10', ['energy_general' => 10], cadence: 'quarterly', end: '2026-06-30');
        $this->curve->missingAnchorMonth = '2026-07';
        $this->curve->lastDelivery = '2026-10';
        $outcome = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('recurring_forward_premium', $outcome->estimateMethod->value);
        $this->assertSame(6, $outcome->termMonths);
        $this->assertEqualsWithDelta($outcome->contractTermTotalCost * 2, $outcome->totalCost, .0001);
        $termKwh = 5000 / 12 * (16 / 30 + 5 + 14 / 31);
        $termEnergy = 5000 / 1200 * (8 * (16 / 30 + 2 + 15 / 31) + 11 * (16 / 31 + 2 + 14 / 31));
        $this->assertEqualsWithDelta($termEnergy + 24, $outcome->contractTermTotalCost, .0001);
        $this->assertEqualsWithDelta($termEnergy * 100 / $termKwh, $outcome->resetEstimate['annual_equivalent_energy_price'], .0001);
        $this->assertEqualsWithDelta($outcome->totalCost, array_sum($outcome->monthlyCosts), .0001);
        $this->assertSame('term_price_only', ContractPricingViewData::fromCanonicalOutcome($outcome)->toArray()['comparability']);
        $this->assertNotContains('2026-11', $this->curve->forwardMonths);
        $this->assertSame('2026-07', $outcome->resetEstimate['tail_starts']);
    }

    #[DataProvider('mismatches')]
    public function test_incompatible_and_unproven_peers_cannot_supply_premiums(string $case): void
    {
        $target = $this->contract('target', 'Seller', '2026-04-01');
        $peer = $this->contract('peer', 'Seller', '2026-04-10',
            $case === 'metering' ? ['energy_day' => 8, 'energy_night' => 6] : ['energy_general' => 8],
            metering: $case === 'metering' ? 'Time' : 'General',
            audience: $case === 'vat' ? 'Company' : 'Household',
            cadence: $case === 'cadence' ? 'quarterly' : 'monthly');
        if ($case === 'proof') {
            $peer->update(['published_interpretation_id' => null]);
        } elseif ($case === 'future_publication') {
            ContractInterpretation::whereKey($peer->published_interpretation_id)->update(['completed_at' => '2026-04-16 00:00:00']);
        } elseif ($case === 'future_observation') {
            ContractSourceObservation::whereKey($peer->current_source_observation_id)->update(['first_observed_at' => '2026-04-16 00:00:00']);
        } elseif (in_array($case, ['family', 'energy_promo', 'malformed', 'incomplete_tariff'], true)) {
            $pricing = $peer->canonical_pricing;
            if ($case === 'family') {
                $pricing['recurring_schedule']['present'] = false;
                $pricing['recurring_schedule']['cadence'] = 'none';
            } elseif ($case === 'energy_promo') {
                $pricing['phases'][0]['components'][0]['normal_amount'] = 12;
            } elseif ($case === 'incomplete_tariff') {
                $peer->update(['metering' => 'Time']);
            } else {
                $pricing['phases'][0]['components'][0]['component_type'] = 'unknown_malformed';
            }
            $this->publishPricing($peer, $pricing);
        }
        $outcome = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertNull($outcome->resetEstimate);
        $this->assertSame('hold_current_recurring_price', $outcome->estimateMethod->value);
    }

    public static function mismatches(): array
    {
        return array_map(fn ($case) => [$case], ['metering', 'vat', 'cadence', 'family', 'proof', 'future_publication', 'future_observation', 'energy_promo', 'malformed', 'incomplete_tariff']);
    }

    #[DataProvider('tariffs')]
    public function test_fee_only_phases_keep_energy_projection_and_company_vat(string $metering, array $rates): void
    {
        $target = $this->contract('target', 'Seller', '2026-04-01', $rates, $metering, 'Company');
        $peer = $this->contract('peer', 'Seller', '2026-04-10', $rates, $metering, 'Company');
        $pricing = $peer->canonical_pricing;
        $normal = $pricing['phases'][0];
        $intro = $normal;
        $intro['phase_kind'] = 'introductory';
        $intro['ends'] = ['kind' => 'after_months', 'value' => '3'];
        $feeIndex = count($intro['components']) - 1;
        $intro['components'][$feeIndex]['amount'] = 0;
        $intro['components'][$feeIndex]['normal_amount'] = 4;
        $normal['phase_kind'] = 'normal';
        $normal['starts'] = ['kind' => 'after_months', 'value' => '3'];
        $pricing['phases'] = [$intro, $normal];
        $this->publishPricing($peer, $pricing);
        $service = $this->service();
        $plain = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('recurring_forward_premium', $plain->estimateMethod->value);
        foreach ($rates as $bucket => $rate) {
            $this->assertEqualsWithDelta($rate - 5 / 1.255, $plain->resetEstimate['premium']['premiums_by_bucket'][$bucket], .000001);
        }
        $pricing['recurring_schedule']['current_period_start'] = '2026-04-01';
        $this->publishPricing($target, $pricing);
        $service->resetMemoization();
        $offer = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame($plain->resetEstimate['premium'], $offer->resetEstimate['premium']);
        $this->assertEqualsWithDelta($plain->totalCost - 12, $offer->totalCost, .000001);
        $this->assertEqualsWithDelta($plain->totalCost, $offer->baseTotalCost, .000001);
        $this->assertEqualsWithDelta(12, $offer->measuredDiscountSavingsTotal, .000001);
    }

    public function test_seasonal_proxy_is_lower_confidence_and_missing_peer_uses_honest_seasonal_fallback(): void
    {
        $target = $this->contract('target', 'Seller', '2026-04-01', cadence: 'seasonal');
        $this->contract('peer', 'Seller', '2026-04-10', cadence: 'seasonal');
        $service = $this->service();
        $outcome = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertTrue($outcome->resetEstimate['premium']['references'][0]['reference_period_proxy']);
        $this->assertSame('2026-06-30', $outcome->resetEstimate['premium']['references'][0]['delivery_end']);
        $this->assertFalse($outcome->resetEstimate['higher_confidence']);
        $this->curve->missingAnchorMonth = '2026-06';
        $this->curve->seasonal = array_fill(1, 12, 1.0);
        $service->resetMemoization();
        $fallback = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('recurring_spot_seasonal_index', $fallback->estimateMethod->value);
        $this->assertStringNotContainsString('ennakkohintoja ei ollut saatavilla', ResetEstimateCopy::receiptNote($fallback->resetEstimate));
    }

    public function test_target_batch_reads_one_peer_universe_and_repeated_real_eex_periods_reuse_queries(): void
    {
        $targets = collect();
        for ($i = 0; $i < 8; $i++) {
            $targets->push($this->contract('target'.$i, 'Target', '2026-04-01'));
        }
        $this->contract('peer', 'Seller', '2026-04-10');
        foreach ([['month', '202604', '2026-04-09', 40], ['year', '202601', '2026-04-14', 50], ['year', '202701', '2026-04-14', 50]] as [$kind, $maturity, $trade, $price]) {
            ElectricityFuturesEodPrice::create([
                'exchange' => 'EEX', 'commodity' => 'POWER', 'pricing' => 'F', 'product' => 'Base', 'area' => 'FI',
                'short_code' => $kind === 'month' ? 'FNBM' : 'FNBY', 'maturity' => $maturity,
                'maturity_type' => $kind, 'trade_date' => $trade, 'settlement_price' => $price,
            ]);
        }
        $provider = app(EexMarketReferenceCurveProvider::class);
        $this->app->forgetScopedInstances();
        $this->app->instance(MarketReferenceCurveProvider::class, $provider);
        $service = $this->service();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $single = $service->evaluate($targets->first(), $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('recurring_forward_premium', $single->estimateMethod->value);
        $firstFutures = count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'electricity_futures_eod_prices')));
        $this->assertGreaterThan(0, $firstFutures);
        $this->assertCount(1, $this->peerQueries());
        DB::flushQueryLog();
        $outcomes = $service->outcomesForContractsAtConsumptions($targets, [2000, 5000, 18000], new SpotAssumptions(null, null), $this->date());
        $this->assertCount(8, $outcomes);
        $this->assertSame($single->totalCost, $outcomes['target7'][5000]->totalCost);
        $this->assertSame([], $this->peerQueries());
        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'electricity_futures_eod_prices'))));
        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'price_components'))));
    }

    public function test_premium_offsets_keep_beta_zero_floor_and_profile_plausibility(): void
    {
        config()->set('canonical_pricing.reset_forward_shift.beta', 0.5);
        $target = $this->contract('target', 'Seller', '2026-04-01');
        $this->contract('peer', 'Seller', '2026-04-10', ['energy_general' => 10]);
        $service = $this->service();
        $outcome = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $knownKwh = 5000 / 12 * 16 / 30;
        $this->assertEqualsWithDelta(48 + $knownKwh * .08 + (5000 - $knownKwh) * .095, $outcome->totalCost, .000001);
        $this->curve->forwardRate = -100;
        $service->resetMemoization();
        $floor = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertEqualsWithDelta(48 + $knownKwh * .08, $floor->totalCost, .000001);
        $this->curve->forwardRate = 1000;
        $service->resetMemoization();
        $guard = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('hold_current_recurring_price', $guard->estimateMethod->value);
        $this->assertEqualsWithDelta(448, $guard->totalCost, .000001);
    }

    #[DataProvider('invalidPayloads')]
    public function test_reset_premium_view_requires_its_own_policy_and_curve(string $field, mixed $value): void
    {
        $target = $this->contract('target', 'Seller', '2026-04-01');
        $this->contract('peer', 'Seller', '2026-04-10');
        $payload = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome']->toCalculatedCostArray();
        $this->assertSame($payload, ContractPricingViewData::fromArray($payload)->toArray());
        $payload['reset_estimate'][$field] = $value;
        $this->expectException(\InvalidArgumentException::class);
        ContractPricingViewData::fromArray($payload);
    }

    public static function invalidPayloads(): array
    {
        return [['current_policy', 'supplier_adjusted_forward_premium_v1'], ['curve_trade_date', null], ['premium', []]];
    }

    #[DataProvider('tariffs')]
    public function test_reset_candidate_cannot_borrow_missing_current_energy_from_future_phase(string $metering, array $rates): void
    {
        $contract = $this->contract('unproven', 'Seller', '2026-07-10', $rates, $metering, cadence: 'quarterly');
        $this->publishPricing($contract, $this->futureEnergyPricing($contract));
        $data = (new CanonicalPricingParser)->parse($contract->canonical_pricing, $contract->canonical_calculation, $contract->canonical_source_consistency);
        $this->assertSame('future', $data->phases[1]->components[0]->priceRole->value);
        $calculator = app(CanonicalContractPriceCalculator::class);
        $asOf = CarbonImmutable::parse('2026-07-15', 'Europe/Helsinki');
        $context = ContractContext::fromContract($contract);
        $candidate = $calculator->resetPremiumCandidate($contract->id, $data, $context, $asOf);
        $this->assertNull($candidate);

        // The guard does not repair or change the old billing inheritance path.
        $controlPricing = $contract->canonical_pricing;
        $controlPricing['phases'][0]['components'] = $controlPricing['phases'][1]['components'];
        foreach ($controlPricing['phases'][0]['components'] as &$component) {
            $component['price_role'] = 'current';
            if ($component['component_type'] === 'monthly_fee') {
                $component['amount'] = 4;
            }
        }
        unset($component);
        $controlData = (new CanonicalPricingParser)->parse($controlPricing, $contract->canonical_calculation, $contract->canonical_source_consistency);
        $historical = $calculator->calculate($data, $context, $this->usage(), new SpotAssumptions(null, null), $asOf, policy: ComparisonPolicy::Historical);
        $control = $calculator->calculate($controlData, $context, $this->usage(), new SpotAssumptions(null, null), $asOf, policy: ComparisonPolicy::Historical);
        $this->assertNotNull($historical->totalCost);
        $this->assertSame($control->totalCost, $historical->totalCost);
        $this->assertSame($control->monthlyCosts, $historical->monthlyCosts);
    }

    #[DataProvider('tariffs')]
    public function test_loader_rejects_future_borrowing_peer_and_keeps_valid_alternative(string $metering, array $rates): void
    {
        $asOf = CarbonImmutable::parse('2026-07-15', 'Europe/Helsinki');
        $target = $this->contract('target', 'Seller', '2026-07-01', $rates, $metering, cadence: 'quarterly');
        $peer = $this->contract('unproven', 'Seller', '2026-07-10', $rates, $metering, cadence: 'quarterly');
        $this->publishPricing($peer, $this->futureEnergyPricing($peer));
        $this->contract('valid', 'Market', '2026-07-10', array_map(fn ($rate) => $rate + 4, $rates), $metering, cadence: 'quarterly');
        $this->curve->missingReferenceBound = '2026-07-01';
        $data = (new CanonicalPricingParser)->parse($target->canonical_pricing, $target->canonical_calculation, $target->canonical_source_consistency);
        $calculator = app(CanonicalContractPriceCalculator::class);
        $candidate = $calculator->resetPremiumCandidate($target->id, $data, ContractContext::fromContract($target), $asOf);
        $this->assertNotNull($candidate);
        $selected = (new CurrentPremiumEvidenceLoader($calculator, $this->curve))->forCandidates(
            [], collect([$target]), [], $asOf, [$target->id => $candidate],
        )[$target->id];
        $this->assertSame('market', $selected->source->value);
        $this->assertSame(['Market'], $selected->sourceCompanies);
        $this->assertSame(array_map(fn ($rate) => (float) $rate - 1, $rates), $selected->premiumsByBucket);
        $outcome = $this->service()->evaluate($target, $this->usage(), startDate: $asOf)['outcome'];
        $this->assertSame('market', $outcome->resetEstimate['premium']['source']);
    }

    #[DataProvider('tariffs')]
    public function test_reset_fee_only_intro_can_use_only_its_adjacent_typed_normal_baseline(string $metering, array $rates): void
    {
        $asOf = CarbonImmutable::parse('2026-07-15', 'Europe/Helsinki');
        $target = $this->contract('target', 'Seller', '2026-07-01', $rates, $metering, cadence: 'quarterly');
        $peer = $this->contract('valid-intro', 'Seller', '2026-07-10', $rates, $metering, cadence: 'quarterly');
        $pricing = $this->futureEnergyPricing($peer);
        $pricing['phases'][0]['phase_kind'] = 'introductory';
        $pricing['phases'][0]['components'] = array_values(array_filter($pricing['phases'][0]['components'], fn ($component) => $component['component_type'] === 'monthly_fee'));
        $pricing['phases'][0]['components'][0]['amount'] = 0;
        $pricing['phases'][1]['phase_kind'] = 'normal';
        foreach ($pricing['phases'][1]['components'] as &$component) {
            $component['price_role'] = 'normal';
        }
        unset($component);
        $this->publishPricing($peer, $pricing);
        $parser = new CanonicalPricingParser;
        $data = $parser->parse($peer->canonical_pricing, $peer->canonical_calculation, $peer->canonical_source_consistency);
        $calculator = app(CanonicalContractPriceCalculator::class);
        $candidate = $calculator->resetPremiumCandidate($peer->id, $data, ContractContext::fromContract($peer), $asOf);
        $this->assertNotNull($candidate);
        $this->assertSame(array_map(fn ($rate) => (float) $rate, $rates), $candidate->normalizedEnergyRates());
        $this->curve->missingReferenceBound = '2026-07-01';
        $outcome = $this->service()->evaluate($target, $this->usage(), startDate: $asOf)['outcome'];
        $this->assertSame('same_company', $outcome->resetEstimate['premium']['source']);
        $this->assertSame(array_map(fn ($rate) => (float) $rate - 5, $rates), $outcome->resetEstimate['premium']['premiums_by_bucket']);

        // Neither an ordinary current fee phase nor a non-adjacent/future baseline gets the exception.
        foreach (['current_fee', 'future_baseline', 'non_adjacent'] as $case) {
            $invalid = $pricing;
            if ($case === 'current_fee') {
                $invalid['phases'][0]['phase_kind'] = 'current_structured';
            } elseif ($case === 'future_baseline') {
                $invalid['phases'][1]['phase_kind'] = 'future';
                foreach ($invalid['phases'][1]['components'] as &$component) {
                    $component['price_role'] = 'future';
                }
                unset($component);
            } else {
                $invalid['phases'][1]['starts']['value'] = '2026-10-02';
            }
            $data = $parser->parse($invalid, $peer->canonical_calculation, $peer->canonical_source_consistency);
            $this->assertNull($calculator->resetPremiumCandidate($peer->id, $data, ContractContext::fromContract($peer), $asOf), $case);
        }
    }

    #[DataProvider('tariffs')]
    public function test_reset_candidate_keeps_expired_unchanged_energy_fee_phase_proof(string $metering, array $rates): void
    {
        $target = $this->contract('target', 'Seller', '2026-07-01', $rates, $metering, cadence: 'quarterly');
        $pricing = $target->canonical_pricing;
        $intro = $pricing['phases'][0];
        $intro['phase_kind'] = 'introductory';
        $intro['ends'] = ['kind' => 'date', 'value' => '2026-07-15'];
        $normal = $pricing['phases'][0];
        $normal['phase_kind'] = 'normal';
        $normal['starts'] = ['kind' => 'date', 'value' => '2026-07-16'];
        $normal['ends'] = ['kind' => 'none', 'value' => null];
        $intro['components'] = array_values(array_filter($intro['components'], fn ($component) => $component['component_type'] === 'monthly_fee'));
        $intro['components'][0]['amount'] = 0;
        $pricing['phases'] = [$intro, $normal];
        $calculator = app(CanonicalContractPriceCalculator::class);
        $parser = new CanonicalPricingParser;
        foreach (['2026-07-15', '2026-07-16'] as $day) {
            $data = $parser->parse($pricing, $target->canonical_calculation, $target->canonical_source_consistency);
            $candidate = $calculator->resetPremiumCandidate($target->id, $data, ContractContext::fromContract($target), CarbonImmutable::parse($day, 'Europe/Helsinki'));
            $this->assertNotNull($candidate);
            $this->assertSame(array_map(fn ($rate) => (float) $rate, $rates), $candidate->normalizedEnergyRates());
        }
        $pricing['phases'][0]['components'][] = $normal['components'][0];
        $pricing['phases'][0]['components'][1]['amount'] += 1;
        $data = $parser->parse($pricing, $target->canonical_calculation, $target->canonical_source_consistency);
        $this->assertNull($calculator->resetPremiumCandidate($target->id, $data, ContractContext::fromContract($target), CarbonImmutable::parse('2026-07-16', 'Europe/Helsinki')));
    }

    /** Current fee-only/partial tariff, followed by genuinely future full energy terms. */
    private function futureEnergyPricing(ElectricityContract $contract): array
    {
        $pricing = $contract->canonical_pricing;
        $current = $pricing['phases'][0];
        $current['phase_kind'] = 'current_structured';
        $current['ends'] = ['kind' => 'date', 'value' => '2026-09-30'];
        $future = $pricing['phases'][0];
        $future['phase_kind'] = 'future';
        $future['starts'] = ['kind' => 'date', 'value' => '2026-10-01'];
        foreach ($future['components'] as &$component) {
            $component['price_role'] = 'future';
            if ($component['component_type'] === 'monthly_fee') {
                $component['amount'] = 6;
            }
        }
        unset($component);
        // General loses its only energy bucket; Time and Season lose one named bucket.
        array_shift($current['components']);
        $pricing['phases'] = [$current, $future];

        return $pricing;
    }

    #[DataProvider('tariffs')]
    public function test_hybrid_reset_uses_same_base_forecast_and_its_own_cadence_family(string $metering, array $rates): void
    {
        $plain = $this->contract('plain', 'Ordinary', '2026-04-01', $rates, $metering, cadence: 'quarterly', end: '2026-06-30');
        $target = $this->contract('hybrid', 'Seller', '2026-04-01', $rates, $metering, cadence: 'quarterly', end: '2026-06-30', baseEffectModel: 'Hybrid');
        $this->curve->originalAvailable = true;
        $service = $this->service();
        $ordinary = $service->evaluate($plain, $this->usage(), startDate: $this->date())['outcome'];
        $own = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame($ordinary->totalCost, $own->totalCost);
        $this->assertSame($ordinary->monthlyCosts, $own->monthlyCosts);
        $this->assertSame($ordinary->estimateMethod, $own->estimateMethod);
        $this->assertSame('2026-07', $own->resetEstimate['tail_starts']);
        $this->assertSame('base_only_hybrid', $own->comparability->value);
        $this->assertNull($own->supplierAdjustedEstimate);
        $this->assertNull($own->spotPriceMargin);
        $this->curve->originalAvailable = false;
        $this->contract('cheap-ordinary', 'Seller', '2026-04-10', array_map(fn ($v) => 1, $rates), $metering, cadence: 'quarterly');
        $this->contract('wrong-cadence', 'Seller', '2026-04-10', array_map(fn ($v) => 2, $rates), $metering, baseEffectModel: 'Hybrid');
        $peer = $this->contract('peer', 'Seller', '2026-04-10', $rates, $metering, cadence: 'quarterly', baseEffectModel: 'FixedPrice');
        $this->contract('market', 'Other', '2026-04-10', array_map(fn ($v) => $v + 2, $rates), $metering, cadence: 'quarterly', baseEffectModel: 'Hybrid');
        $service->resetMemoization();
        $selected = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('recurring_forward_premium', $selected->estimateMethod->value);
        $this->assertSame('same_company', $selected->resetEstimate['premium']['source']);
        $this->assertEquals(array_map(fn ($v) => $v - 5, $rates), $selected->resetEstimate['premium']['premiums_by_bucket']);
        $this->assertEqualsWithDelta($own->totalCost, $selected->totalCost, .000001);
        $view = ContractPricingViewData::fromCanonicalOutcome($selected);
        $copy = ContractCardCopy::estimate($view, (new PricingCategoryResolver)->resolve($target));
        $this->assertStringContainsString('Tulevat perushinnat ovat arvioita.', $copy->body);
        $this->assertStringContainsString('ei sisällä kulutusvaikutusta', $copy->body);
        $this->assertSame($selected->totalCost, $service->metricsForContracts(collect([$target]), $this->usage(), $this->date())[$target->id]->pricing()->total());
        $multi = $service->outcomesForContractsAtConsumptions(collect([$target]), [5000], new SpotAssumptions(null, null), $this->date());
        $this->assertSame($selected->totalCost, $multi[$target->id][5000]->totalCost);
        $request = new CanonicalPeriodPricingRequest($this->date(), $this->date()->addDays(30), 300, 5000, []);
        $before = $service->periodEvaluationsForContracts(collect([$target]), $request)[$target->id];
        $this->assertSame($selected->totalCost, $before['annual']->totalCost);
        $this->curve->forwardRate = 1000;
        $after = $service->periodEvaluationsForContracts(collect([$target]), $request)[$target->id];
        $this->assertSame($before['period']->periodTotal, $after['period']->periodTotal);
        $this->assertSame($before['period']->phaseBreakdown, $after['period']->phaseBreakdown);
        $peer->activeContract()->delete();
        $this->curve->forwardRate = 6;
        $service->resetMemoization();
        $market = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('market', $market->resetEstimate['premium']['source']);
        $this->assertEquals(array_map(fn ($v) => $v - 3, $rates), $market->resetEstimate['premium']['premiums_by_bucket']);
    }

    public static function hybridResetTerms(): array
    {
        $cases = [];
        foreach (self::tariffs() as [$metering, $rates]) {
            foreach ([6, 12] as $months) {
                $cases[] = [$metering, $rates, $months];
            }
        }

        return $cases;
    }

    #[DataProvider('hybridResetTerms')]
    public function test_fixed_term_hybrid_reset_matches_ordinary_reset_with_only_real_term_futures(string $metering, array $rates, int $months): void
    {
        $plain = $this->contract('term-ordinary', 'Ordinary', '2026-04-01', $rates, $metering, cadence: 'quarterly', end: '2026-06-30', term: true, termMonths: $months);
        $hybrid = $this->contract('term-hybrid', 'Hybrid', '2026-04-01', $rates, $metering, cadence: 'quarterly', end: '2026-06-30', term: true, baseEffectModel: 'Hybrid', termMonths: $months);
        $peerRates = array_map(fn ($rate) => $rate + 2, $rates);
        $this->contract('ordinary-peer', 'Ordinary', '2026-04-10', $peerRates, $metering, cadence: 'quarterly');
        $this->contract('hybrid-peer', 'Hybrid', '2026-04-10', $peerRates, $metering, cadence: 'quarterly', baseEffectModel: 'Hybrid');
        $this->curve->lastDelivery = $this->date()->addMonthsNoOverflow($months)->subDay()->format('Y-m');
        $service = $this->service();
        $outcomes = $service->outcomesForContractsAtConsumptions(collect([$plain, $hybrid]), [5000], new SpotAssumptions(null, null), $this->date());
        $ordinary = $outcomes[$plain->id][5000];
        $actual = $outcomes[$hybrid->id][5000];
        $this->assertSame('recurring_forward_premium', $ordinary->estimateMethod->value);
        $this->assertSame($ordinary->estimateMethod, $actual->estimateMethod);
        $this->assertSame('base_only_hybrid', $actual->comparability->value);
        $this->assertContains('excludes_consumption_effect', $actual->assumptions);
        $this->assertTrue($actual->consumptionEffect->present);
        $this->assertNull($actual->consumptionEffect->expectedCentsPerKwh);
        $this->assertNull($actual->supplierAdjustedEstimate);
        $this->assertSame('2026-07', $actual->resetEstimate['tail_starts']);
        $this->assertSame('same_company', $actual->resetEstimate['premium']['source']);
        $this->assertSame($ordinary->resetEstimate['premium']['premiums_by_bucket'], $actual->resetEstimate['premium']['premiums_by_bucket']);
        $this->assertSame($ordinary->totalCost, $actual->totalCost);
        $this->assertSame($ordinary->baseTotalCost, $actual->baseTotalCost);
        $this->assertSame($ordinary->contractTermTotalCost, $actual->contractTermTotalCost);
        $this->assertSame($ordinary->monthlyCosts, $actual->monthlyCosts);
        if ($months === 6) {
            $this->assertSame(6, $actual->termMonths);
            $this->assertEqualsWithDelta($actual->totalCost, 2 * $actual->contractTermTotalCost, .000001);
        }
        $this->assertSame($this->curve->lastDelivery, max($this->curve->forwardMonths));
        $data = (new CanonicalPricingParser)->parse($hybrid->canonical_pricing, $hybrid->canonical_calculation, $hybrid->canonical_source_consistency);
        $calculator = app(CanonicalContractPriceCalculator::class);
        $context = ContractContext::fromContract($hybrid);
        $this->assertNotNull($calculator->resetPremiumCandidate($hybrid->id, $data, $context, $this->date()));
        $this->assertNull($calculator->supplierAdjustedCandidate($hybrid->id, $data, $context, $this->date()));
        $historical = $calculator->calculate($data, $context, $this->usage(), new SpotAssumptions(null, null), $this->date(), policy: ComparisonPolicy::Historical);
        $this->assertNotSame('recurring_forward_premium', $historical->estimateMethod->value);
        $this->assertArrayNotHasKey('premium', $historical->resetEstimate ?? []);
        $this->assertSame($historical->monthlyCosts[0], $actual->monthlyCosts[0]);
        $this->assertSame($historical->monthlyCosts[1], $actual->monthlyCosts[1]);
        $payload = ContractPricingViewData::fromCanonicalOutcome($actual)->toArray();
        $this->assertSame('recurring_forward_premium', $payload['estimate_method']);
        $this->assertSame('base_only_hybrid', $payload['comparability']);
    }

    public function test_hybrid_reset_candidates_fail_closed_and_flag_off_keeps_base_only(): void
    {
        $target = $this->contract('hybrid', 'Seller', '2026-04-01', baseEffectModel: 'Hybrid');
        $calculator = app(CanonicalContractPriceCalculator::class);
        $context = ContractContext::fromContract($target);
        foreach (['missing_energy', 'unknown_charge', 'wrong_fee_unit', 'optional_fixing'] as $failure) {
            $pricing = $target->canonical_pricing;
            if ($failure === 'missing_energy') {
                array_shift($pricing['phases'][0]['components']);
            } elseif ($failure === 'unknown_charge') {
                $pricing['phases'][0]['components'][] = CanonicalPricingFixture::component(ComponentType::Other, 1, ComponentUnit::CentsPerKwh);
            } elseif ($failure === 'wrong_fee_unit') {
                $pricing['phases'][0]['components'][1]['unit'] = 'cents_per_kwh';
            } else {
                $pricing['consumption_effect']['applies_to'] = 'optional_fixing';
            }
            $data = (new CanonicalPricingParser)->parse($pricing, $target->canonical_calculation, $target->canonical_source_consistency);
            $this->assertNull($calculator->resetPremiumCandidate($target->id, $data, $context, $this->date()), $failure);
        }
        config()->set('canonical_pricing.reset_forward_shift.enabled', false);
        $this->app->forgetScopedInstances();
        $outcome = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('hybrid_base_only', $outcome->estimateMethod->value);
        $this->assertSame('base_only_hybrid', $outcome->comparability->value);
        $this->assertNull($outcome->resetEstimate);
        $this->assertNull($outcome->supplierAdjustedEstimate);
    }

    private function publishPricing(ElectricityContract $contract, array $pricing): void
    {
        $contract->update(['canonical_pricing' => $pricing]);
        $publication = ContractInterpretation::findOrFail($contract->published_interpretation_id);
        $output = $publication->output;
        $output['pricing'] = $pricing;
        $publication->update(['output' => $output]);
    }

    private function peerQueries(): array
    {
        return array_values(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'premium_observation')));
    }

    private function service(): CanonicalContractPricingService
    {
        return app(CanonicalContractPricingService::class)->withSpotAssumptions(new SpotAssumptions(null, null));
    }

    private function date(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-04-15', 'Europe/Helsinki');
    }

    private function usage(): EnergyUsage
    {
        return new EnergyUsage(total: 5000, basicLiving: 5000);
    }

    private function contract(string $id, string $company, string $start, array $rates = ['energy_general' => 8], string $metering = 'General', string $audience = 'Household', string $cadence = 'monthly', ?string $end = null, float $fee = 4, bool $term = false, string $status = 'estimate_required', ?string $baseEffectModel = null, int $termMonths = 6): ElectricityContract
    {
        Company::firstOrCreate(['name' => $company], ['name_slug' => strtolower($company)]);
        $attributes = CanonicalPricingFixture::fixedAttributes();
        $components = [];
        foreach ($rates as $bucket => $rate) {
            $component = CanonicalPricingFixture::component(ComponentType::from($bucket), $rate, ComponentUnit::CentsPerKwh);
            $component['vat_status'] = $audience === 'Company' ? 'excluded' : 'included';
            $components[] = $component;
        }
        $feeComponent = CanonicalPricingFixture::component(ComponentType::MonthlyFee, $fee, ComponentUnit::EurPerMonth);
        $feeComponent['vat_status'] = $audience === 'Company' ? 'excluded' : 'included';
        $components[] = $feeComponent;
        $attributes['canonical_pricing']['phases'][0]['components'] = $components;
        $attributes['canonical_pricing']['phases'][0]['phase_kind'] = 'recurring_period';
        $attributes['canonical_pricing']['phases'][0]['ends'] = ['kind' => 'none', 'value' => null];
        $attributes['canonical_pricing']['recurring_schedule'] = [
            'present' => true, 'cadence' => $cadence, 'current_period_start' => $start,
            'current_period_end' => $end, 'future_price_known' => false, 'description' => null, 'evidence' => [],
        ];
        $attributes['canonical_calculation']['status'] = $status;
        if ($baseEffectModel !== null) {
            $attributes['canonical_pricing']['consumption_effect']['present'] = true;
            $attributes['canonical_pricing']['consumption_effect']['applies_to'] = 'base_contract';
            $attributes['canonical_calculation']['status'] = 'unsupported';
        }
        $contract = ElectricityContract::factory()->active()->forCompany($company)->create([
            'id' => $id, 'contract_type' => $term ? 'FixedTerm' : 'OpenEnded', 'fixed_time_range' => $term ? 'Fixed'.$termMonths : null,
            'pricing_model' => $baseEffectModel ?? 'FixedPrice', 'metering' => $metering, 'target_group' => $audience, ...$attributes,
        ]);
        $source = ContractSourceSnapshot::create([
            'contract_id' => $id, 'source_fingerprint' => hash('sha256', $id),
            'source_payload' => ['Details' => ['PricingModel' => $baseEffectModel ?? 'FixedPrice', 'ContractType' => $contract->contract_type, 'Metering' => $metering, 'TargetGroup' => $audience]],
            'first_observed_at' => '2026-04-10 00:00:00', 'last_observed_at' => '2026-04-14 12:00:00',
        ]);
        $observation = ContractSourceObservation::create([
            'contract_id' => $id, 'source_snapshot_id' => $source->id,
            'first_observed_at' => '2026-04-10 00:00:00', 'last_observed_at' => '2026-04-14 12:00:00',
        ]);
        $publication = ContractInterpretation::create([
            'contract_id' => $id, 'source_snapshot_id' => $source->id, 'analysis_source_observation_id' => $observation->id,
            'analysis_fingerprint' => hash('sha256', 'analysis'.$id), 'status' => 'published', 'schema_version' => 'test',
            'prompt_version' => 'test', 'validator_version' => 'test', 'provider' => 'test', 'model' => 'test',
            'output' => ['pricing' => $attributes['canonical_pricing'], 'calculation' => $attributes['canonical_calculation'], 'source_consistency' => $attributes['canonical_source_consistency']],
            'validation_errors' => [], 'completed_at' => '2026-04-10 01:00:00',
        ]);
        $contract->update(['current_source_observation_id' => $observation->id, 'published_interpretation_id' => $publication->id]);

        return $contract;
    }
}

class ResetPremiumCurve implements MarketReferenceCurveProvider
{
    public bool $originalAvailable = false;

    public bool $currentAvailable = true;

    public ?string $missingAnchorMonth = null;

    public ?string $missingReferenceBound = null;

    public ?string $lastDelivery = null;

    public ?string $referenceTradeOverride = null;

    public array $forwardMonths = [];

    public ?array $seasonal = null;

    public float $forwardRate = 6.0;

    public function tradeDate(CarbonImmutable $asOfDate): ?CarbonImmutable
    {
        return $this->currentAvailable && ($this->originalAvailable || $asOfDate->toDateString() > '2026-04-08') ? $asOfDate->subDay() : null;
    }

    public function referencePrice(CarbonImmutable $asOfDate, CarbonImmutable $anchorMonth, array $kindPreference): ?array
    {
        if ($this->tradeDate($asOfDate) === null || ($this->missingAnchorMonth !== null && $anchorMonth->format('Y-m') === $this->missingAnchorMonth)
            || $asOfDate->toDateString() === $this->missingReferenceBound) {
            return null;
        }

        return ['kind' => $kindPreference[0], 'price_cents_per_kwh' => 5.0, 'trade_date' => $this->referenceTradeOverride ?? $asOfDate->subDay()->toDateString()];
    }

    public function forwardPriceForMonth(CarbonImmutable $asOfDate, CarbonImmutable $deliveryMonth): ?array
    {
        $this->forwardMonths[] = $deliveryMonth->format('Y-m');

        return $this->currentAvailable && ($this->lastDelivery === null || $deliveryMonth->format('Y-m') <= $this->lastDelivery) ? ['kind' => 'month', 'price_cents_per_kwh' => $this->forwardRate] : null;
    }

    public function spotSeasonalIndex(CarbonImmutable $asOfDate): ?array
    {
        return $this->seasonal;
    }

    public function fixedTermMedianEnergyPrice(): ?float
    {
        return null;
    }
}
