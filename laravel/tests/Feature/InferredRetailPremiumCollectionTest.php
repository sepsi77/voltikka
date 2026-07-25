<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Models\ElectricityFuturesEodPrice;
use App\Models\FixedContractPriceForecast;
use App\Models\RetailPremiumObservation;
use App\Services\RetailPremium\RetailPremiumCrossCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InferredRetailPremiumCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_reset_stores_month_quarter_and_year_candidates_at_observed_vintage(): void
    {
        config()->set('price_forecasting.fixed_term.vat_multiplier', 1.255);
        $this->future('month', '202607', '2026-06-30', 40.0);
        $this->future('quarter', '202607', '2026-06-30', 50.0);
        $this->future('year', '202601', '2026-06-30', 60.0);
        $this->future('month', '202607', '2026-07-01', 99.0);
        $this->contract(
            id: 'monthly-reset',
            pricingModel: 'FixedPrice',
            contractType: 'OpenEnded',
            price: 8.00,
            monthlyFee: 5.00,
            firstObserved: '2026-07-01',
            cadence: 'monthly',
        );

        $this->artisan('retail-premiums:collect')->assertExitCode(0);

        $observations = RetailPremiumObservation::query()->orderBy('reference_kind')->get()->keyBy('reference_kind');
        $this->assertSame(['month', 'quarter', 'year'], $observations->keys()->all());
        $this->assertSame('2026-06-30', $observations['month']->reference_trade_date->toDateString());
        $this->assertEqualsWithDelta(5.02, $observations['month']->reference_price_cents_per_kwh, 0.0001);
        $this->assertEqualsWithDelta(2.98, $observations['month']->retail_premium_cents_per_kwh, 0.0001);
        $this->assertEqualsWithDelta(4.18, $observations['month']->retail_premium_with_fee_cents_per_kwh, 0.0001);
        $this->assertEqualsWithDelta(1.725, $observations['quarter']->retail_premium_cents_per_kwh, 0.0001);
        $this->assertEqualsWithDelta(0.47, $observations['year']->retail_premium_cents_per_kwh, 0.0001);
        $this->assertTrue($observations->every(fn (RetailPremiumObservation $row) => $row->quality === 'inferred'));
    }

    public function test_explicit_excluded_vat_basis_uses_the_vat_excluded_wholesale_reference(): void
    {
        $this->future('month', '202607', '2026-06-30', 40.0);
        $this->contract(
            id: 'business-reset',
            pricingModel: 'FixedPrice',
            contractType: 'OpenEnded',
            price: 7.00,
            monthlyFee: 5.00,
            firstObserved: '2026-07-01',
            cadence: 'monthly',
            vatStatus: 'excluded',
        );

        $this->artisan('retail-premiums:collect')->assertExitCode(0);

        $observation = RetailPremiumObservation::sole();
        $this->assertSame('excluded', $observation->vat_basis);
        $this->assertEqualsWithDelta(4.0, $observation->reference_price_cents_per_kwh, 0.0001);
        $this->assertEqualsWithDelta(3.0, $observation->retail_premium_cents_per_kwh, 0.0001);
    }

    public function test_fixed_term_stores_pure_tenor_candidates_and_existing_term_strip(): void
    {
        config()->set('price_forecasting.fixed_term.vat_multiplier', 1.255);
        $this->future('month', '202607', '2026-05-31', 40.0);
        $this->future('month', '202608', '2026-05-31', 50.0);
        $this->future('quarter', '202607', '2026-05-31', 60.0);
        $this->future('year', '202601', '2026-05-31', 70.0);
        $this->contract(
            id: 'fixed-term',
            pricingModel: 'FixedPrice',
            contractType: 'FixedTerm',
            price: 10.00,
            monthlyFee: 4.00,
            firstObserved: '2026-06-01',
            durationMonths: 2,
        );

        $this->artisan('retail-premiums:collect')->assertExitCode(0);

        $observations = RetailPremiumObservation::all()->keyBy('reference_kind');
        $this->assertEqualsCanonicalizing(
            ['month', 'quarter', 'year', 'term_strip'],
            $observations->keys()->all(),
        );
        $this->assertEqualsWithDelta(
            $observations['month']->reference_price_cents_per_kwh,
            $observations['term_strip']->reference_price_cents_per_kwh,
            0.0001,
        );
        $this->assertSame(2, $observations['term_strip']->source_metadata['duration_months']);
        $this->assertSame('all_monthly', $observations['term_strip']->source_metadata['reference']['coverage_quality']);
    }

    public function test_cross_check_compares_company_dataset_with_market_level_ewma(): void
    {
        config()->set('price_forecasting.fixed_term.vat_multiplier', 1.255);
        $this->future('month', '202607', '2026-05-31', 40.0);
        $this->future('month', '202608', '2026-05-31', 50.0);
        $this->contract(
            id: 'cross-check-fixed',
            pricingModel: 'FixedPrice',
            contractType: 'FixedTerm',
            price: 10.00,
            monthlyFee: 4.00,
            firstObserved: '2026-06-01',
            durationMonths: 2,
        );
        $this->artisan('retail-premiums:collect')->assertExitCode(0);
        FixedContractPriceForecast::create([
            'forecast_date' => '2026-06-01',
            'target_date' => '2026-07-01',
            'horizon_days' => 30,
            'duration_months' => 2,
            'target_quantile' => 'median',
            'current_price_cents_per_kwh' => 10.00,
            'forecast_price_cents_per_kwh' => 10.10,
            'expected_change_cents_per_kwh' => 0.10,
            'hedge_cost_cents_per_kwh' => 5.6475,
            'retail_premium_cents_per_kwh' => 4.3525,
            'normal_retail_premium_cents_per_kwh' => 4.00,
            'fair_price_cents_per_kwh' => 9.6475,
            'gap_cents_per_kwh' => -0.3525,
            'futures_trade_date' => '2026-05-31',
            'coverage_quality' => 'all_monthly',
            'confidence' => 'low',
            'direction' => 'slightly_falling',
            'consumer_signal' => 'neutral',
            'model_version' => 'test-model',
        ]);

        $result = app(RetailPremiumCrossCheckService::class)
            ->compare(now()->setDate(2026, 6, 1))
            ->sole();

        $this->assertEqualsWithDelta(4.3525, $result['dataset_median_retail_premium_cents_per_kwh'], 0.0001);
        $this->assertEqualsWithDelta(0.3525, $result['difference_from_normal_ewma_cents_per_kwh'], 0.0001);
        $this->assertEqualsWithDelta(4.3525, $result['company_medians_cents_per_kwh']['Reference Energy'], 0.0001);
        $this->artisan('retail-premiums:cross-check --as-of=2026-06-01')->assertExitCode(0);
    }

    public function test_zero_retail_price_is_stored_and_flagged_as_an_outlier(): void
    {
        $this->contract(
            id: 'zero-reset',
            pricingModel: 'FixedPrice',
            contractType: 'OpenEnded',
            price: 0.00,
            monthlyFee: 4.00,
            firstObserved: '2026-07-25',
            cadence: 'monthly',
        );

        $this->artisan('retail-premiums:collect')->assertExitCode(0);

        $observation = RetailPremiumObservation::sole();
        $this->assertEqualsWithDelta(0.0, $observation->retail_energy_price_cents_per_kwh, 0.0001);
        $this->assertContains('zero_or_negative_retail_energy_price', $observation->quality_flags);
        $this->assertContains('reference_curve_unavailable', $observation->quality_flags);
    }

    public function test_hybrid_base_is_recorded_as_not_comparable_without_a_premium(): void
    {
        $this->contract(
            id: 'hybrid',
            pricingModel: 'Hybrid',
            contractType: 'OpenEnded',
            price: 7.50,
            monthlyFee: 3.00,
            firstObserved: '2026-07-25',
        );

        $this->artisan('retail-premiums:collect')->assertExitCode(0);

        $observation = RetailPremiumObservation::sole();
        $this->assertSame('hybrid_base', $observation->reference_kind);
        $this->assertSame('not_comparable', $observation->quality);
        $this->assertNull($observation->reference_price_cents_per_kwh);
        $this->assertNull($observation->retail_premium_cents_per_kwh);
        $this->assertContains('hybrid_consumption_effect_not_comparable', $observation->quality_flags);
    }

    private function contract(
        string $id,
        string $pricingModel,
        string $contractType,
        float $price,
        float $monthlyFee,
        string $firstObserved,
        string $cadence = 'none',
        ?int $durationMonths = null,
        string $vatStatus = 'included',
    ): ElectricityContract {
        Company::firstOrCreate(['name' => 'Reference Energy']);
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => 'Reference Energy',
            'name' => $id,
            'contract_type' => $contractType,
            'metering' => 'General',
            'pricing_model' => $pricingModel,
            'target_group' => 'Household',
            'availability_is_national' => true,
        ]);
        ActiveContract::create(['id' => $id]);
        $pricing = $this->pricing($price, $monthlyFee, $cadence, $vatStatus);
        $snapshot = ContractSourceSnapshot::create([
            'contract_id' => $id,
            'source_fingerprint' => hash('sha256', $id.$price.$firstObserved),
            'source_payload' => ['id' => $id, 'price' => $price],
            'first_observed_at' => $firstObserved.' 06:00:00',
            'last_observed_at' => $firstObserved.' 06:00:00',
        ]);
        $interpretation = ContractInterpretation::create([
            'contract_id' => $id,
            'source_snapshot_id' => $snapshot->id,
            'analysis_fingerprint' => hash('sha256', $snapshot->source_fingerprint.'analysis'),
            'status' => ContractInterpretation::STATUS_PUBLISHED,
            'schema_version' => 'schema-v3',
            'prompt_version' => 'prompt-v17',
            'validator_version' => 'validator-v14',
            'provider' => 'test',
            'model' => 'test-model',
            'output' => [
                'classification' => ['fixed_duration_months' => $durationMonths],
                'pricing' => $pricing,
                'confidence' => ['pricing' => 'high'],
            ],
        ]);
        $contract->update([
            'published_interpretation_id' => $interpretation->id,
            'canonical_pricing' => $pricing,
            'canonical_source_consistency' => ['structured_pricing_status' => 'complete'],
            'canonical_calculation' => ['status' => $cadence === 'none' ? 'exact' : 'estimate_required'],
        ]);

        return $contract->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function pricing(float $price, float $monthlyFee, string $cadence, string $vatStatus): array
    {
        return [
            'phases' => [[
                'label' => 'Current price',
                'phase_kind' => $cadence === 'none' ? 'current_structured' : 'recurring_period',
                'starts' => ['kind' => 'date', 'value' => '2026-07-01'],
                'ends' => ['kind' => 'date', 'value' => '2026-07-31'],
                'components' => [[
                    'component_type' => 'energy_general',
                    'amount' => $price,
                    'normal_amount' => null,
                    'unit' => 'cents_per_kwh',
                    'vat_status' => $vatStatus,
                    'price_role' => 'current',
                    'source_kind' => 'structured',
                    'evidence' => [],
                ], [
                    'component_type' => 'monthly_fee',
                    'amount' => $monthlyFee,
                    'normal_amount' => null,
                    'unit' => 'eur_per_month',
                    'vat_status' => $vatStatus,
                    'price_role' => 'current',
                    'source_kind' => 'structured',
                    'evidence' => [],
                ]],
                'evidence' => [],
            ]],
            'recurring_schedule' => [
                'present' => $cadence !== 'none',
                'cadence' => $cadence,
                'current_period_start' => $cadence === 'none' ? null : '2026-07-01',
                'current_period_end' => $cadence === 'none' ? null : '2026-07-31',
            ],
            'consumption_effect' => ['present' => false],
        ];
    }

    private function future(string $maturityType, string $maturity, string $tradeDate, float $settlementPrice): void
    {
        ElectricityFuturesEodPrice::create([
            'exchange' => 'EEX',
            'commodity' => 'POWER',
            'pricing' => 'F',
            'product' => 'Base',
            'area' => 'FI',
            'short_code' => match ($maturityType) {
                'month' => 'FNBM',
                'quarter' => 'FNBQ',
                'year' => 'FNBY',
            },
            'maturity' => $maturity,
            'maturity_type' => $maturityType,
            'trade_date' => $tradeDate,
            'settlement_price' => $settlementPrice,
        ]);
    }
}
