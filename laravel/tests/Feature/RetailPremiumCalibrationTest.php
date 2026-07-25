<?php

namespace Tests\Feature;

use App\Models\RetailPremiumObservation;
use App\Services\RetailPremium\RetailPremiumCalibrationService;
use App\Services\RetailPremium\RetailPremiumObservationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RetailPremiumCalibrationTest extends TestCase
{
    use RefreshDatabase;

    private const VAT_MULTIPLIER = 1.255;

    private int $sequence = 0;

    public function test_a_clean_two_period_series_produces_the_expected_pass_through(): void
    {
        $this->series([
            ['retail' => 10.00, 'reference' => 5.00],
            ['retail' => 11.00, 'reference' => 7.00],
        ]);

        $report = $this->analyse();

        $this->assertSame(1, $report['multi_period_series_count']);
        $group = $report['groups'][0];
        $this->assertSame('Testi Energia', $group['company_name']);
        $this->assertSame('quarterly', $group['cadence']);
        $this->assertSame(1, $group['pair_count_included']);
        // dP = 1.00, dF = 2.00 -> through-origin beta = 0.5.
        $this->assertEqualsWithDelta(0.5, $group['beta_included'], 0.0001);
        // One pair fits itself exactly, so no R2 is claimed.
        $this->assertNull($group['r_squared_included']);
        // Premiums 5.00 and 4.00 -> sample standard deviation of sqrt(0.5).
        $this->assertEqualsWithDelta(sqrt(0.5), $group['mean_premium_sd_included'], 0.0001);
        $this->assertSame('quarter', $group['best_reference_kind_included']);
    }

    public function test_a_pair_whose_reference_did_not_move_is_skipped(): void
    {
        $this->series([
            ['retail' => 10.00, 'reference' => 5.000],
            ['retail' => 10.50, 'reference' => 5.005],
            ['retail' => 11.00, 'reference' => 7.005],
        ]);

        $report = $this->analyse();

        $group = $report['groups'][0];
        $this->assertSame(1, $group['pair_count_included']);
        $this->assertSame(1, $report['scenarios']['included']['flat_reference_pairs_skipped']);
        // Only the second step survives: dP = 0.50 against dF = 2.00.
        $this->assertEqualsWithDelta(0.25, $group['beta_included'], 0.0001);
    }

    public function test_an_unknown_vat_basis_is_reported_under_both_assumptions(): void
    {
        $this->series([
            ['retail' => 10.00, 'reference' => 5.00],
            ['retail' => 11.00, 'reference' => 7.00],
        ], ['vat_basis' => 'unknown']);

        $report = $this->analyse();

        $this->assertTrue($report['vat_ambiguous']);
        $this->assertSame(2, $report['vat_unknown_observation_count']);

        $group = $report['groups'][0];
        $this->assertEqualsWithDelta(0.5, $group['beta_included'], 0.0001);
        // The excluded-VAT reference is smaller by the 1.255 factor, so the same retail move
        // implies a proportionally larger pass-through. Neither reading is preferred.
        $this->assertEqualsWithDelta(0.5 * self::VAT_MULTIPLIER, $group['beta_excluded'], 0.0001);
    }

    public function test_a_known_vat_basis_is_not_ambiguous(): void
    {
        $this->series([
            ['retail' => 10.00, 'reference' => 5.00],
            ['retail' => 11.00, 'reference' => 7.00],
        ], ['vat_basis' => 'included']);

        $report = $this->analyse();

        $this->assertFalse($report['vat_ambiguous']);
        $group = $report['groups'][0];
        $this->assertEqualsWithDelta($group['beta_included'], $group['beta_excluded'], 0.0001);
    }

    public function test_a_measurable_quarterly_disagreement_asks_for_a_calibration_review(): void
    {
        config(['canonical_pricing.reset_forward_shift.beta' => 1.0]);
        $this->series([
            ['retail' => 10.00, 'reference' => 5.00],
            ['retail' => 10.30, 'reference' => 6.00],
            ['retail' => 10.90, 'reference' => 8.00],
            ['retail' => 11.20, 'reference' => 9.00],
        ]);

        $report = $this->analyse();
        $this->assertTrue($report['review']['quarterly_measurable']);
        $this->assertEqualsWithDelta(0.3, $report['cadences']['quarterly']['median_ready_company_beta_included'], 0.0001);
        $this->assertTrue($report['review']['review_needed']);

        Log::spy();

        $this->artisan('retail-premiums:calibrate')
            ->expectsOutputToContain('Calibration review needed')
            ->assertExitCode(0);

        Log::shouldHaveReceived('warning')->once();
        Log::shouldNotHaveReceived('info');
    }

    public function test_a_measurable_quarterly_agreement_does_not_ask_for_a_review(): void
    {
        config(['canonical_pricing.reset_forward_shift.beta' => 1.0]);
        $this->series([
            ['retail' => 10.00, 'reference' => 5.00],
            ['retail' => 11.00, 'reference' => 6.00],
            ['retail' => 13.00, 'reference' => 8.00],
            ['retail' => 14.00, 'reference' => 9.00],
        ]);

        $report = $this->analyse();
        $this->assertTrue($report['review']['quarterly_measurable']);
        $this->assertEqualsWithDelta(1.0, $report['cadences']['quarterly']['median_ready_company_beta_included'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $report['groups'][0]['r_squared_included'], 0.0001);
        $this->assertFalse($report['review']['review_needed']);

        Log::spy();

        $this->artisan('retail-premiums:calibrate')->assertExitCode(0);

        Log::shouldHaveReceived('info')->once();
        Log::shouldNotHaveReceived('warning');
    }

    public function test_quarterly_below_the_pair_threshold_is_reported_as_uncalibrated(): void
    {
        config(['canonical_pricing.reset_forward_shift.beta' => 1.0]);
        $this->series([
            ['retail' => 10.00, 'reference' => 5.00],
            ['retail' => 10.30, 'reference' => 6.00],
        ]);

        $report = $this->analyse();

        $this->assertFalse($report['cadences']['quarterly']['measurable']);
        $this->assertFalse($report['review']['quarterly_measurable']);
        // A single pair disagrees with the configured 1.0, but one pair is not a measurement.
        $this->assertFalse($report['review']['review_needed']);

        $this->artisan('retail-premiums:calibrate')
            ->expectsOutputToContain('Quarterly is still uncalibrated')
            ->assertExitCode(0);
    }

    public function test_superseded_method_versions_are_excluded(): void
    {
        $this->series([
            ['retail' => 10.00, 'reference' => 5.00],
            ['retail' => 11.00, 'reference' => 7.00],
        ]);

        // Same lineage, same component, same reference kind, but a superseded method version.
        // Those rows keep known duplicate-period defects and must never join the analysis.
        $this->series([
            ['retail' => 30.00, 'reference' => 5.00],
            ['retail' => 30.10, 'reference' => 25.00],
        ], ['method_version' => 'retail-premium-v1']);

        $report = $this->analyse();

        $this->assertSame(
            [RetailPremiumObservationService::METHOD_VERSION, 'retail-premium-history-v2'],
            $report['method_versions'],
        );
        $this->assertSame(2, $report['observation_count']);
        $this->assertSame(1, $report['multi_period_series_count']);
        $this->assertEqualsWithDelta(0.5, $report['groups'][0]['beta_included'], 0.0001);
    }

    public function test_a_method_version_seam_step_is_not_treated_as_a_reset(): void
    {
        // The handover from reconstructed history to forward collection repeats an unchanged
        // price against a moved reference. Counting it would read as zero pass-through.
        $this->series([
            ['retail' => 10.00, 'reference' => 5.00],
            ['retail' => 10.00, 'reference' => 7.00, 'quality_flags' => ['continues_prior_history_period']],
            ['retail' => 11.00, 'reference' => 9.00],
        ]);

        $report = $this->analyse();

        $this->assertSame(1, $report['scenarios']['included']['method_seam_pairs_skipped']);
        $group = $report['groups'][0];
        $this->assertSame(1, $group['pair_count_included']);
        $this->assertEqualsWithDelta(0.5, $group['beta_included'], 0.0001);
    }

    public function test_a_frozen_reference_kind_does_not_win_the_stability_ranking(): void
    {
        // A reference that never moved has a premium standard deviation of zero and would win on
        // stability alone, while carrying no pass-through information at all.
        $this->series([
            ['retail' => 10.00, 'reference' => 5.00],
            ['retail' => 10.00, 'reference' => 5.00],
        ], ['reference_kind' => 'quarter']);

        $this->series([
            ['retail' => 10.00, 'reference' => 5.00],
            ['retail' => 11.00, 'reference' => 7.00],
        ], ['reference_kind' => 'month']);

        $report = $this->analyse();

        $group = $report['groups'][0];
        $this->assertSame('quarter', $group['most_stable_reference_kind_included']);
        $this->assertSame('month', $group['best_reference_kind_included']);
        $this->assertSame(1, $group['pair_count_included']);
        $this->assertEqualsWithDelta(0.5, $group['beta_included'], 0.0001);
    }

    public function test_the_report_is_scheduled_monthly_on_the_second(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'retail-premiums:calibrate'));

        $this->assertCount(1, $events);
        $this->assertSame('0 8 2 * *', $events->first()->expression);
        $this->assertSame('Europe/Helsinki', (string) $events->first()->timezone);
    }

    public function test_not_comparable_rows_and_non_reset_cadences_are_out_of_scope(): void
    {
        $this->series([
            ['retail' => 10.00, 'reference' => 5.00],
            ['retail' => 11.00, 'reference' => 7.00],
        ], ['quality' => 'not_comparable']);

        $this->series([
            ['retail' => 10.00, 'reference' => 5.00],
            ['retail' => 11.00, 'reference' => 7.00],
        ], ['cadence' => 'none', 'lineage_key' => 'other-lineage']);

        $report = $this->analyse();

        $this->assertSame(0, $report['observation_count']);
        $this->assertSame([], $report['groups']);
    }

    public function test_the_report_writes_nothing(): void
    {
        $this->series([
            ['retail' => 10.00, 'reference' => 5.00],
            ['retail' => 11.00, 'reference' => 7.00],
        ]);

        $before = RetailPremiumObservation::query()->get()->toArray();

        $path = storage_path('app/retail-premium-calibration-test.json');
        $this->artisan('retail-premiums:calibrate --json='.$path)->assertExitCode(0);

        $this->assertSame($before, RetailPremiumObservation::query()->get()->toArray());
        $this->assertFileExists($path);
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertSame(2, $payload['observation_count']);
        unlink($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function analyse(): array
    {
        return app(RetailPremiumCalibrationService::class)->analyse();
    }

    /**
     * @param  list<array<string, mixed>>  $periods
     * @param  array<string, mixed>  $overrides
     */
    private function series(array $periods, array $overrides = []): void
    {
        foreach ($periods as $index => $period) {
            $this->sequence++;

            RetailPremiumObservation::create(array_merge([
                'observation_key' => 'observation-'.$this->sequence,
                'price_signature' => 'signature-'.$this->sequence,
                'lineage_key' => 'lineage-key',
                'lineage_contract_id' => 'contract-1',
                'contract_id' => 'contract-1',
                'company_name' => 'Testi Energia',
                'pricing_model' => 'FixedPrice',
                'cadence' => 'quarterly',
                'contract_type' => 'OpenEnded',
                'target_group' => 'Household',
                'metering' => 'General',
                'phase_index' => $index,
                'first_observed_date' => '2026-01-01',
                'last_observed_date' => '2026-01-31',
                'energy_component_type' => 'energy_general',
                'retail_energy_price_cents_per_kwh' => $period['retail'],
                'vat_basis' => 'included',
                'reference_consumption_kwh' => 5000,
                'reference_kind' => 'quarter',
                'reference_price_including_vat_cents_per_kwh' => $period['reference'],
                'reference_price_excluding_vat_cents_per_kwh' => $period['reference'] / self::VAT_MULTIPLIER,
                'method_version' => RetailPremiumObservationService::METHOD_VERSION,
                'quality' => 'inferred',
                'quality_flags' => $period['quality_flags'] ?? [],
            ], $overrides, [
                'first_observed_date' => CarbonImmutable::parse('2026-01-01')->addMonths($index)->toDateString(),
            ]));
        }
    }
}
