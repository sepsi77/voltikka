<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractPriceAnnualCost;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractPriceSnapshot;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\ContractStatistics\AsOfAnnualCostEvidenceResolver;
use App\Services\ContractStatistics\ContractPriceBasis;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use App\Services\ContractStatistics\DTO\AsOfAnnualCostEvidence;
use App\Services\ContractStatistics\Enums\AnnualCostCalculationBasis;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use App\Services\ContractStatistics\SellerSetEnergyPriceIndexService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerSetEnergyPriceIndexTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = SellerSetEnergyPriceIndexService::BASKET_DATE;

    public function test_writer_applies_eligibility_company_medians_fixed_weights_and_separate_hybrid_base(): void
    {
        $this->offer('fixed-a-1', 'Fixed A', 'fixed_term_6', 8.0, contractType: 'FixedTerm');
        $this->offer('fixed-a-2', 'Fixed A', 'fixed_term_24', 10.0, contractType: 'FixedTerm');
        $this->offer('fixed-b', 'Fixed B', 'fixed_term_over24', 12.0, contractType: 'FixedTerm');
        $this->offer('fixed-c', 'Fixed C', 'fixed_term_12', 10.5, contractType: 'FixedTerm');
        $this->offer('open', 'Open C', 'open_ended', 7.0, targetGroup: 'Both');
        $this->offer('open-d', 'Open D', 'open_ended', 7.0);
        $this->offer('open-e', 'Open E', 'open_ended', 7.0);
        $this->offer('reset', 'Reset D', 'quarterly', 6.0, targetGroup: null);
        $this->offer('reset-e', 'Reset E', 'quarterly', 6.0);
        $this->offer('reset-f', 'Reset F', 'market_reset', 6.0);
        $this->offer('hybrid', 'Hybrid E', 'hybrid', 5.0, pricingModel: 'Hybrid');

        $this->offer('spot', 'Excluded Spot', 'spot', 1.0, pricingModel: 'Spot');
        $this->offer('time', 'Excluded Time', 'fixed_term_12', 2.0, contractType: 'FixedTerm', metering: 'Time');
        $this->offer('season', 'Excluded Season', 'open_ended', 2.0, metering: 'Season');
        $this->offer('package', 'Excluded Package', 'open_ended', null);
        $this->offer('local', 'Excluded Local', 'fixed_term_12', 2.0, contractType: 'FixedTerm', national: false);
        $this->offer('business', 'Excluded Business', 'fixed_term_12', 2.0, contractType: 'FixedTerm', targetGroup: 'Company');
        $this->offer('observed', 'Excluded Observed', 'fixed_term_12', 2.0, contractType: 'FixedTerm', snapshotBasis: 'observed_seller_data');
        $this->offer('zero', 'Excluded Zero', 'fixed_term_12', 0.0, contractType: 'FixedTerm');
        $this->offer('negative', 'Excluded Negative', 'fixed_term_12', -1.0, contractType: 'FixedTerm');
        $this->offer('too-high', 'Excluded High', 'fixed_term_12', 50.01, contractType: 'FixedTerm');
        $this->offer('other', 'Excluded Other', 'other', 2.0);

        $written = app(SellerSetEnergyPriceIndexService::class)->writeForDate(self::DATE);

        $this->assertSame(5, $written);
        $rows = ContractPriceDailyStatistic::query()
            ->where('metric_key', SellerSetEnergyPriceIndexService::METRIC_KEY)
            ->get()
            ->keyBy('segment_key');

        $this->assertEqualsWithDelta(10.5, $rows[SellerSetEnergyPriceIndexService::SEGMENT_FIXED_TERM]->avg_value, 0.0001);
        $this->assertEqualsWithDelta(7.0, $rows[SellerSetEnergyPriceIndexService::SEGMENT_OPEN_ENDED]->avg_value, 0.0001);
        $this->assertEqualsWithDelta(6.0, $rows[SellerSetEnergyPriceIndexService::SEGMENT_MARKET_RESET]->avg_value, 0.0001);
        $this->assertEqualsWithDelta(8.545455, $rows[SellerSetEnergyPriceIndexService::SEGMENT_OVERALL]->avg_value, 0.0001);
        $this->assertEqualsWithDelta(5.0, $rows[SellerSetEnergyPriceIndexService::SEGMENT_HYBRID_BASE]->avg_value, 0.0001);
        $this->assertNull($rows[SellerSetEnergyPriceIndexService::SEGMENT_OVERALL]->median_value);
        $this->assertSame('canonical_calculation', $rows[SellerSetEnergyPriceIndexService::SEGMENT_OVERALL]->pricing_basis);
        $this->assertSame(10, $rows[SellerSetEnergyPriceIndexService::SEGMENT_OVERALL]->contract_count);
        $this->assertSame(9, $rows[SellerSetEnergyPriceIndexService::SEGMENT_OVERALL]->basis_counts['supplier_count']);
        $this->assertSame(10, $rows[SellerSetEnergyPriceIndexService::SEGMENT_OVERALL]->basis_counts['contract_count']);
        $this->assertSame(SellerSetEnergyPriceIndexService::FAMILY_WEIGHTS, $rows[SellerSetEnergyPriceIndexService::SEGMENT_OVERALL]->basis_counts['family_weights']);
        $this->assertFalse($rows[SellerSetEnergyPriceIndexService::SEGMENT_HYBRID_BASE]->basis_counts['included_in_overall']);
    }

    public function test_missing_required_family_omits_overall_and_rerun_replaces_all_stale_metric_rows(): void
    {
        $this->offer('fixed', 'Fixed A', 'fixed_term_12', 8.0, contractType: 'FixedTerm');
        $this->offer('fixed-b', 'Fixed B', 'fixed_term_12', 8.0, contractType: 'FixedTerm');
        $this->offer('fixed-c', 'Fixed C', 'fixed_term_12', 8.0, contractType: 'FixedTerm');
        $this->offer('open', 'Open A', 'open_ended', 7.0);
        $this->offer('open-b', 'Open B', 'open_ended', 7.0);
        $this->offer('open-c', 'Open C', 'open_ended', 7.0);
        $this->offer('hybrid', 'Hybrid C', 'hybrid', 5.0, pricingModel: 'Hybrid');
        $this->staleIndexRow(SellerSetEnergyPriceIndexService::SEGMENT_OVERALL, 99.0);
        $this->staleIndexRow('stale-family', 88.0);

        $service = app(SellerSetEnergyPriceIndexService::class);
        $this->assertSame(3, $service->writeForDate(self::DATE));
        $this->assertFalse(ContractPriceDailyStatistic::query()
            ->where('metric_key', SellerSetEnergyPriceIndexService::METRIC_KEY)
            ->where('segment_key', SellerSetEnergyPriceIndexService::SEGMENT_OVERALL)
            ->exists());
        $this->assertFalse(ContractPriceDailyStatistic::query()->where('segment_key', 'stale-family')->exists());

        $this->offer('reset', 'Reset A', 'market_reset', 6.0);
        $this->offer('reset-b', 'Reset B', 'market_reset', 6.0);
        $this->offer('reset-c', 'Reset C', 'market_reset', 6.0);
        $this->assertSame(5, $service->writeForDate(self::DATE));
        $this->assertSame(5, ContractPriceDailyStatistic::query()
            ->where('metric_key', SellerSetEnergyPriceIndexService::METRIC_KEY)
            ->count());
        $this->assertSame(1, ContractPriceDailyStatistic::query()
            ->where('metric_key', SellerSetEnergyPriceIndexService::METRIC_KEY)
            ->where('segment_key', SellerSetEnergyPriceIndexService::SEGMENT_OVERALL)
            ->count());
    }

    public function test_historical_writer_uses_validated_direct_rates_without_annual_cost_membership(): void
    {
        $date = SellerSetEnergyPriceIndexService::SERIES_START_DATE;
        $evidence = [];
        foreach ([
            ['fixed', 'Fixed Historical A', 'FixedTerm', 'FixedPrice', 9.0, false, true],
            ['fixed-b', 'Fixed Historical B', 'FixedTerm', 'FixedPrice', 9.0, false, true],
            ['fixed-c', 'Fixed Historical C', 'FixedTerm', 'FixedPrice', 9.0, false, true],
            ['open', 'Open Historical A', 'OpenEnded', 'FixedPrice', 7.0, false, true],
            ['open-b', 'Open Historical B', 'OpenEnded', 'FixedPrice', 7.0, false, true],
            ['reset', 'Reset Historical A', 'OpenEnded', 'FixedPrice', 6.0, false, true],
            ['reset-b', 'Reset Historical B', 'OpenEnded', 'FixedPrice', 6.0, false, true],
            ['reset-c', 'Reset Historical C', 'OpenEnded', 'FixedPrice', 6.0, false, true],
            ['hybrid', 'Hybrid Historical', 'OpenEnded', 'Hybrid', 5.0, false, true],
            ['no-proof', 'No Proof Historical', 'OpenEnded', 'FixedPrice', 7.0, false, false],
        ] as [$id, $companyName, $type, $model, $rate, $reset, $hasProof]) {
            $company = Company::create(['name' => $companyName, 'name_slug' => $id]);
            ElectricityContract::factory()->forCompany($company)->create([
                'id' => $id,
                'target_group' => 'Household',
                'availability_is_national' => true,
            ]);
            if ($hasProof) {
                ContractPriceAnnualCost::create([
                    'snapshot_date' => $date,
                    'contract_id' => $id,
                    'segment_key' => 'unused',
                    'pricing_basis' => 'observed_seller_data',
                    'consumption_kwh' => 5000,
                    'annual_cost' => 999.0,
                    'method_version' => AnnualCostMethodVersion::AsOf,
                    'calculation_basis' => AnnualCostCalculationBasis::CanonicalOutcome,
                    'estimate_method' => 'unused',
                    'estimate_basis' => 'unused',
                    'compatibility_key' => 'unused',
                ]);
            }
            $evidence[$id] = $this->historicalEvidence(
                $date,
                $id,
                $companyName,
                $type,
                $model,
                $rate,
                $reset,
                str_starts_with($id, 'reset') ? 'quarterly' : 'unused',
            );
        }

        $this->mock(AsOfAnnualCostEvidenceResolver::class)
            ->shouldReceive('resolveDate')
            ->twice()
            ->with($date)
            ->andReturn($evidence);

        $service = app(SellerSetEnergyPriceIndexService::class);
        $preview = $service->previewHistoricalForDate($date);
        $this->assertSame(9, $preview->annualProofCount);
        $this->assertSame(10, $preview->directRateCount);
        $this->assertSame(5, $preview->rowCount);
        $this->assertArrayNotHasKey('missing_canonical_annual_cost_availability_proof', $preview->exclusionCounts);
        $this->assertSame(0, ContractPriceDailyStatistic::query()->where('metric_key', SellerSetEnergyPriceIndexService::METRIC_KEY)->count());

        $summary = $service->writeHistoricalForDate($date);
        $this->assertSame(5, $summary->rowCount);
        $rows = ContractPriceDailyStatistic::query()
            ->where('metric_key', SellerSetEnergyPriceIndexService::METRIC_KEY)
            ->get()
            ->keyBy('segment_key');
        $this->assertEqualsWithDelta(7.795455, $rows[SellerSetEnergyPriceIndexService::SEGMENT_OVERALL]->avg_value, 0.0001);
        $this->assertSame('canonical_calculation', $rows[SellerSetEnergyPriceIndexService::SEGMENT_OVERALL]->pricing_basis);
        $this->assertSame('historical_reconstruction', $rows[SellerSetEnergyPriceIndexService::SEGMENT_OVERALL]->basis_counts['evidence_mode']);
        $this->assertSame(9, $rows[SellerSetEnergyPriceIndexService::SEGMENT_OVERALL]->basis_counts['historical_provenance_counts']['retrospective_historical_interpretation']);
        $this->assertSame(SellerSetEnergyPriceIndexService::BASKET_DATE, $rows[SellerSetEnergyPriceIndexService::SEGMENT_OVERALL]->basis_counts['basket_date']);
        $this->assertSame(SellerSetEnergyPriceIndexService::SERIES_START_DATE, $rows[SellerSetEnergyPriceIndexService::SEGMENT_OVERALL]->basis_counts['series_start_date']);
    }

    public function test_series_boundary_current_feature_off_cleanup_and_historical_observed_preservation(): void
    {
        $this->offer('pre-series', 'Pre-series', 'fixed_term_12', 8.0, contractType: 'FixedTerm', date: '2026-01-20');
        $this->assertSame(0, app(SellerSetEnergyPriceIndexService::class)->writeForDate('2026-01-20'));

        Carbon::setTestNow(self::DATE.' 09:00:00 Europe/Helsinki');
        $company = Company::query()->firstOrCreate(['name' => 'Observed Co'], ['name_slug' => 'observed-co']);
        $contract = ElectricityContract::factory()->forCompany($company)->legacy()->create(['id' => 'observed-contract']);
        foreach ([self::DATE, '2026-08-10'] as $date) {
            PriceComponent::create([
                'id' => 'observed-general-'.$date,
                'electricity_contract_id' => $contract->id,
                'price_component_type' => 'General',
                'price_date' => $date,
                'price' => 7.0,
                'payment_unit' => 'c/kWh',
            ]);
        }
        $this->staleIndexRow(SellerSetEnergyPriceIndexService::SEGMENT_OVERALL, 8.0, self::DATE);
        $this->staleIndexRow(SellerSetEnergyPriceIndexService::SEGMENT_OVERALL, 7.5, '2026-08-10');

        app(ContractPriceStatisticsService::class)->calculateForDate(
            self::DATE,
            [$contract->id],
            overwrite: true,
            useCanonical: false,
        );
        $this->assertFalse(ContractPriceDailyStatistic::query()
            ->where('metric_key', SellerSetEnergyPriceIndexService::METRIC_KEY)
            ->whereDate('stat_date', self::DATE)
            ->exists());

        app(ContractPriceStatisticsService::class)->calculateForDate(
            '2026-08-10',
            [$contract->id],
            overwrite: true,
            useCanonical: false,
        );
        $this->assertTrue(ContractPriceDailyStatistic::query()
            ->where('metric_key', SellerSetEnergyPriceIndexService::METRIC_KEY)
            ->whereDate('stat_date', '2026-08-10')
            ->exists());
        Carbon::setTestNow();
    }

    private function offer(
        string $id,
        string $companyName,
        string $segment,
        ?float $rate,
        string $contractType = 'OpenEnded',
        string $pricingModel = 'FixedPrice',
        string $metering = 'General',
        ?string $targetGroup = 'Household',
        bool $national = true,
        string $snapshotBasis = 'canonical_calculation',
        string $date = self::DATE,
    ): void {
        $company = Company::query()->firstOrCreate(
            ['name' => $companyName],
            ['name_slug' => ElectricityContract::generateSlug($companyName)],
        );
        $contract = ElectricityContract::factory()->forCompany($company)->create([
            'id' => $id,
            'contract_type' => $contractType,
            'fixed_time_range' => $contractType === 'FixedTerm' ? 'Fixed12' : null,
            'pricing_model' => $pricingModel,
            'metering' => $metering,
            'target_group' => $targetGroup,
            'availability_is_national' => $national,
        ]);

        ContractPriceSnapshot::create([
            'snapshot_date' => $date,
            'contract_id' => $contract->id,
            'company_name' => $companyName,
            'contract_name' => $contract->name,
            'pricing_model' => $pricingModel,
            'contract_type' => $contractType,
            'fixed_time_range' => $contract->fixed_time_range,
            'metering' => $metering,
            'segment_key' => $segment,
            'pricing_basis' => $snapshotBasis,
            'energy_price_cents_per_kwh' => $rate,
            'has_discount' => false,
            'includes_spot_price' => false,
        ]);
    }

    private function historicalEvidence(
        string $date,
        string $id,
        string $companyName,
        string $contractType,
        string $pricingModel,
        float $rate,
        bool $reset,
        string $segmentKey = 'unused',
    ): AsOfAnnualCostEvidence {
        $parser = new CanonicalPricingParser;
        $canonical = $parser->parse([
            'phases' => [[
                'label' => 'current',
                'phase_kind' => 'current_structured',
                'starts' => ['kind' => 'contract_start', 'value' => null],
                'ends' => ['kind' => 'none', 'value' => null],
                'components' => [[
                    'component_type' => 'energy_general',
                    'amount' => $rate,
                    'normal_amount' => null,
                    'unit' => 'cents_per_kwh',
                    'vat_status' => 'included',
                    'price_role' => 'current',
                    'source_kind' => 'both',
                    'evidence' => [],
                ]],
                'package' => null,
                'evidence' => [],
            ]],
            'recurring_schedule' => [
                'present' => $reset,
                'cadence' => $reset ? 'quarterly' : 'none',
                'current_period_start' => null,
                'current_period_end' => null,
                'future_price_known' => null,
                'description' => null,
                'evidence' => [],
            ],
            'consumption_effect' => [
                'present' => $pricingModel === 'Hybrid',
                'applies_to' => $pricingModel === 'Hybrid' ? 'general' : 'unknown',
                'cadence' => 'none',
                'expected_cents_per_kwh' => null,
                'typical_min_cents_per_kwh' => null,
                'typical_max_cents_per_kwh' => null,
                'hard_min_cents_per_kwh' => null,
                'hard_max_cents_per_kwh' => null,
                'uncapped' => null,
                'description' => null,
                'evidence' => [],
            ],
        ], ['status' => $pricingModel === 'Hybrid' ? 'unsupported' : 'exact', 'missing_facts' => [], 'required_assumptions' => []], [
            'misleading_first_12_months' => 'not_detected',
            'structured_pricing_status' => 'complete',
            'issue_codes' => [],
        ]);

        return new AsOfAnnualCostEvidence(
            contractId: $id,
            date: CarbonImmutable::parse($date, 'Europe/Helsinki'),
            companyName: $companyName,
            segmentKey: $segmentKey,
            pricingModel: $pricingModel,
            contractType: $contractType,
            fixedTimeRange: $contractType === 'FixedTerm' ? 'Fixed12' : null,
            metering: 'General',
            pricingBasis: ContractPriceBasis::ObservedSellerData,
            priceComponents: [],
            consumptionAvailability: [2000 => true, 5000 => true, 18000 => true],
            canonicalData: $canonical,
            sourceEvidenceIds: [
                'price_snapshot_id' => 1,
                'price_component_ids' => [],
                'observation_ids' => [],
                'source_snapshot_id' => null,
                'interpretation_id' => null,
                'historical_episode_id' => 1,
                'historical_interpretation_id' => 1,
                'historical_evidence_grade' => 'strong',
            ],
            provenanceFlags: ['retrospective_historical_interpretation'],
        );
    }

    private function staleIndexRow(string $segment, float $value, string $date = self::DATE): ContractPriceDailyStatistic
    {
        return ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => $segment,
            'metric_key' => SellerSetEnergyPriceIndexService::METRIC_KEY,
            'pricing_basis' => 'canonical_calculation',
            'method_version' => ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
            'calculation_basis' => SellerSetEnergyPriceIndexService::CALCULATION_BASIS,
            'estimate_basis' => SellerSetEnergyPriceIndexService::ESTIMATE_BASIS,
            'compatibility_key' => SellerSetEnergyPriceIndexService::COMPATIBILITY_KEY,
            'basis_counts' => ['contract_count' => 1, 'supplier_count' => 1],
            'consumption_kwh' => null,
            'avg_value' => $value,
            'contract_count' => 1,
        ]);
    }
}
