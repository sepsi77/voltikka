<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Models\ElectricityFuturesEodPrice;
use App\Models\PriceComponent;
use App\Models\RetailPremiumObservation;
use App\Services\RetailPremium\RetailPremiumHistoryBackfillService;
use App\Services\RetailPremium\RetailPremiumObservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetailPremiumHistoryBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_spot_history_compresses_daily_rows_and_stitches_contract_rotation(): void
    {
        [$tip, $old] = $this->lineage('Spot', 'OpenEnded');
        $this->price($old, '2026-01-01', 'General', 0.50);
        $this->price($old, '2026-01-01', 'Monthly', 4.00);
        $this->price($old, '2026-01-02', 'General', 0.50);
        $this->price($old, '2026-01-02', 'Monthly', 4.00);
        $this->price($tip, '2026-01-03', 'General', 0.50);
        $this->price($tip, '2026-01-03', 'Monthly', 4.00);
        $this->price($tip, '2026-01-04', 'General', 0.70);
        $this->price($tip, '2026-01-04', 'Monthly', 5.00);
        $this->publishTemplate($tip, '2026-01-05', $this->spotPricing(0.70, 5.00));

        $this->artisan('retail-premiums:collect --include-inactive --only=spot --to=2026-01-04')
            ->assertExitCode(0);

        $rows = RetailPremiumObservation::query()->orderBy('first_observed_date')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(RetailPremiumHistoryBackfillService::METHOD_VERSION, $rows[0]->method_version);
        $this->assertSame('2026-01-01', $rows[0]->first_observed_date->toDateString());
        $this->assertSame('2026-01-03', $rows[0]->last_observed_date->toDateString());
        $this->assertSame($old->id, $rows[0]->contract_id);
        $this->assertEqualsCanonicalizing([$old->id, $tip->id], $rows[0]->source_metadata['period_carrier_ids']);
        $this->assertNull($rows[0]->published_interpretation_id);
        $this->assertNull($rows[0]->source_snapshot_id);
        $this->assertNull($rows[0]->period_start);
        $this->assertNull($rows[0]->period_end);
        $this->assertEqualsWithDelta(0.50, $rows[0]->retail_premium_cents_per_kwh, 0.0001);
        $this->assertEqualsWithDelta(1.46, $rows[0]->retail_premium_with_fee_cents_per_kwh, 0.0001);
        $this->assertContains('historical_relational_components', $rows[0]->quality_flags);
        $this->assertSame('2026-01-04', $rows[1]->first_observed_date->toDateString());
        $this->assertEqualsWithDelta(0.70, $rows[1]->retail_premium_cents_per_kwh, 0.0001);

        $this->artisan('retail-premiums:collect --include-inactive --only=spot --to=2026-01-04')
            ->assertExitCode(0);
        $this->assertSame(2, RetailPremiumObservation::count());
    }

    public function test_later_range_extension_updates_only_the_period_end(): void
    {
        [$tip, $old] = $this->lineage('Spot', 'OpenEnded');

        foreach (['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04'] as $date) {
            $carrier = $date <= '2026-01-02' ? $old : $tip;
            $this->price($carrier, $date, 'General', 0.50);
            $this->price($carrier, $date, 'Monthly', 4.00);
        }

        $this->publishTemplate($tip, '2026-01-05', $this->spotPricing(0.50, 4.00));
        $this->artisan('retail-premiums:collect --include-inactive --only=spot --to=2026-01-02')
            ->assertExitCode(0);
        $first = RetailPremiumObservation::sole();
        $this->assertSame('2026-01-02', $first->last_observed_date->toDateString());

        $this->artisan('retail-premiums:collect --include-inactive --only=spot --from=2026-01-03 --to=2026-01-04')
            ->assertExitCode(0);

        $extended = RetailPremiumObservation::sole();
        $this->assertSame($first->observation_key, $extended->observation_key);
        $this->assertSame('2026-01-01', $extended->first_observed_date->toDateString());
        $this->assertSame('2026-01-04', $extended->last_observed_date->toDateString());
        $this->assertEqualsCanonicalizing([$old->id, $tip->id], $extended->source_metadata['period_carrier_ids']);

        $this->artisan('retail-premiums:collect --include-inactive --only=spot --to=2026-01-02 --overwrite')
            ->assertExitCode(0);
        $this->assertSame(
            '2026-01-04',
            RetailPremiumObservation::sole()->last_observed_date->toDateString(),
        );
    }

    public function test_import_outage_day_keeps_one_period_for_an_unchanged_price(): void
    {
        [$tip, $old] = $this->lineage('Spot', 'OpenEnded');

        // Nothing at all was imported on 2026-01-02, so that day is no evidence of a price change.
        foreach (['2026-01-01', '2026-01-03'] as $date) {
            $this->price($old, $date, 'General', 0.50);
            $this->price($old, $date, 'Monthly', 4.00);
        }

        $this->price($tip, '2026-01-04', 'General', 0.50);
        $this->price($tip, '2026-01-04', 'Monthly', 4.00);
        $this->publishTemplate($tip, '2026-01-05', $this->spotPricing(0.50, 4.00));

        $this->artisan('retail-premiums:collect --include-inactive --only=spot --to=2026-01-04')
            ->assertExitCode(0);

        $row = RetailPremiumObservation::sole();
        $this->assertSame('2026-01-01', $row->first_observed_date->toDateString());
        $this->assertSame('2026-01-04', $row->last_observed_date->toDateString());
        $this->assertContains('observation_gap_bridged_import_outage', $row->quality_flags);
        $this->assertSame(['2026-01-02'], $row->source_metadata['bridged_observation_dates']);
    }

    public function test_a_changed_price_across_an_import_outage_still_starts_a_new_period(): void
    {
        [$tip, $old] = $this->lineage('Spot', 'OpenEnded');
        $this->price($old, '2026-01-01', 'General', 0.50);
        $this->price($old, '2026-01-01', 'Monthly', 4.00);
        $this->price($tip, '2026-01-03', 'General', 0.70);
        $this->price($tip, '2026-01-03', 'Monthly', 4.00);
        $this->publishTemplate($tip, '2026-01-04', $this->spotPricing(0.70, 4.00));

        $this->artisan('retail-premiums:collect --include-inactive --only=spot --to=2026-01-03')
            ->assertExitCode(0);

        $rows = RetailPremiumObservation::query()->orderBy('first_observed_date')->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(0.50, $rows[0]->retail_premium_cents_per_kwh, 0.0001);
        $this->assertEqualsWithDelta(0.70, $rows[1]->retail_premium_cents_per_kwh, 0.0001);
    }

    public function test_lineage_absent_from_a_running_import_splits_the_same_price(): void
    {
        [$tip, $old] = $this->lineage('Spot', 'OpenEnded');
        $other = $this->contract('history-other-product', 'Spot', 'OpenEnded');

        $this->price($old, '2026-01-01', 'General', 0.50);
        $this->price($old, '2026-01-01', 'Monthly', 4.00);
        $this->price($tip, '2026-01-03', 'General', 0.50);
        $this->price($tip, '2026-01-03', 'Monthly', 4.00);

        // The import ran on 2026-01-02 for another product, so this lineage was genuinely off sale.
        $this->price($other, '2026-01-02', 'General', 0.90);
        $this->price($other, '2026-01-02', 'Monthly', 4.00);
        $this->publishTemplate($tip, '2026-01-04', $this->spotPricing(0.50, 4.00));

        $this->artisan('retail-premiums:collect --include-inactive --only=spot --to=2026-01-03')
            ->assertExitCode(0);

        $rows = RetailPremiumObservation::query()->orderBy('first_observed_date')->get();
        $this->assertSame(
            ['2026-01-01', '2026-01-03'],
            $rows->map(fn (RetailPremiumObservation $row) => $row->first_observed_date->toDateString())->all(),
        );
        $this->assertContains('period_follows_lineage_absence', $rows[1]->quality_flags);
        $this->assertSame(['2026-01-02'], $rows[1]->source_metadata['preceding_absent_observation_dates']);
    }

    public function test_unreadable_conflicting_day_does_not_split_an_unchanged_price(): void
    {
        [$tip, $old] = $this->lineage('Spot', 'OpenEnded');
        $this->price($old, '2026-01-01', 'General', 0.50);
        $this->price($old, '2026-01-01', 'Monthly', 4.00);

        // Two lineage carriers disagree on 2026-01-02, so that day cannot be read at all.
        $this->price($old, '2026-01-02', 'General', 0.50);
        $this->price($old, '2026-01-02', 'Monthly', 4.00);
        $this->price($tip, '2026-01-02', 'General', 0.90);
        $this->price($tip, '2026-01-02', 'Monthly', 4.00);
        $this->price($tip, '2026-01-03', 'General', 0.50);
        $this->price($tip, '2026-01-03', 'Monthly', 4.00);
        $this->publishTemplate($tip, '2026-01-04', $this->spotPricing(0.50, 4.00));

        $this->artisan('retail-premiums:collect --include-inactive --only=spot --to=2026-01-03')
            ->assertExitCode(0);

        $row = RetailPremiumObservation::sole();
        $this->assertSame('2026-01-01', $row->first_observed_date->toDateString());
        $this->assertSame('2026-01-03', $row->last_observed_date->toDateString());
        $this->assertContains('observation_gap_bridged_unreadable_day', $row->quality_flags);
    }

    public function test_conflicting_lineage_rows_on_the_same_day_are_skipped(): void
    {
        [$tip, $old] = $this->lineage('Spot', 'OpenEnded');
        $this->price($old, '2026-01-01', 'General', 0.50);
        $this->price($old, '2026-01-01', 'Monthly', 4.00);
        $this->price($tip, '2026-01-01', 'General', 0.70);
        $this->price($tip, '2026-01-01', 'Monthly', 5.00);
        $this->publishTemplate($tip, '2026-01-02', $this->spotPricing(0.70, 5.00));

        $result = app(RetailPremiumHistoryBackfillService::class)->build(
            $tip->fresh(),
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-01-01'),
        );

        $this->assertSame(1, $result['stats']['lineage_overlap_conflicts']);
        $this->assertSame(0, $result['stats']['observations_built']);
    }

    public function test_partial_multi_rate_template_calibration_rejects_the_lineage(): void
    {
        [$tip, $old] = $this->lineage('FixedPrice', 'OpenEnded');
        $tip->update(['metering' => 'Time']);
        $old->update(['metering' => 'Time']);
        $this->price($old, '2026-01-01', 'DayTime', 8.00);
        $this->price($old, '2026-01-01', 'Monthly', 4.00);
        $this->price($tip, '2026-01-02', 'DayTime', 8.00);
        $this->price($tip, '2026-01-02', 'Monthly', 4.00);
        $this->publishTemplate($tip, '2026-01-03', $this->timePricing(8.00, 6.00, 4.00));

        $result = app(RetailPremiumHistoryBackfillService::class)->build(
            $tip->fresh(),
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-01-02'),
        );

        $this->assertSame(1, $result['stats']['uncalibrated_lineages']);
        $this->assertSame(0, $result['stats']['observations_built']);
    }

    public function test_multi_rate_history_keeps_each_component_and_marks_it_unaggregated(): void
    {
        [$tip, $old] = $this->lineage('FixedPrice', 'OpenEnded');
        $tip->update(['metering' => 'Time']);
        $old->update(['metering' => 'Time']);

        foreach ([[$old, '2026-01-01'], [$tip, '2026-01-02']] as [$carrier, $date]) {
            $this->price($carrier, $date, 'DayTime', 8.00);
            $this->price($carrier, $date, 'NightTime', 6.00);
            $this->price($carrier, $date, 'Monthly', 4.00);
        }

        $this->publishTemplate($tip, '2026-01-03', $this->timePricing(8.00, 6.00, 4.00));
        $this->artisan('retail-premiums:collect --include-inactive --only=reset --to=2026-01-02')
            ->assertExitCode(0);

        $rows = RetailPremiumObservation::all();
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['energy_day', 'energy_night'], $rows->pluck('energy_component_type')->all());
        $this->assertTrue($rows->every(
            fn (RetailPremiumObservation $row) => in_array('multi_rate_component_not_aggregated', $row->quality_flags, true),
        ));
    }

    public function test_unknown_historical_vat_keeps_disclosed_spot_value_out_of_premium_fields(): void
    {
        [$tip, $old] = $this->lineage('Spot', 'OpenEnded');
        $this->price($old, '2026-01-01', 'General', 0.50);
        $this->price($old, '2026-01-01', 'Monthly', 4.00);
        $this->price($tip, '2026-01-02', 'General', 0.50);
        $this->price($tip, '2026-01-02', 'Monthly', 4.00);
        $this->publishTemplate($tip, '2026-01-03', $this->spotPricing(0.50, 4.00, 'unknown'));

        $this->artisan('retail-premiums:collect --include-inactive --only=spot --to=2026-01-02')
            ->assertExitCode(0);

        $row = RetailPremiumObservation::sole();
        $this->assertSame('unknown', $row->vat_basis);
        $this->assertNull($row->retail_premium_cents_per_kwh);
        $this->assertNull($row->retail_premium_with_fee_cents_per_kwh);
        $this->assertContains('vat_unknown', $row->quality_flags);
        $storedSpot = collect($row->energy_components)->firstWhere('component_type', 'spot_margin');
        $this->assertEqualsWithDelta(0.50, $storedSpot['amount'], 0.0001);
    }

    public function test_history_vat_is_propagated_from_the_same_contract_disclosure(): void
    {
        [$tip, $old] = $this->lineage('Spot', 'OpenEnded');
        $this->price($old, '2026-01-01', 'General', 0.50);
        $this->price($old, '2026-01-01', 'Monthly', 4.00);
        $this->price($tip, '2026-01-02', 'General', 0.50);
        $this->price($tip, '2026-01-02', 'Monthly', 4.00);
        $this->publishTemplate($tip, '2026-01-03', $this->spotPricing(0.50, 4.00, 'unknown', 'included'));

        $this->artisan('retail-premiums:collect --include-inactive --only=spot --to=2026-01-02')
            ->assertExitCode(0);

        $row = RetailPremiumObservation::sole();
        $this->assertSame('included', $row->vat_basis);
        $this->assertSame('included', $row->fee_vat_basis);
        $this->assertSame('contract_propagated', $row->vat_basis_source);
        $this->assertContains('vat_basis_propagated_within_contract', $row->quality_flags);
        $this->assertEqualsWithDelta(0.50, $row->retail_premium_cents_per_kwh, 0.0001);
        $this->assertEqualsWithDelta(1.46, $row->retail_premium_with_fee_cents_per_kwh, 0.0001);
    }

    public function test_forward_period_is_flagged_when_it_continues_a_history_period(): void
    {
        [$tip, $old] = $this->lineage('Spot', 'OpenEnded');
        $this->price($old, '2026-01-01', 'General', 0.50);
        $this->price($old, '2026-01-01', 'Monthly', 4.00);
        $this->price($tip, '2026-01-02', 'General', 0.50);
        $this->price($tip, '2026-01-02', 'Monthly', 4.00);
        $this->publishTemplate($tip, '2026-01-03', $this->spotPricing(0.50, 4.00));

        $this->artisan('retail-premiums:collect --include-inactive --only=spot --to=2026-01-02')
            ->assertExitCode(0);
        ActiveContract::query()->firstOrCreate(['id' => $tip->id]);
        $this->artisan('retail-premiums:collect --as-of=2026-01-03')->assertExitCode(0);

        $forward = RetailPremiumObservation::query()
            ->where('method_version', RetailPremiumObservationService::METHOD_VERSION)
            ->sole();
        $this->assertSame('2026-01-03', $forward->first_observed_date->toDateString());
        $this->assertContains('continues_prior_history_period', $forward->quality_flags);
        $this->assertSame('2026-01-02', $forward->source_metadata['continued_history_last_observed_date']);
    }

    public function test_market_reset_history_uses_prior_curve_and_records_pre_curve_evidence(): void
    {
        [$tip, $old] = $this->lineage('FixedPrice', 'OpenEnded');
        $this->price($old, '2026-04-07', 'General', 7.00);
        $this->price($old, '2026-04-07', 'Monthly', 5.00);
        $this->price($tip, '2026-05-01', 'General', 8.00);
        $this->price($tip, '2026-05-01', 'Monthly', 5.00);
        $this->future('month', '202605', '2026-04-30', 40.0);
        $this->future('quarter', '202604', '2026-04-30', 50.0);
        $this->future('year', '202601', '2026-04-30', 60.0);
        $this->publishTemplate($tip, '2026-05-02', $this->resetPricing(8.00, 5.00));

        $this->artisan('retail-premiums:collect --include-inactive --only=reset --to=2026-05-01')
            ->assertExitCode(0);

        $rows = RetailPremiumObservation::all();
        $this->assertCount(4, $rows);
        $preCurve = $rows->firstWhere('reference_kind', 'curve_unavailable');
        $this->assertNotNull($preCurve);
        $this->assertSame('2026-04-07', $preCurve->first_observed_date->toDateString());
        $this->assertNull($preCurve->reference_price_cents_per_kwh);
        $this->assertNull($preCurve->retail_premium_cents_per_kwh);
        $month = $rows->firstWhere('reference_kind', 'month');
        $this->assertSame('2026-04-30', $month->reference_trade_date->toDateString());
        $this->assertEqualsWithDelta(5.02, $month->reference_price_cents_per_kwh, 0.0001);
        $this->assertEqualsWithDelta(2.98, $month->retail_premium_cents_per_kwh, 0.0001);
    }

    public function test_fixed_term_history_reuses_the_existing_term_strip_service(): void
    {
        [$tip, $old] = $this->lineage('FixedPrice', 'FixedTerm');
        $tip->update(['fixed_time_range' => 'Fixed6']);
        $old->update(['fixed_time_range' => 'Fixed6']);
        $this->price($old, '2026-06-01', 'General', 10.00);
        $this->price($old, '2026-06-01', 'Monthly', 4.00);
        $this->price($tip, '2026-06-02', 'General', 10.00);
        $this->price($tip, '2026-06-02', 'Monthly', 4.00);

        foreach (['202607', '202608', '202609', '202610', '202611', '202612'] as $maturity) {
            $this->future('month', $maturity, '2026-05-31', 40.0);
        }

        $this->publishTemplate($tip, '2026-06-03', $this->resetPricing(10.00, 4.00, reset: false), durationMonths: 6);

        $this->artisan('retail-premiums:collect --include-inactive --only=fixed-term --to=2026-06-02')
            ->assertExitCode(0);

        $termStrip = RetailPremiumObservation::query()->where('reference_kind', 'term_strip')->sole();
        $this->assertSame('2026-05-31', $termStrip->reference_trade_date->toDateString());
        $this->assertEqualsWithDelta(5.02, $termStrip->reference_price_cents_per_kwh, 0.0001);
        $this->assertEqualsWithDelta(4.98, $termStrip->retail_premium_cents_per_kwh, 0.0001);
    }

    public function test_unresolved_monthly_discount_keeps_fee_inclusive_value_null(): void
    {
        [$tip, $old] = $this->lineage('Spot', 'OpenEnded');
        $this->price($old, '2026-01-01', 'General', 0.50);
        $this->price($old, '2026-01-01', 'Monthly', 5.00, discounted: true);
        $this->price($tip, '2026-01-02', 'General', 0.50);
        $this->price($tip, '2026-01-02', 'Monthly', 5.00, discounted: true);
        $this->publishTemplate($tip, '2026-01-03', $this->spotPricing(0.50, 5.00));

        $this->artisan('retail-premiums:collect --include-inactive --only=spot --to=2026-01-02')
            ->assertExitCode(0);

        $row = RetailPremiumObservation::sole();
        $this->assertNull($row->monthly_fee_eur);
        $this->assertNull($row->retail_premium_with_fee_cents_per_kwh);
        $this->assertContains('discount_effect_unresolved', $row->quality_flags);
    }

    public function test_historical_options_require_explicit_inactive_mode(): void
    {
        $this->artisan('retail-premiums:collect --from=2026-01-01 --to=2026-01-02')
            ->assertExitCode(2);
    }

    public function test_historical_dry_run_does_not_write(): void
    {
        [$tip, $old] = $this->lineage('Spot', 'OpenEnded');
        $this->price($old, '2026-01-01', 'General', 0.50);
        $this->price($old, '2026-01-01', 'Monthly', 4.00);
        $this->price($tip, '2026-01-02', 'General', 0.50);
        $this->price($tip, '2026-01-02', 'Monthly', 4.00);
        $this->publishTemplate($tip, '2026-01-03', $this->spotPricing(0.50, 4.00));

        $this->artisan('retail-premiums:collect --include-inactive --only=spot --dry-run --to=2026-01-02')
            ->assertExitCode(0);

        $this->assertSame(0, RetailPremiumObservation::count());
    }

    /**
     * @return array{ElectricityContract, ElectricityContract}
     */
    private function lineage(string $pricingModel, string $contractType): array
    {
        Company::create(['name' => 'History Energy']);
        $tip = $this->contract('history-tip', $pricingModel, $contractType);
        $old = $this->contract('history-old', $pricingModel, $contractType, $tip->id);
        ActiveContract::create(['id' => $tip->id]);

        return [$tip, $old];
    }

    private function contract(
        string $id,
        string $pricingModel,
        string $contractType,
        ?string $replacedBy = null,
    ): ElectricityContract {
        return ElectricityContract::create([
            'id' => $id,
            'company_name' => 'History Energy',
            'name' => 'History contract',
            'contract_type' => $contractType,
            'fixed_time_range' => $contractType === 'FixedTerm' ? 'Fixed12' : null,
            'metering' => 'General',
            'pricing_model' => $pricingModel,
            'target_group' => 'Household',
            'availability_is_national' => true,
            'replaced_by_contract_id' => $replacedBy,
        ]);
    }

    private function publishTemplate(
        ElectricityContract $tip,
        string $firstObserved,
        array $pricing,
        ?int $durationMonths = null,
    ): void {
        $snapshot = ContractSourceSnapshot::create([
            'contract_id' => $tip->id,
            'source_fingerprint' => hash('sha256', $tip->id.$firstObserved),
            'source_payload' => ['id' => $tip->id],
            'first_observed_at' => $firstObserved.' 06:00:00',
            'last_observed_at' => $firstObserved.' 06:00:00',
        ]);
        $interpretation = ContractInterpretation::create([
            'contract_id' => $tip->id,
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
        $tip->update([
            'published_interpretation_id' => $interpretation->id,
            'canonical_pricing' => $pricing,
            'canonical_source_consistency' => ['structured_pricing_status' => 'complete'],
            'canonical_calculation' => ['status' => 'estimate_required'],
        ]);
    }

    private function spotPricing(
        float $price,
        float $monthlyFee,
        string $vatStatus = 'included',
        ?string $feeVatStatus = null,
    ): array {
        return $this->pricing('spot_margin', $price, $monthlyFee, false, $vatStatus, $feeVatStatus ?? $vatStatus);
    }

    private function resetPricing(float $price, float $monthlyFee, bool $reset = true): array
    {
        return $this->pricing('energy_general', $price, $monthlyFee, $reset, 'included', 'included');
    }

    private function timePricing(float $dayPrice, float $nightPrice, float $monthlyFee): array
    {
        $pricing = $this->pricing('energy_day', $dayPrice, $monthlyFee, true, 'included', 'included');
        $pricing['phases'][0]['components'][] = [
            'component_type' => 'energy_night',
            'amount' => $nightPrice,
            'normal_amount' => null,
            'unit' => 'cents_per_kwh',
            'vat_status' => 'included',
            'price_role' => 'current',
            'source_kind' => 'structured',
            'evidence' => [['source' => 'components[2].price', 'quote' => 'night price']],
        ];

        return $pricing;
    }

    private function pricing(
        string $energyRole,
        float $price,
        float $monthlyFee,
        bool $reset,
        string $vatStatus,
        string $feeVatStatus,
    ): array {
        return [
            'phases' => [[
                'label' => 'Current price',
                'phase_kind' => $reset ? 'recurring_period' : 'current_structured',
                'starts' => ['kind' => 'contract_start', 'value' => null],
                'ends' => ['kind' => 'none', 'value' => null],
                'components' => [[
                    'component_type' => $energyRole,
                    'amount' => $price,
                    'normal_amount' => null,
                    'unit' => 'cents_per_kwh',
                    'vat_status' => $vatStatus,
                    'price_role' => 'current',
                    'source_kind' => 'structured',
                    'evidence' => [['source' => 'components[0].price', 'quote' => 'energy price']],
                ], [
                    'component_type' => 'monthly_fee',
                    'amount' => $monthlyFee,
                    'normal_amount' => null,
                    'unit' => 'eur_per_month',
                    'vat_status' => $feeVatStatus,
                    'price_role' => 'current',
                    'source_kind' => 'structured',
                    'evidence' => [['source' => 'components[1].price', 'quote' => 'monthly fee']],
                ]],
            ]],
            'recurring_schedule' => [
                'present' => $reset,
                'cadence' => $reset ? 'monthly' : 'none',
                'current_period_start' => null,
                'current_period_end' => null,
            ],
        ];
    }

    private function price(
        ElectricityContract $contract,
        string $date,
        string $type,
        float $price,
        bool $discounted = false,
    ): void {
        PriceComponent::create([
            'id' => hash('sha256', $contract->id.$date.$type),
            'electricity_contract_id' => $contract->id,
            'price_date' => $date,
            'price_component_type' => $type,
            'price' => $price,
            'payment_unit' => $type === 'Monthly' ? 'EurPerMonth' : 'CentPerKiloWattHour',
            'has_discount' => $discounted,
            'discount_value' => $discounted ? $price : null,
            'discount_is_percentage' => $discounted ? false : null,
            'discount_type' => $discounted ? 'NFirstMonth' : null,
            'discount_discount_n_first_months' => $discounted ? 3 : null,
        ]);
    }

    private function future(string $maturityType, string $maturity, string $tradeDate, float $price): void
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
            'settlement_price' => $price,
        ]);
    }
}
