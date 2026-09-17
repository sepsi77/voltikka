<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
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
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedEstimateCopy;
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

class CurrentSupplierPremiumIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private SupplierPremiumCurve $curve;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('canonical_pricing.enabled', true);
        $this->app->forgetScopedInstances();
        $this->curve = new SupplierPremiumCurve;
        $this->app->instance(MarketReferenceCurveProvider::class, $this->curve);
    }

    public function test_real_same_company_evidence_prices_evaluate_metrics_statistics_and_period_annual(): void
    {
        $target = $this->contract('target', 'Seller', '2026-02-01', ['energy_general' => 8]);
        $this->contract('peer', 'Seller', '2026-06-15', ['energy_general' => 8]);
        $service = $this->service();
        $outcome = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('supplier_adjusted_forward_premium', $outcome->estimateMethod->value);
        $estimate = $outcome->supplierAdjustedEstimate;
        $this->assertSame('same_company', $estimate['premium']['source']);
        $this->assertSame(['energy_general' => 3.0], $estimate['premium']['premiums_by_bucket']);
        $this->assertSame(['2026-06-14'], $estimate['premium']['reference_trade_dates']);
        $this->assertSame('2026-06-15', $estimate['premium']['references'][0]['pricing_date']);
        $this->assertTrue($estimate['premium']['references'][0]['reference_period_proxy']);
        $this->assertSame('lower', $estimate['premium']['confidence']);
        $this->assertEqualsWithDelta(493.833333, $outcome->totalCost, 0.01);
        $this->assertEqualsWithDelta($outcome->totalCost, $outcome->baseTotalCost, 0.001);
        $this->assertEqualsWithDelta($outcome->totalCost, $outcome->structuredOnlyTotal, 0.001);
        $this->assertEqualsWithDelta(5000 / 12 * .08 + 4, $outcome->monthlyCosts[0], .001);
        $metric = $service->metricsForContracts(collect([$target]), $this->usage(), $this->date())[$target->id];
        $this->assertSame($estimate, $metric->pricing()->toArray()['supplier_adjusted_estimate']);
        $view = ContractPricingViewData::fromCanonicalOutcome($outcome);
        $copy = SupplierAdjustedEstimateCopy::popoverBody($view->supplierAdjustedEstimate());
        $this->assertStringContainsString('nykyisiin sähköfutuureihin', $copy);
        $this->assertStringNotContainsString('ennakkohintoja ei ollut saatavilla', $copy);
        $stats = $service->outcomesForContractsAtConsumptions(collect([$target]), [2000, 5000, 18000], new SpotAssumptions(null, null), $this->date());
        $this->assertSame($outcome->totalCost, $stats['target'][5000]->totalCost);
        $period = $service->periodEvaluationsForContracts(collect([$target]), new CanonicalPeriodPricingRequest($this->date(), $this->date()->addDays(30), 300, 5000, []))['target'];
        $this->assertSame($outcome->totalCost, $period['annual']->totalCost);
        $this->assertEqualsWithDelta(300 * .08 + 4 * 31 / 30, $period['period']->periodTotal, .001);
    }

    public function test_malformed_proven_canonical_peer_is_excluded_without_breaking_a_valid_alternative(): void
    {
        $target = $this->contract('target', 'Seller', '2026-02-01', ['energy_general' => 8]);
        $invalid = $this->contract('invalid-peer', 'Seller', '2026-06-15', ['energy_general' => 20]);
        $pricing = $invalid->canonical_pricing;
        $pricing['phases'][0]['components'][0]['component_type'] = 'invalid_energy_type';
        $invalid->update(['canonical_pricing' => $pricing]);
        $publication = ContractInterpretation::findOrFail($invalid->published_interpretation_id);
        $output = $publication->output;
        $output['pricing'] = $pricing;
        $publication->update(['output' => $output]);
        $this->contract('valid-peer', 'Seller', '2026-06-15', ['energy_general' => 8]);

        $outcome = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];

        $this->assertSame('supplier_adjusted_forward_premium', $outcome->estimateMethod->value);
        $this->assertSame(['energy_general' => 3.0], $outcome->supplierAdjustedEstimate['premium']['premiums_by_bucket']);
        $this->assertSame(1, $outcome->supplierAdjustedEstimate['premium']['observation_count']);
        $this->assertEqualsWithDelta(493.833333, $outcome->totalCost, .01);
    }

    #[DataProvider('observedDates')]
    public function test_current_pointed_peer_keeps_its_real_helsinki_evidence_date(string $timestamp, string $expectedDate): void
    {
        $target = $this->contract('target', 'Seller', '2026-02-01', ['energy_general' => 8]);
        $peer = $this->contract('peer', 'Seller', '2026-06-15', ['energy_general' => 8]);
        ContractSourceObservation::whereKey($peer->current_source_observation_id)->update(['last_observed_at' => $timestamp]);
        $asOf = $this->date()->addDay();

        $outcome = $this->service()->evaluate($target, $this->usage(), startDate: $asOf)['outcome'];

        $this->assertSame('supplier_adjusted_forward_premium', $outcome->estimateMethod->value);
        $this->assertSame($expectedDate, $outcome->supplierAdjustedEstimate['premium']['evidence_through']);
        $this->assertSame(['energy_general' => 3.0], $outcome->supplierAdjustedEstimate['premium']['premiums_by_bucket']);
        $calculator = app(CanonicalContractPriceCalculator::class);
        $data = (new CanonicalPricingParser)->parse($target->canonical_pricing, $target->canonical_calculation, $target->canonical_source_consistency);
        $candidate = $calculator->supplierAdjustedCandidate('target', $data, ContractContext::fromContract($target));
        $premium = (new CurrentPremiumEvidenceLoader($calculator, $this->curve))->forCandidates(['target' => $candidate], collect([$target]), [], $asOf)['target'];
        $this->assertSame($expectedDate, $premium->observations[0]->observedAt->toDateString());
        $this->assertSame($expectedDate, $premium->observations[0]->pricePeriodEnd->toDateString());
        $this->assertSame('2026-06-15', $premium->observations[0]->pricingDate->toDateString());
    }

    public static function observedDates(): array
    {
        return [
            'yesterday pointed data' => ['2026-07-01 18:00:00', '2026-07-01'],
            'no new age floor' => ['2026-06-20 18:00:00', '2026-06-20'],
            'helsinki midnight' => ['2026-07-01 21:00:00', '2026-07-02'],
            'capped at comparison date' => ['2026-07-03 18:00:00', '2026-07-02'],
        ];
    }

    public function test_transferred_payload_requires_current_curve_provenance_at_the_view_boundary(): void
    {
        $target = $this->contract('target', 'Seller', '2026-02-01', ['energy_general' => 8]);
        $this->contract('peer', 'Seller', '2026-06-15', ['energy_general' => 8]);
        $payload = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome']->toCalculatedCostArray();
        $this->assertSame($payload, ContractPricingViewData::fromArray($payload)->toArray());
        $payload['supplier_adjusted_estimate']['curve_trade_date'] = null;
        $this->expectException(\InvalidArgumentException::class);
        ContractPricingViewData::fromArray($payload);
    }

    public function test_market_fallback_and_own_reference_priority(): void
    {
        $target = $this->contract('target', 'Seller', '2026-02-01', ['energy_general' => 8]);
        $this->contract('peer', 'Other', '2026-06-15', ['energy_general' => 8]);
        $this->assertSame('market', $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome']->supplierAdjustedEstimate['premium']['source']);
        $this->curve->februaryAvailable = true;
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $own = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('supplier_adjusted_forward_curve_shift', $own->estimateMethod->value);
        $this->assertArrayNotHasKey('premium', $own->supplierAdjustedEstimate);
        $this->assertCount(0, array_filter($queries, fn ($sql) => str_contains($sql, 'premium_observation')));
    }

    #[DataProvider('incompatiblePeers')]
    public function test_ineligible_or_unproven_peers_do_not_supply_a_premium(string $case): void
    {
        $target = $this->contract('target', 'Seller', '2026-02-01', ['energy_general' => 8]);
        $peer = $this->contract('peer', 'Other', '2026-06-15', ['energy_general' => 8]);
        match ($case) {
            'fixed' => $peer->update(['contract_type' => 'FixedTerm']),
            'spot' => $peer->update(['pricing_model' => 'Spot']),
            'hybrid' => $peer->update(['pricing_model' => 'Hybrid']),
            'vat' => $peer->update(['target_group' => 'Company']),
            'metering' => $peer->update(['metering' => 'Time']),
            'invalid' => ContractInterpretation::whereKey($peer->published_interpretation_id)->update(['validation_errors' => ['invalid']]),
            'future' => ContractInterpretation::whereKey($peer->published_interpretation_id)->update(['completed_at' => '2026-07-02 00:00:00']),
            'source' => $peer->update(['current_source_observation_id' => null]),
            'future_observation' => ContractSourceObservation::whereKey($peer->current_source_observation_id)->update(['first_observed_at' => '2026-07-01 21:00:00']),
            'invalid_observation' => DB::table('contract_source_observations')->where('id', $peer->current_source_observation_id)->update(['last_observed_at' => 'invalid']),
            'reversed_observation' => ContractSourceObservation::whereKey($peer->current_source_observation_id)->update(['last_observed_at' => '2026-06-14 00:00:00']),
            'campaign' => DB::table('contract_source_snapshots')->where('contract_id', $peer->id)->update(['source_payload' => json_encode([
                'Name' => 'Kampanjahinta 8 snt/kWh',
                'Details' => ['PricingModel' => 'FixedPrice', 'ContractType' => 'OpenEnded', 'Metering' => 'General', 'TargetGroup' => 'Household'],
            ])]),
        };
        $outcome = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('hold_current_supplier_price', $outcome->estimateMethod->value);
        $this->assertArrayNotHasKey('premium', $outcome->supplierAdjustedEstimate);
        $this->assertEqualsWithDelta(448, $outcome->totalCost, .001);
    }

    public static function incompatiblePeers(): array
    {
        return array_map(fn ($case) => [$case], ['fixed', 'spot', 'hybrid', 'vat', 'metering', 'invalid', 'future', 'source', 'future_observation', 'invalid_observation', 'reversed_observation', 'campaign']);
    }

    #[DataProvider('tariffs')]
    public function test_transferred_premium_preserves_each_tariff_bucket_and_target_vat(string $metering, array $rates, array $expected): void
    {
        $target = $this->contract('target', 'Seller', '2026-02-01', $rates, $metering);
        $this->contract('peer', 'Other', '2026-06-15', $rates, $metering);
        $outcome = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame($expected, $outcome->supplierAdjustedEstimate['premium']['premiums_by_bucket']);
        $this->assertEqualsWithDelta($outcome->totalCost, $outcome->baseTotalCost, .001);
        $this->assertEqualsWithDelta($outcome->totalCost, $outcome->structuredOnlyTotal, .001);
        // Every later rate is the matching peer bucket plus one cent, not a blended rate.
        $base = $this->serviceWithoutPremium($target);
        $this->assertEqualsWithDelta(5000 * 11 / 12 / 100, $outcome->totalCost - $base->totalCost, .001);
    }

    public static function tariffs(): array
    {
        return [
            ['Time', ['energy_day' => 10, 'energy_night' => 6], ['energy_day' => 5.0, 'energy_night' => 1.0]],
            ['Season', ['energy_seasonal_winter' => 10, 'energy_seasonal_other' => 6], ['energy_seasonal_other' => 1.0, 'energy_seasonal_winter' => 5.0]],
        ];
    }

    public function test_time_transfer_uses_each_peer_bucket_not_a_representative_offset(): void
    {
        $target = $this->contract('target', 'Seller', '2026-02-01', ['energy_day' => 8, 'energy_night' => 8], 'Time');
        $this->contract('peer', 'Other', '2026-06-15', ['energy_day' => 10, 'energy_night' => 6], 'Time');
        $outcome = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertEqualsWithDelta(558, $outcome->totalCost, .001);
        $this->assertEqualsWithDelta(5000 / 12 * .104 + 4, $outcome->monthlyCosts[1], .001);
        $this->assertEqualsWithDelta($outcome->totalCost, $outcome->structuredOnlyTotal, .001);
    }

    public function test_season_transfer_uses_distinct_winter_and_other_premiums(): void
    {
        $target = $this->contract('target', 'Seller', '2026-02-01', ['energy_seasonal_winter' => 8, 'energy_seasonal_other' => 8], 'Season');
        $this->contract('peer', 'Other', '2026-06-15', ['energy_seasonal_winter' => 10, 'energy_seasonal_other' => 6], 'Season');
        $result = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertEqualsWithDelta(5000 / 12 * .07 + 4, $result->monthlyCosts[1], .001);
        $this->assertEqualsWithDelta(5000 / 12 * .104 + 4, $result->monthlyCosts[4], .001);
        $this->assertEqualsWithDelta($result->totalCost, $result->baseTotalCost, .001);
    }

    public function test_no_current_curve_skips_peer_reads_and_no_viable_peer_keeps_seasonal_fallback(): void
    {
        $target = $this->contract('target', 'Seller', '2026-02-01', ['energy_general' => 8]);
        $this->curve->currentAvailable = false;
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $outcome = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('hold_current_supplier_price', $outcome->estimateMethod->value);
        $this->assertCount(0, array_filter($queries, fn ($sql) => str_contains($sql, 'premium_observation')));
        $this->curve->currentAvailable = true;
        $this->curve->seasonal = array_fill(1, 12, 1.0);
        $seasonal = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('supplier_adjusted_spot_seasonal_index', $seasonal->estimateMethod->value);
        $this->assertContains('no_usable_comparable_premium_and_curve_pair', $seasonal->supplierAdjustedEstimate['flags']);
    }

    public function test_company_basis_and_unknown_source_vat_use_the_existing_target_normalization(): void
    {
        $target = $this->contract('target', 'Seller', '2026-02-01', ['energy_general' => 8], audience: 'Company', vat: 'unknown');
        $this->contract('peer', 'Other', '2026-06-15', ['energy_general' => 8], audience: 'Company', vat: 'unknown');
        $result = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertEqualsWithDelta(8 - 5 / 1.255, $result->supplierAdjustedEstimate['premium']['premiums_by_bucket']['energy_general'], .000001);
        $this->assertStringContainsString('unknown_assumed', $result->supplierAdjustedEstimate['premium']['references'][0]['provenance']);
    }

    public function test_peer_reads_are_batched_and_retry_clears_evidence(): void
    {
        $targets = collect();
        for ($i = 0; $i < 8; $i++) {
            $targets->push($this->contract('target-'.$i, 'Seller', '2026-02-01', ['energy_general' => 8]));
        }
        $peer = $this->contract('peer', 'Seller', '2026-06-15', ['energy_general' => 8]);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $service = $this->service();
        $service->metricsForContracts($targets->take(1), $this->usage(), $this->date());
        $oneCount = count($queries);
        $queries = [];
        $service->resetMemoization();
        $service->withSpotAssumptions(new SpotAssumptions(null, null));
        $service->metricsForContracts($targets, $this->usage(), $this->date());
        $this->assertSame($oneCount, count($queries));
        $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, 'premium_observation')));
        $this->assertCount(0, array_filter($queries, fn ($sql) => str_contains($sql, 'price_components')));
        $queries = [];
        $service->metricsForContracts($targets, new EnergyUsage(total: 2000, basicLiving: 2000), $this->date());
        $this->assertCount(0, array_filter($queries, fn ($sql) => str_contains($sql, 'premium_observation')));
        $peer->delete();
        $service->resetMemoization();
        $outcome = $service->evaluate($targets->first(), $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('hold_current_supplier_price', $outcome->estimateMethod->value);
    }

    public function test_premium_plausibility_uses_costed_time_buckets_not_snapshot_weights(): void
    {
        $target = $this->contract('target', 'Seller', '2026-02-01', ['energy_day' => 8, 'energy_night' => 8], 'Time');
        $this->contract('peer', 'Other', '2026-06-15', ['energy_day' => 100, 'energy_night' => 0], 'Time');
        $result = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        // Snapshot 15/9 weights would produce 58.875 c/kWh, below the 60 ceiling.
        // The real 85/15 bill exceeds the ceiling and must reject that projection.
        $this->assertSame('hold_current_supplier_price', $result->estimateMethod->value);
        $this->assertContains('comparable_premium_curve_unavailable_or_implausible', $result->supplierAdjustedEstimate['flags']);
        $this->assertEqualsWithDelta(448, $result->totalCost, .001);
    }

    public function test_actual_peer_universe_deduplicates_clones_and_balances_companies(): void
    {
        $target = $this->contract('target', 'Seller', '2026-02-01', ['energy_general' => 8]);
        $this->contract('a1', 'A', '2026-06-15', ['energy_general' => 8]);
        $this->contract('a2-clone', 'A', '2026-06-15', ['energy_general' => 8]);
        $this->contract('a3', 'A', '2026-06-15', ['energy_general' => 10]);
        $this->contract('b1', 'B', '2026-06-15', ['energy_general' => 20]);
        $premium = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome']->supplierAdjustedEstimate['premium'];
        $this->assertSame(9.5, $premium['premiums_by_bucket']['energy_general']);
        $this->assertSame(2, $premium['company_count']);
        $this->assertSame(3, $premium['independent_variant_count']);
    }

    public function test_negative_peer_spread_is_valid_and_billed_rates_keep_the_zero_floor(): void
    {
        $target = $this->contract('target', 'Seller', '2026-02-01', ['energy_general' => 8]);
        $this->contract('peer', 'Other', '2026-06-15', ['energy_general' => 1]);
        $this->curve->forwardRate = 0.0;
        $result = $this->service()->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame(-4.0, $result->supplierAdjustedEstimate['premium']['premiums_by_bucket']['energy_general']);
        $this->assertEqualsWithDelta(5000 / 12 * .08 + 48, $result->totalCost, .001);
        $this->assertEqualsWithDelta(4, $result->monthlyCosts[1], .001);
    }

    public function test_historical_policy_ignores_supplied_current_premium(): void
    {
        $target = $this->contract('target', 'Seller', '2026-02-01', ['energy_general' => 8]);
        $this->contract('peer', 'Other', '2026-06-15', ['energy_general' => 8]);
        $calculator = app(CanonicalContractPriceCalculator::class);
        $data = (new CanonicalPricingParser)->parse($target->canonical_pricing, $target->canonical_calculation, $target->canonical_source_consistency);
        $context = ContractContext::fromContract($target);
        $anchor = new PriceEpisodeAnchor(CarbonImmutable::parse('2026-02-01', 'Europe/Helsinki'), PriceEpisodeEvidenceBasis::CanonicalSourceObservationRun);
        $candidate = $calculator->supplierAdjustedCandidate('target', $data, $context);
        $premium = (new CurrentPremiumEvidenceLoader($calculator, $this->curve))->forCandidates(['target' => $candidate], collect([$target]), ['target' => $anchor], $this->date())['target'];
        $this->assertNotNull($premium);
        $historical = $calculator->calculate($data, $context, $this->usage(), new SpotAssumptions(null, null), $this->date(), $anchor, policy: ComparisonPolicy::Historical, premium: $premium);
        $this->assertSame('hold_current_supplier_price', $historical->estimateMethod->value);
        $this->assertEqualsWithDelta(448, $historical->totalCost, .001);
    }

    #[DataProvider('unchangedTariffs')]
    public function test_unchanged_energy_phases_keep_forecasts_fees_and_all_current_entrypoints(string $metering, array $rates): void
    {
        $plain = $this->contract('plain', 'Seller', '2026-02-01', $rates, $metering);
        // The only donor has a fully disclosed fee promotion, with immutable proof.
        $this->contract('peer', 'Seller', '2026-06-15', $rates, $metering, variant: 'fee');
        $service = $this->service();
        $baseline = $service->evaluate($plain, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('supplier_adjusted_forward_premium', $baseline->estimateMethod->value);
        foreach (['redundant', 'fee', 'phase_fee', 'inherited', 'normal_baseline', 'equal_normal', 'fee_change'] as $variant) {
            $target = $this->contract($variant, 'Seller', '2026-02-01', $rates, $metering, variant: $variant);
            $service->resetMemoization();
            $result = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
            $saving = in_array($variant, ['fee', 'phase_fee', 'inherited', 'normal_baseline'], true) ? 12 : 0;
            $extraFee = $variant === 'fee_change' ? 36 : 0;
            $this->assertSame($baseline->estimateMethod, $result->estimateMethod, $variant);
            $this->assertEqualsWithDelta($baseline->totalCost - $saving + $extraFee, $result->totalCost, .001, $variant);
            $this->assertEqualsWithDelta($baseline->totalCost + $extraFee, $result->baseTotalCost, .001, $variant);
            $this->assertEqualsWithDelta($saving, $result->measuredDiscountSavingsTotal, .001, $variant);
            foreach ($result->monthlyCosts as $month => $cost) {
                $delta = $saving > 0 && $month < 3 ? -4 : ($extraFee > 0 && $month >= 3 ? 4 : 0);
                $this->assertEqualsWithDelta($baseline->monthlyCosts[$month] + $delta, $cost, .001, $variant);
            }
            $calculator = app(CanonicalContractPriceCalculator::class);
            $data = (new CanonicalPricingParser)->parse($target->canonical_pricing, $target->canonical_calculation, $target->canonical_source_consistency);
            $context = ContractContext::fromContract($target);
            $candidate = $calculator->supplierAdjustedCandidate($target->id, $data, $context, $this->date());
            $this->assertSame($saving > 0 ? 0.0 : 4.0, $candidate->monthlyFeeEur);
            $this->assertSame($saving > 0 || $extraFee > 0 ? 'disclosed_phases' : 'held_flat', $result->supplierAdjustedEstimate['monthly_fee_assumption']);
            if ($saving > 0 || $extraFee > 0) {
                $note = SupplierAdjustedEstimateCopy::receiptNote(ContractPricingViewData::fromCanonicalOutcome($result)->supplierAdjustedEstimate());
                $this->assertStringContainsString('ilmoitetut muutokset ja tarjoukset', $note);
                $this->assertStringNotContainsString('pidetty nykyisellään', $note);
            }
            $this->assertNull($calculator->supplierAdjustedCandidate($target->id, $data, $context, $this->date(), ComparisonPolicy::Historical));
            $historical = $calculator->calculate($data, $context, $this->usage(), new SpotAssumptions(null, null), $this->date(), policy: ComparisonPolicy::Historical);
            $this->assertNull($historical->supplierAdjustedEstimate);
            if ($saving > 0) {
                $normalPricing = $target->canonical_pricing;
                foreach ($normalPricing['phases'] as &$phase) {
                    foreach ($phase['components'] as &$component) {
                        if ($component['component_type'] === 'monthly_fee') {
                            $component['amount'] = 4;
                            $component['normal_amount'] = null;
                            $component['price_role'] = 'current';
                        }
                    }
                    unset($component);
                }
                unset($phase);
                $normalData = (new CanonicalPricingParser)->parse($normalPricing, $target->canonical_calculation, $target->canonical_source_consistency);
                $this->assertNotNull($calculator->supplierAdjustedCandidate('normal', $normalData, $context, $this->date()));
                // Reuse exactly the same own-reference adjustment for both independent bills.
                $anchor = new PriceEpisodeAnchor($this->date()->subMonth(), PriceEpisodeEvidenceBasis::CanonicalSourceObservationRun);
                $actualOwn = $calculator->calculate($data, $context, $this->usage(), new SpotAssumptions(null, null), $this->date(), $anchor);
                $normalOwn = $calculator->calculate($normalData, $context, $this->usage(), new SpotAssumptions(null, null), $this->date(), $anchor);
                $this->assertSame($actualOwn->estimateMethod, $normalOwn->estimateMethod);
                $this->assertEqualsWithDelta($actualOwn->baseTotalCost, $normalOwn->totalCost, .001);
                $this->assertEqualsWithDelta(0, $normalOwn->measuredDiscountSavingsTotal, .001);
            }
            $metric = $service->metricsForContracts(collect([$target]), $this->usage(), $this->date())[$target->id];
            $this->assertEqualsWithDelta($result->totalCost, $metric->pricing()->toArray()['total_cost'], .001);
            $stats = $service->outcomesForContractsAtConsumptions(collect([$target]), [2000, 5000, 18000], new SpotAssumptions(null, null), $this->date());
            $this->assertSame($result->totalCost, $stats[$target->id][5000]->totalCost);
            $period = $service->periodEvaluationsForContracts(collect([$target]), new CanonicalPeriodPricingRequest($this->date(), $this->date()->addDays(30), 300, 5000, []))[$target->id];
            $this->assertSame($result->totalCost, $period['annual']->totalCost);
            $plainPeriod = $service->periodEvaluationsForContracts(collect([$plain]), new CanonicalPeriodPricingRequest($this->date(), $this->date()->addDays(30), 300, 5000, []))[$plain->id]['period'];
            $this->assertEqualsWithDelta($plainPeriod->periodTotal - ($saving > 0 ? 4 * 31 / 30 : 0), $period['period']->periodTotal, .001);
            $this->assertEqualsWithDelta($plainPeriod->periodTotal, $period['period']->normalPeriodTotal, .001);
            $this->assertEqualsWithDelta($saving > 0 ? 4 * 31 / 30 : 0, $period['period']->measuredDiscountSavings, .001);
        }
    }

    public static function unchangedTariffs(): array
    {
        return [['General', ['energy_general' => 8]], ['Time', ['energy_day' => 10, 'energy_night' => 6]], ['Season', ['energy_seasonal_winter' => 10, 'energy_seasonal_other' => 6]]];
    }

    #[DataProvider('unchangedTariffs')]
    public function test_phase_only_fee_offer_uses_its_first_normal_fee_not_a_later_fee_change(string $metering, array $rates): void
    {
        $plain = $this->contract('plain', 'Seller', '2026-06-15', $rates, $metering);
        $target = $this->contract('target', 'Seller', '2026-06-15', $rates, $metering, variant: 'phase_fee');
        $pricing = $target->canonical_pricing;
        $later = $pricing['phases'][1];
        $later['starts'] = ['kind' => 'after_months', 'value' => '6'];
        $later['components'][count($later['components']) - 1]['amount'] = 8;
        $pricing['phases'][1]['ends'] = ['kind' => 'after_months', 'value' => '6'];
        $pricing['phases'][] = $later;
        $calculator = app(CanonicalContractPriceCalculator::class);
        $parser = new CanonicalPricingParser;
        $context = ContractContext::fromContract($target);
        $anchor = new PriceEpisodeAnchor($this->date()->subMonth(), PriceEpisodeEvidenceBasis::CanonicalSourceObservationRun);
        $baseline = $calculator->calculate($parser->parse($plain->canonical_pricing, $plain->canonical_calculation, $plain->canonical_source_consistency), $context, $this->usage(), new SpotAssumptions(null, null), $this->date(), $anchor);
        $result = $calculator->calculate($parser->parse($pricing, $target->canonical_calculation, $target->canonical_source_consistency), $context, $this->usage(), new SpotAssumptions(null, null), $this->date(), $anchor);
        $this->assertSame($baseline->estimateMethod, $result->estimateMethod);
        $this->assertEqualsWithDelta($baseline->totalCost + 24 - 12, $result->totalCost, .001);
        $this->assertEqualsWithDelta($baseline->totalCost + 24, $result->baseTotalCost, .001);
        $this->assertEqualsWithDelta(12, $result->measuredDiscountSavingsTotal, .001);
        $this->assertSame(4.0, $result->offerTerms[0]->components[0]->normalAmount);
    }

    #[DataProvider('unchangedTariffs')]
    public function test_factual_fee_baselines_keep_actual_bills_and_only_measure_applicable_promotions(string $metering, array $rates): void
    {
        $calculator = app(CanonicalContractPriceCalculator::class);
        $parser = new CanonicalPricingParser;
        $start = $this->date()->addDays(14);
        $spot = new SpotAssumptions(null, null);
        foreach (['Household', 'Company'] as $audience) {
            $target = $this->contract($audience, 'Seller', '2026-06-15', $rates, $metering, audience: $audience, variant: 'phase_fee');
            $context = ContractContext::fromContract($target);
            $vat = $audience === 'Company' ? 1 / 1.255 : 1;
            foreach (['phase_only', 'explicit_normal', 'ordinary_change'] as $variant) {
                $pricing = $target->canonical_pricing;
                $feeIndex = count($pricing['phases'][0]['components']) - 1;
                $later = $pricing['phases'][1];
                $later['starts'] = ['kind' => 'after_months', 'value' => '6'];
                $later['components'][$feeIndex]['amount'] = 8;
                $pricing['phases'][1]['ends'] = ['kind' => 'after_months', 'value' => '6'];
                $pricing['phases'][] = $later;
                if ($variant === 'explicit_normal') {
                    // The explicit normal amount is primary, even when the next fee is 4.
                    $pricing['phases'][0]['components'][$feeIndex]['normal_amount'] = 3;
                } elseif ($variant === 'ordinary_change') {
                    $pricing['phases'][0]['phase_kind'] = 'current_structured';
                    $pricing['phases'][0]['components'][$feeIndex]['price_role'] = 'current';
                    $pricing['phases'][0]['components'][$feeIndex]['amount'] = 4;
                }
                $data = $parser->parse($pricing, $target->canonical_calculation, $target->canonical_source_consistency);
                $annual = $calculator->calculate($data, $context, $this->usage(), $spot, $start);
                // This status bypasses only the new unchanged-energy gate. The same
                // original phases exercise the former period arithmetic as a control.
                $controlCalculation = $target->canonical_calculation;
                $controlCalculation['status'] = 'estimate_required';
                $controlData = $parser->parse($pricing, $controlCalculation, $target->canonical_source_consistency);
                foreach ([1, 4, 7, 12] as $months) {
                    $until = $start->addMonthsNoOverflow($months);
                    $request = new CanonicalPeriodPricingRequest($start, $until->subDay(), 5000 * $months / 12, 5000, []);
                    $result = $calculator->calculatePeriod($data, $context, $request, $spot, $annual);
                    $control = $calculator->calculatePeriod($controlData, $context, $request, $spot, $annual);
                    $this->assertSame($control->periodTotal, $result->periodTotal);
                    $this->assertSame($control->phaseBreakdown, $result->phaseBreakdown);
                    $promoFee = match ($variant) {
                        'ordinary_change' => 0,
                        'explicit_normal' => 3,
                        default => 4,
                    };
                    $promoDays = $start->diffInDays($until->min($start->addMonthsNoOverflow(3)));
                    $expectedSaving = $promoFee * $vat * $promoDays / 30;
                    $this->assertEqualsWithDelta($expectedSaving, $result->measuredDiscountSavings, .000001);
                    $this->assertEqualsWithDelta($result->periodTotal + $expectedSaving, $result->normalPeriodTotal, .000001);
                    $this->assertFalse($result->usesSpot);
                    $this->assertFalse(collect($result->assumptions)->contains(fn ($value) => str_starts_with($value, 'supplier_adjusted_')));
                }
            }
        }
    }

    #[DataProvider('unchangedTariffs')]
    public function test_explicit_fee_normal_metadata_is_primary_without_hiding_energy_equal_metadata_offers(string $metering, array $rates): void
    {
        $target = $this->contract('target', 'Seller', '2026-06-15', $rates, $metering, variant: 'phase_fee');
        $calculator = app(CanonicalContractPriceCalculator::class);
        $parser = new CanonicalPricingParser;
        $context = ContractContext::fromContract($target);
        $spot = new SpotAssumptions(null, null);
        $start = $this->date()->addDays(14);
        foreach (['zero_equal', 'nonzero_equal', 'lower_normal', 'energy_equal', 'explicit_three', 'duplicate_current', 'duplicate_normal'] as $case) {
            $pricing = $target->canonical_pricing;
            $feeIndex = count($pricing['phases'][0]['components']) - 1;
            $actualFee = in_array($case, ['nonzero_equal', 'lower_normal'], true) ? 4 : 0;
            $pricing['phases'][0]['components'][$feeIndex]['amount'] = $actualFee;
            if ($actualFee === 4) {
                $pricing['phases'][1]['components'][$feeIndex]['amount'] = 8;
            }
            $normalFee = match ($case) {
                'zero_equal', 'duplicate_current' => 0,
                'nonzero_equal' => 4,
                'lower_normal', 'explicit_three' => 3,
                default => null,
            };
            $pricing['phases'][0]['components'][$feeIndex]['normal_amount'] = $normalFee;
            if ($case === 'energy_equal') {
                foreach ($pricing['phases'][0]['components'] as &$component) {
                    if ($component['component_type'] !== 'monthly_fee') {
                        $component['normal_amount'] = $component['amount'];
                    }
                }
                unset($component);
            } elseif ($case === 'duplicate_current') {
                $duplicate = $pricing['phases'][0]['components'][$feeIndex];
                $duplicate['normal_amount'] = null;
                $pricing['phases'][0]['components'][] = $duplicate;
            } elseif ($case === 'duplicate_normal') {
                $duplicate = $pricing['phases'][1]['components'][$feeIndex];
                $duplicate['amount'] = 8;
                $pricing['phases'][1]['components'][] = $duplicate;
            }
            $data = $parser->parse($pricing, $target->canonical_calculation, $target->canonical_source_consistency);
            $annual = $calculator->calculate($data, $context, $this->usage(), $spot, $start);
            $historical = $calculator->calculate($data, $context, $this->usage(), $spot, $start, policy: ComparisonPolicy::Historical);
            $this->assertSame($historical->totalCost, $annual->totalCost, $case);
            $benefitFee = match ($case) {
                'energy_equal' => 4,
                'explicit_three' => 3,
                default => 0,
            };
            $this->assertEqualsWithDelta($benefitFee * 3, $annual->measuredDiscountSavingsTotal, .000001, $case);
            $this->assertEqualsWithDelta($annual->totalCost + $benefitFee * 3, $annual->baseTotalCost, .000001, $case);
            if ($benefitFee === 0) {
                $this->assertSame([], $annual->offerTerms, $case);
            } else {
                $this->assertSame((float) $benefitFee, $annual->offerTerms[0]->components[0]->normalAmount, $case);
            }
            if ($case === 'zero_equal') {
                // The correction is current-scoped; Historical retains its old result.
                $this->assertEqualsWithDelta(12, $historical->measuredDiscountSavingsTotal, .000001);
                $this->assertSame(4.0, $historical->offerTerms[0]->components[0]->normalAmount);
            }
            $controlCalculation = $target->canonical_calculation;
            $controlCalculation['status'] = 'estimate_required';
            $controlData = $parser->parse($pricing, $controlCalculation, $target->canonical_source_consistency);
            foreach ([3, 12] as $months) {
                $request = new CanonicalPeriodPricingRequest($start, $start->addMonthsNoOverflow($months)->subDay(), 5000 * $months / 12, 5000, []);
                $period = $calculator->calculatePeriod($data, $context, $request, $spot, $annual);
                $control = $calculator->calculatePeriod($controlData, $context, $request, $spot, $annual);
                $this->assertSame($control->periodTotal, $period->periodTotal, $case);
                $this->assertSame($control->phaseBreakdown, $period->phaseBreakdown, $case);
                $saving = $benefitFee * 92 / 30;
                $this->assertEqualsWithDelta($saving, $period->measuredDiscountSavings, .000001, $case);
                $this->assertEqualsWithDelta($period->periodTotal + $saving, $period->normalPeriodTotal, .000001, $case);
            }
        }
    }

    #[DataProvider('unsafePhases')]
    public function test_current_candidate_rejects_unsafe_or_energy_changing_phases(string $case): void
    {
        $target = $this->contract('target', 'Seller', '2026-06-15', ['energy_day' => 10, 'energy_night' => 6], 'Time', variant: 'redundant');
        $pricing = $target->canonical_pricing;
        switch ($case) {
            case 'energy_change': $pricing['phases'][1]['components'][0]['amount'] = 11;
                break;
            case 'offsetting_change':
                $pricing['phases'][1]['components'][0]['amount'] = 9.4;
                $pricing['phases'][1]['components'][1]['amount'] = 7;
                break;
            case 'energy_promotion':
                $pricing['phases'][0]['components'][0]['normal_amount'] = 11;
                $pricing['phases'][1]['components'][0]['amount'] = 11;
                break;
            case 'duplicate':
                $component = $pricing['phases'][0]['components'][0];
                $component['amount'] = 0;
                $pricing['phases'][0]['components'][] = $component;
                break;
            case 'unknown': $pricing['phases'][1]['components'] = [];
                break;
            case 'future_borrow': $pricing['phases'][0]['components'] = [array_pop($pricing['phases'][0]['components'])];
                break;
            case 'unknown_role': $pricing['phases'][0]['components'][0]['price_role'] = 'unknown';
                break;
            case 'spot': $pricing['phases'][1]['components'][0]['component_type'] = 'spot_margin';
                break;
            case 'unit': $pricing['phases'][0]['components'][0]['unit'] = 'eur_per_month';
                break;
        }
        $data = (new CanonicalPricingParser)->parse($pricing, $target->canonical_calculation, $target->canonical_source_consistency);
        $this->assertNull(app(CanonicalContractPriceCalculator::class)->supplierAdjustedCandidate('target', $data, ContractContext::fromContract($target), $this->date()));
        $target->update(['canonical_pricing' => $pricing]);
        $publication = ContractInterpretation::findOrFail($target->published_interpretation_id);
        $output = $publication->output;
        $output['pricing'] = $pricing;
        $publication->update(['output' => $output]);
        $plain = $this->contract('plain', 'Seller', '2026-02-01', ['energy_day' => 10, 'energy_night' => 6], 'Time');
        $result = $this->service()->evaluate($plain, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('hold_current_supplier_price', $result->estimateMethod->value);
    }

    public static function unsafePhases(): array
    {
        return array_map(fn ($case) => [$case], ['energy_change', 'offsetting_change', 'energy_promotion', 'duplicate', 'unknown', 'future_borrow', 'unknown_role', 'spot', 'unit']);
    }

    public static function expiryTariffs(): array
    {
        return [...self::unchangedTariffs(), ...array_map(fn ($row) => [$row[0], $row[1], 'Hybrid'], self::unchangedTariffs())];
    }

    #[DataProvider('expiryTariffs')]
    public function test_absolute_fee_expiry_keeps_the_energy_forecast_and_episode(string $metering, array $rates, ?string $baseEffectModel = null): void
    {
        $plain = $this->contract('plain', 'Seller', '2026-06-15', $rates, $metering);
        $service = $this->service();
        $calculator = app(CanonicalContractPriceCalculator::class);
        foreach (['date', 'contract_start'] as $startKind) {
            foreach (['fee', 'normal_baseline'] as $variant) {
                $target = $this->contract($startKind.'-'.$variant, 'Seller', '2026-06-15', $rates, $metering, variant: $variant, baseEffectModel: $baseEffectModel);
                $predecessor = $this->contract('old-'.$target->id, 'Seller', '2026-06-01', $rates, $metering, baseEffectModel: $baseEffectModel);
                $predecessor->update(['replaced_by_contract_id' => $target->id]);
                $pricing = $target->canonical_pricing;
                $pricing['phases'][0]['starts'] = ['kind' => $startKind, 'value' => $startKind === 'date' ? '2026-06-15' : null];
                $pricing['phases'][0]['ends'] = ['kind' => 'date', 'value' => '2026-07-15'];
                $pricing['phases'][1]['starts'] = ['kind' => 'date', 'value' => '2026-07-16'];
                $target->update(['canonical_pricing' => $pricing]);
                $publication = ContractInterpretation::findOrFail($target->published_interpretation_id);
                $output = $publication->output;
                $output['pricing'] = $pricing;
                $publication->update(['output' => $output]);
                $data = (new CanonicalPricingParser)->parse($pricing, $target->canonical_calculation, $target->canonical_source_consistency);
                $context = ContractContext::fromContract($target);
                $this->assertNotNull($calculator->supplierAdjustedCandidate($target->id, $data, $context, CarbonImmutable::parse('2026-07-16', 'Europe/Helsinki')));
                $anchor = null;
                foreach (['2026-07-15' => 0.0, '2026-07-16' => 4.0] as $day => $fee) {
                    $date = CarbonImmutable::parse($day, 'Europe/Helsinki');
                    $candidate = $calculator->supplierAdjustedCandidate($target->id, $data, $context, $date);
                    $this->assertNotNull($candidate, $startKind.' '.$variant.' '.$day);
                    $this->assertEquals($rates, $candidate->energyRates);
                    $this->assertSame($fee, $candidate->monthlyFeeEur);
                    $expected = $service->evaluate($plain, $this->usage(), startDate: $date)['outcome'];
                    $actual = $service->evaluate($target, $this->usage(), startDate: $date)['outcome'];
                    $this->assertSame('supplier_adjusted_forward_curve_shift', $actual->estimateMethod->value);
                    $this->assertSame($expected->estimateMethod, $actual->estimateMethod);
                    $this->assertEqualsWithDelta($expected->supplierAdjustedEstimate['annual_equivalent_energy_price'], $actual->supplierAdjustedEstimate['annual_equivalent_energy_price'], .000001);
                    $episode = $actual->supplierAdjustedEstimate['price_episode_started_at'];
                    $this->assertSame('2026-06-01', $episode);
                    $this->assertSame($anchor ?? $episode, $episode);
                    $anchor = $episode;
                    $request = new CanonicalPeriodPricingRequest($date, $date, 10, 5000, []);
                    $spot = new SpotAssumptions(null, null);
                    $period = $calculator->calculatePeriod($data, $context, $request, $spot, $actual);
                    $controlCalculation = $target->canonical_calculation;
                    $controlCalculation['status'] = 'estimate_required';
                    $controlData = (new CanonicalPricingParser)->parse($pricing, $controlCalculation, $target->canonical_source_consistency);
                    $control = $calculator->calculatePeriod($controlData, $context, $request, $spot, $actual);
                    $this->assertNotNull($period->periodTotal);
                    $this->assertSame($control->periodTotal, $period->periodTotal);
                    $this->assertSame($control->phaseBreakdown, $period->phaseBreakdown);
                    $this->assertNull($calculator->supplierAdjustedCandidate($target->id, $data, $context, $date, ComparisonPolicy::Historical));
                }
            }
        }
    }

    #[DataProvider('unchangedTariffs')]
    public function test_expired_phase_proof_rejects_changed_or_unknown_energy_and_outside_future_plans(string $metering, array $rates): void
    {
        $target = $this->contract('target', 'Seller', '2026-06-15', $rates, $metering, variant: 'fee');
        $pricing = $target->canonical_pricing;
        $pricing['phases'][0]['starts'] = ['kind' => 'date', 'value' => '2026-06-15'];
        $pricing['phases'][0]['ends'] = ['kind' => 'date', 'value' => '2026-07-15'];
        $pricing['phases'][1]['starts'] = ['kind' => 'date', 'value' => '2026-07-16'];
        $calculator = app(CanonicalContractPriceCalculator::class);
        $context = ContractContext::fromContract($target);
        foreach (['changed_past', 'unknown_past', 'partial_past', 'future_outside', 'future_current_gap'] as $case) {
            $unsafe = $pricing;
            if ($case === 'changed_past') {
                $unsafe['phases'][0]['components'][0]['amount'] += 1;
            } elseif ($case === 'unknown_past' || $case === 'future_current_gap') {
                $unsafe['phases'][0]['phase_kind'] = 'current_structured';
                $unsafe['phases'][0]['components'] = [array_last($unsafe['phases'][0]['components'])];
            } elseif ($case === 'partial_past') {
                array_shift($unsafe['phases'][0]['components']);
                $unsafe['phases'][0]['phase_kind'] = 'current_structured';
            } else {
                $future = $unsafe['phases'][1];
                $future['phase_kind'] = 'future';
                $future['starts'] = ['kind' => 'date', 'value' => '2028-01-01'];
                $future['components'][0]['amount'] += 1;
                $unsafe['phases'][] = $future;
            }
            if ($case === 'future_current_gap') {
                $unsafe['phases'][0]['ends']['value'] = '2026-09-30';
                $unsafe['phases'][1]['phase_kind'] = 'future';
                $unsafe['phases'][1]['starts']['value'] = '2026-10-01';
                foreach ($unsafe['phases'][1]['components'] as &$component) {
                    $component['price_role'] = 'future';
                }
                unset($component);
            }
            $data = (new CanonicalPricingParser)->parse($unsafe, $target->canonical_calculation, $target->canonical_source_consistency);
            $this->assertNull($calculator->supplierAdjustedCandidate($target->id, $data, $context, CarbonImmutable::parse('2026-07-16', 'Europe/Helsinki')), $case);
        }
    }

    public static function hybridTariffs(): array
    {
        return [['General', ['energy_general' => 8]], ...self::tariffs()];
    }

    #[DataProvider('hybridTariffs')]
    public function test_hybrid_base_own_reference_matches_ordinary_forecast_and_fee_phases(string $metering, array $rates): void
    {
        foreach (['plain', 'redundant', 'equal_normal', 'fee', 'phase_fee', 'normal_baseline', 'fee_change'] as $variant) {
            $plain = $this->contract('ordinary-'.$variant, 'Ordinary', '2026-06-15', $rates, $metering, variant: $variant);
            foreach (['Hybrid', 'FixedPrice'] as $model) {
                $hybrid = $this->contract($model.'-'.$variant, 'Hybrid', '2026-06-15', $rates, $metering, variant: $variant, baseEffectModel: $model);
                $service = $this->service();
                $ordinary = $service->evaluate($plain, $this->usage(), startDate: $this->date())['outcome'];
                $outcome = $service->evaluate($hybrid, $this->usage(), startDate: $this->date())['outcome'];
                $this->assertSame($ordinary->totalCost, $outcome->totalCost);
                $this->assertSame($ordinary->baseTotalCost, $outcome->baseTotalCost);
                $this->assertSame($ordinary->monthlyCosts, $outcome->monthlyCosts);
                $this->assertSame($ordinary->structuredOnlyTotal, $outcome->structuredOnlyTotal);
                $this->assertSame($ordinary->estimateMethod, $outcome->estimateMethod);
                $this->assertSame('supplier_adjusted_forward_curve_shift', $outcome->estimateMethod->value);
                $this->assertSame('base_only_hybrid', $outcome->comparability->value);
                $this->assertTrue($outcome->consumptionEffect->present);
                $this->assertSame('2026-06-15', $outcome->supplierAdjustedEstimate['price_episode_started_at']);
                $this->assertNull($outcome->spotPriceMargin);
                $view = ContractPricingViewData::fromCanonicalOutcome($outcome);
                $copy = ContractCardCopy::estimate($view, (new PricingCategoryResolver)->resolve($hybrid));
                $this->assertStringContainsString('Tulevat perushinnat ovat arvioita.', $copy->body);
                $this->assertStringContainsString('ei sisällä kulutusvaikutusta', $copy->body);
                $metrics = $service->metricsForContracts(collect([$hybrid]), $this->usage(), $this->date());
                $this->assertSame($outcome->totalCost, $metrics[$hybrid->id]->pricing()->total());
                $multi = $service->outcomesForContractsAtConsumptions(collect([$hybrid]), [5000], new SpotAssumptions(null, null), $this->date());
                $this->assertSame($outcome->totalCost, $multi[$hybrid->id][5000]->totalCost);
                $request = new CanonicalPeriodPricingRequest($this->date(), $this->date()->addMonthsNoOverflow(7)->subDay(), 1000, 5000, []);
                $periods = $service->periodEvaluationsForContracts(collect([$plain, $hybrid]), $request);
                $this->assertSame($outcome->totalCost, $periods[$hybrid->id]['annual']->totalCost);
                $this->assertSame($periods[$plain->id]['period']->periodTotal, $periods[$hybrid->id]['period']->periodTotal);
                $this->assertSame($periods[$plain->id]['period']->normalPeriodTotal, $periods[$hybrid->id]['period']->normalPeriodTotal);
            }
        }
    }

    #[DataProvider('hybridTariffs')]
    public function test_hybrid_missing_reference_uses_only_hybrid_company_then_market(string $metering, array $rates): void
    {
        $target = $this->contract('hybrid-target', 'Seller', '2026-02-01', $rates, $metering, baseEffectModel: 'Hybrid');
        $this->contract('cheap-ordinary', 'Seller', '2026-06-15', array_map(fn ($v) => 1, $rates), $metering);
        $peer = $this->contract('hybrid-peer', 'Seller', '2026-06-15', $rates, $metering, baseEffectModel: 'FixedPrice');
        $this->contract('hybrid-market', 'Other', '2026-06-15', array_map(fn ($v) => $v + 2, $rates), $metering, baseEffectModel: 'Hybrid');
        $service = $this->service();
        $outcome = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('supplier_adjusted_forward_premium', $outcome->estimateMethod->value);
        $this->assertSame('same_company', $outcome->supplierAdjustedEstimate['premium']['source']);
        $this->assertEquals(array_map(fn ($v) => $v - 5, $rates), $outcome->supplierAdjustedEstimate['premium']['premiums_by_bucket']);
        $peer->activeContract()->delete();
        $service->resetMemoization();
        $market = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('market', $market->supplierAdjustedEstimate['premium']['source']);
        $this->assertEquals(array_map(fn ($v) => $v - 3, $rates), $market->supplierAdjustedEstimate['premium']['premiums_by_bucket']);
    }

    public function test_hybrid_missing_anchor_keeps_own_lineage_priority_and_clone_weights(): void
    {
        $target = $this->contract('target-hybrid', 'Seller', '2026-02-01', ['energy_general' => 8], baseEffectModel: 'Hybrid');
        $own = $this->contract('own-hybrid', 'Seller', '2026-06-15', ['energy_general' => 10], baseEffectModel: 'Hybrid');
        $own->update(['replaced_by_contract_id' => $target->id]);
        $this->contract('company-hybrid', 'Seller', '2026-06-15', ['energy_general' => 2], baseEffectModel: 'Hybrid');
        $service = $this->service();
        $premium = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome']->supplierAdjustedEstimate['premium'];
        $this->assertSame('own_lineage', $premium['source']);
        $this->assertSame(['energy_general' => 5.0], $premium['premiums_by_bucket']);
        $own->activeContract()->delete();
        $this->contract('clone-hybrid', 'Seller', '2026-06-15', ['energy_general' => 2], baseEffectModel: 'FixedPrice');
        $this->contract('other-hybrid', 'Seller', '2026-06-15', ['energy_general' => 10], baseEffectModel: 'Hybrid');
        $service->resetMemoization();
        $premium = $service->evaluate($target, $this->usage(), startDate: $this->date())['outcome']->supplierAdjustedEstimate['premium'];
        $this->assertSame('same_company', $premium['source']);
        $this->assertSame(2, $premium['independent_variant_count']);
        $this->assertSame(['energy_general' => 1.0], $premium['premiums_by_bucket']);
    }

    public function test_hybrid_disclosure_numbers_do_not_enter_costs_and_unsafe_components_are_not_candidates(): void
    {
        $target = $this->contract('hybrid', 'Seller', '2026-06-15', ['energy_general' => 8], baseEffectModel: 'Hybrid');
        $parser = new CanonicalPricingParser;
        $calculator = app(CanonicalContractPriceCalculator::class);
        $context = new ContractContext('Hybrid', 'OpenEnded', 'General', null, 'Household');
        $original = $parser->parse($target->canonical_pricing, $target->canonical_calculation, $target->canonical_source_consistency);
        $anchor = new PriceEpisodeAnchor(CarbonImmutable::parse('2026-06-15'), PriceEpisodeEvidenceBasis::CanonicalSourceObservationRun);
        $baseline = $calculator->calculate($original, $context, $this->usage(), new SpotAssumptions(null, null), $this->date(), priceEpisodeAnchor: $anchor);
        foreach ([-1000, 0, 1000] as $number) {
            $pricing = $target->canonical_pricing;
            foreach (['expected_cents_per_kwh', 'typical_min_cents_per_kwh', 'typical_max_cents_per_kwh', 'hard_min_cents_per_kwh', 'hard_max_cents_per_kwh'] as $key) {
                $pricing['consumption_effect'][$key] = $number;
            }
            $pricing['phases'][0]['components'][] = CanonicalPricingFixture::component(ComponentType::ConsumptionEffect, $number, ComponentUnit::CentsPerKwh);
            $data = $parser->parse($pricing, $target->canonical_calculation, $target->canonical_source_consistency);
            $this->assertNotNull($calculator->supplierAdjustedCandidate('', $data, $context, $this->date()));
            $actual = $calculator->calculate($data, $context, $this->usage(), new SpotAssumptions(null, null), $this->date(), priceEpisodeAnchor: $anchor);
            $this->assertSame($baseline->totalCost, $actual->totalCost);
            $this->assertNull($calculator->supplierAdjustedCandidate('', $data, $context, $this->date(), ComparisonPolicy::Historical));
        }
        $incompleteCalculation = $target->canonical_calculation;
        $incompleteCalculation['status'] = 'incomplete';
        $incompleteCalculation['missing_facts'] = ['unidentified_billed_charge'];
        $incompleteConsistency = $target->canonical_source_consistency;
        $incompleteConsistency['issue_codes'] = ['insufficient_evidence'];
        $incomplete = $parser->parse($target->canonical_pricing, $incompleteCalculation, $incompleteConsistency);
        $this->assertNull($calculator->supplierAdjustedCandidate('', $incomplete, $context, $this->date()));
        $this->assertFalse($calculator->calculate($incomplete, $context, $this->usage(), new SpotAssumptions(null, null), $this->date())->comparability->isListed());
        foreach (['other', 'missing_energy', 'optional', 'spot', 'package'] as $failure) {
            $pricing = $target->canonical_pricing;
            $testContext = $context;
            if ($failure === 'other') {
                $pricing['phases'][0]['components'][] = CanonicalPricingFixture::component(ComponentType::Other, 2, ComponentUnit::CentsPerKwh);
            } elseif ($failure === 'missing_energy') {
                array_shift($pricing['phases'][0]['components']);
            } elseif ($failure === 'optional') {
                $pricing['consumption_effect']['applies_to'] = 'optional_fixing';
            } elseif ($failure === 'spot') {
                $testContext = new ContractContext('Spot', 'OpenEnded', 'General', null, 'Household');
            } else {
                $pricing['phases'][0]['components'] = [];
                $pricing['phases'][0]['package'] = ['monthly_fee_eur' => 20, 'included_kwh' => 200, 'allowance_cadence' => 'monthly', 'excess_rate_cents_per_kwh' => 10];
            }
            $data = $parser->parse($pricing, $target->canonical_calculation, $target->canonical_source_consistency);
            $this->assertNull($calculator->supplierAdjustedCandidate('', $data, $testContext, $this->date()), $failure);
        }
    }

    #[DataProvider('hybridTariffs')]
    public function test_hybrid_fixed_terms_historical_and_actual_periods_do_not_receive_current_projections(string $metering, array $rates): void
    {
        $target = $this->contract('hybrid', 'Seller', '2026-06-15', $rates, $metering, baseEffectModel: 'Hybrid');
        $data = (new CanonicalPricingParser)->parse($target->canonical_pricing, $target->canonical_calculation, $target->canonical_source_consistency);
        $calculator = app(CanonicalContractPriceCalculator::class);
        $spot = new SpotAssumptions(null, null);
        $context = ContractContext::fromContract($target);
        $historical = $calculator->calculate($data, $context, $this->usage(), $spot, $this->date(), policy: ComparisonPolicy::Historical);
        $this->assertSame('hybrid_base_only', $historical->estimateMethod->value);
        $this->assertNull($historical->supplierAdjustedEstimate);
        foreach ([6, 12, 24] as $months) {
            $fixed = new ContractContext('Hybrid', 'FixedTerm', $metering, 'Fixed'.$months, 'Household');
            $this->assertNull($calculator->supplierAdjustedCandidate('', $data, $fixed, $this->date()));
            $before = $calculator->calculate($data, $fixed, $this->usage(), $spot, $this->date());
            $this->curve->forwardRate = 1000;
            $after = $calculator->calculate($data, $fixed, $this->usage(), $spot, $this->date());
            $this->assertSame($before->totalCost, $after->totalCost);
            $this->assertSame('hybrid_base_only', $after->estimateMethod->value);
            if ($months === 6) {
                $this->assertEqualsWithDelta($after->totalCost, 2 * $after->toCalculatedCostArray()['contract_term']['total_cost'], .000001);
            }
            $this->curve->forwardRate = 6;
        }
        $service = $this->service();
        $request = new CanonicalPeriodPricingRequest($this->date(), $this->date()->addMonthsNoOverflow(12)->subDay(), 5000, 5000, []);
        $before = $service->periodEvaluationsForContracts(collect([$target]), $request)[$target->id];
        $this->curve->forwardRate = 1000;
        $after = $service->periodEvaluationsForContracts(collect([$target]), $request)[$target->id];
        $this->assertSame($before['period']->periodTotal, $after['period']->periodTotal);
        $this->assertSame($before['period']->phaseBreakdown, $after['period']->phaseBreakdown);
        $this->assertSame('base_only_hybrid', $after['period']->comparability->value);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $replay = $calculator->calculate($data, $context, $this->usage(), $spot, $this->date(), policy: ComparisonPolicy::Historical);
        $this->assertSame($historical->totalCost, $replay->totalCost);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    #[DataProvider('hybridTariffs')]
    public function test_hybrid_profile_vat_floors_and_missing_premium_remain_honest(string $metering, array $rates): void
    {
        $plain = $this->contract('plain', 'Ordinary', '2026-06-15', $rates, $metering, audience: 'Company', vat: 'excluded');
        $hybrid = $this->contract('hybrid', 'Seller', '2026-06-15', $rates, $metering, audience: 'Company', vat: 'excluded', baseEffectModel: 'Hybrid');
        $usage = new EnergyUsage(total: 18000, basicLiving: 5000, roomHeating: 13000);
        $service = $this->service();
        foreach ([6, -100] as $forward) {
            $this->curve->forwardRate = $forward;
            $expected = $service->evaluate($plain, $usage, startDate: $this->date())['outcome'];
            $actual = $service->evaluate($hybrid, $usage, startDate: $this->date())['outcome'];
            $this->assertSame($expected->totalCost, $actual->totalCost);
            $this->assertSame($expected->monthlyCosts, $actual->monthlyCosts);
            $this->assertSame('excluded', $actual->vatBasis);
            $this->assertGreaterThanOrEqual(0, $actual->totalCost);
        }
        $this->curve->forwardRate = 6;
        $missing = $this->contract('old-hybrid', 'Missing', '2026-02-01', $rates, $metering, baseEffectModel: 'Hybrid');
        // Company VAT0 and ordinary tariffs cannot donate to the household Hybrid target.
        $service->resetMemoization();
        $fallback = $service->evaluate($missing, $this->usage(), startDate: $this->date())['outcome'];
        $this->assertSame('hybrid_base_only', $fallback->estimateMethod->value);
        $this->assertArrayNotHasKey('premium', $fallback->supplierAdjustedEstimate);
        $this->assertSame('hold_flat', $fallback->supplierAdjustedEstimate['basis']);
        $this->assertContains('no_defensible_comparable_premium', $fallback->supplierAdjustedEstimate['flags']);
    }

    public function test_hybrid_public_api_keeps_published_base_and_forecast_identity(): void
    {
        CarbonImmutable::setTestNow($this->date());
        try {
            $target = $this->contract('hybrid-api', 'Seller', '2026-02-01', ['energy_general' => 8], baseEffectModel: 'Hybrid');
            $this->contract('hybrid-peer', 'Seller', '2026-06-15', ['energy_general' => 8], baseEffectModel: 'Hybrid');
            $response = $this->getJson('/api/contracts/'.$target->id.'?consumption=5000');
            $response->assertOk()->assertJsonPath('data.calculated_cost.comparability', 'base_only_hybrid')
                ->assertJsonPath('data.calculated_cost.estimate_method', 'supplier_adjusted_forward_premium')
                ->assertJsonPath('data.calculated_cost.consumption_effect.present', true)
                ->assertJsonPath('data.calculated_cost.spot_price_margin', null)
                ->assertJsonPath('data.calculated_cost.general_kwh_price', 8);
            $payload = $response->json('data.calculated_cost');
            $this->assertSame($payload, ContractPricingViewData::fromArray($payload)->toArray());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function serviceWithoutPremium(ElectricityContract $target)
    {
        return (new CanonicalContractPricingService(app(CanonicalContractPriceCalculator::class), app(PricingMode::class)))
            ->evaluate($target, $this->usage(), new SpotAssumptions(null, null), $this->date())['outcome'];
    }

    private function service(): CanonicalContractPricingService
    {
        return app(CanonicalContractPricingService::class)->withSpotAssumptions(new SpotAssumptions(null, null));
    }

    private function date(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki');
    }

    private function usage(): EnergyUsage
    {
        return new EnergyUsage(total: 5000, basicLiving: 5000);
    }

    private function contract(string $id, string $company, string $start, array $rates, string $metering = 'General', string $audience = 'Household', string $vat = 'included', string $variant = 'plain', ?string $baseEffectModel = null): ElectricityContract
    {
        Company::firstOrCreate(['name' => $company], ['name_slug' => strtolower($company)]);
        $attributes = CanonicalPricingFixture::fixedAttributes();
        $components = [];
        foreach ($rates as $bucket => $rate) {
            $component = CanonicalPricingFixture::component(ComponentType::from($bucket), $rate, ComponentUnit::CentsPerKwh);
            $component['vat_status'] = $vat;
            $components[] = $component;
        }
        $components[] = CanonicalPricingFixture::component(ComponentType::MonthlyFee, 4, ComponentUnit::EurPerMonth);
        $attributes['canonical_pricing']['phases'][0]['components'] = $components;
        if ($variant === 'equal_normal') {
            foreach ($attributes['canonical_pricing']['phases'][0]['components'] as &$component) {
                $component['normal_amount'] = $component['amount'];
            }
            unset($component);
        } elseif ($variant !== 'plain') {
            $normal = $attributes['canonical_pricing']['phases'][0];
            $normal['phase_kind'] = 'normal';
            $normal['starts'] = ['kind' => 'after_months', 'value' => '3'];
            $intro = $attributes['canonical_pricing']['phases'][0];
            $intro['ends'] = ['kind' => 'after_months', 'value' => '3'];
            $feeIndex = count($components) - 1;
            if (in_array($variant, ['fee', 'phase_fee', 'inherited', 'normal_baseline'], true)) {
                $intro['phase_kind'] = 'introductory';
                $intro['components'][$feeIndex]['amount'] = 0;
                $intro['components'][$feeIndex]['price_role'] = 'introductory';
                if ($variant !== 'phase_fee') {
                    $intro['components'][$feeIndex]['normal_amount'] = 4;
                }
            }
            if ($variant === 'fee_change') {
                $normal['components'][$feeIndex]['amount'] = 8;
            }
            $attributes['canonical_pricing']['phases'] = [$intro, $normal];
            if ($variant === 'normal_baseline') {
                $intro['components'] = [$intro['components'][$feeIndex]];
                $attributes['canonical_pricing']['phases'] = [$intro, $normal];
            }
            if ($variant === 'inherited') {
                $base = $attributes['canonical_pricing']['phases'][1];
                $base['starts'] = ['kind' => 'contract_start', 'value' => null];
                $base['phase_kind'] = 'current_structured';
                $intro['components'] = [$intro['components'][$feeIndex]];
                $attributes['canonical_pricing']['phases'] = [$base, $intro, $normal];
            }
        }
        if ($baseEffectModel !== null) {
            $attributes['canonical_pricing']['consumption_effect']['present'] = true;
            $attributes['canonical_pricing']['consumption_effect']['applies_to'] = 'base_contract';
            $attributes['canonical_calculation']['status'] = 'unsupported';
        }
        $contract = ElectricityContract::factory()->active()->forCompany($company)->create([
            'id' => $id, 'contract_type' => 'OpenEnded', 'pricing_model' => $baseEffectModel ?? 'FixedPrice', 'metering' => $metering, 'target_group' => $audience, ...$attributes,
        ]);
        $source = ContractSourceSnapshot::create([
            'contract_id' => $id, 'source_fingerprint' => hash('sha256', $id),
            'source_payload' => ['Details' => ['PricingModel' => $baseEffectModel ?? 'FixedPrice', 'ContractType' => 'OpenEnded', 'Metering' => $metering, 'TargetGroup' => $audience]],
            'first_observed_at' => $start.' 00:00:00', 'last_observed_at' => '2026-07-01 18:00:00',
        ]);
        $observation = ContractSourceObservation::create([
            'contract_id' => $id, 'source_snapshot_id' => $source->id,
            'first_observed_at' => $start.' 00:00:00', 'last_observed_at' => '2026-07-01 18:00:00',
        ]);
        $publication = ContractInterpretation::create([
            'contract_id' => $id, 'source_snapshot_id' => $source->id, 'analysis_source_observation_id' => $observation->id,
            'analysis_fingerprint' => hash('sha256', 'analysis'.$id), 'status' => 'published', 'schema_version' => 'test',
            'prompt_version' => 'test', 'validator_version' => 'test', 'provider' => 'test', 'model' => 'test',
            'output' => ['pricing' => $attributes['canonical_pricing'], 'calculation' => $attributes['canonical_calculation'], 'source_consistency' => $attributes['canonical_source_consistency']],
            'validation_errors' => [], 'completed_at' => $start.' 01:00:00',
        ]);
        $contract->update(['current_source_observation_id' => $observation->id, 'published_interpretation_id' => $publication->id]);

        return $contract;
    }
}

class SupplierPremiumCurve implements MarketReferenceCurveProvider
{
    public bool $februaryAvailable = false;

    public bool $currentAvailable = true;

    public ?array $seasonal = null;

    public float $forwardRate = 6.0;

    public function tradeDate(CarbonImmutable $asOfDate): ?CarbonImmutable
    {
        return $this->currentAvailable ? $asOfDate->subDay() : null;
    }

    public function referencePrice(CarbonImmutable $asOfDate, CarbonImmutable $anchorMonth, array $kindPreference): ?array
    {
        if ($anchorMonth->month === 2 && ! $this->februaryAvailable) {
            return null;
        }

        return ['kind' => 'month', 'price_cents_per_kwh' => 5.0, 'trade_date' => $asOfDate->subDay()->toDateString()];
    }

    public function forwardPriceForMonth(CarbonImmutable $asOfDate, CarbonImmutable $deliveryMonth): ?array
    {
        return $this->currentAvailable ? ['kind' => 'month', 'price_cents_per_kwh' => $this->forwardRate] : null;
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
