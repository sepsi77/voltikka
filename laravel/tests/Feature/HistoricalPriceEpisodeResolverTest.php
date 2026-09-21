<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use App\Services\ContractInterpretation\ContractInterpretationValidator;
use App\Services\ContractStatistics\AsOfAnnualCostEvidenceResolver;
use App\Services\ContractStatistics\ContractPriceBasis;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use App\Services\ContractStatistics\HistoricalPriceEpisodeResolver;
use Carbon\CarbonImmutable;
use Database\Factories\Support\CanonicalPricingFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistoricalPriceEpisodeResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Partial parser fixtures test anchors. The exact-source test uses real validation.
        $this->mock(ContractInterpretationValidator::class)
            ->shouldReceive('validate')->andReturn([]);
        Company::create(['name' => 'Historical Energy Oy', 'name_slug' => 'historical-energy-oy']);
    }

    public function test_later_evidence_is_ignored(): void
    {
        $this->contract('later-ignored');
        $this->snapshot('later-ignored', '2026-05-31', 6.0, 3.0);
        $this->snapshot('later-ignored', '2026-06-01', 7.0, 3.0);
        $this->snapshot('later-ignored', '2026-06-02', 9.0, 3.0);

        $anchor = $this->resolve('2026-06-01', 'later-ignored', 7.0, 3.0);

        $this->assertSame('2026-06-01', $anchor->startedAt?->toDateString());
        $this->assertSame(PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun, $anchor->evidenceBasis);
        $this->assertContains('preceding_calendar_snapshot_proves_price_change', $anchor->flags);
    }

    public function test_recurrence_anchors_the_latest_matching_run(): void
    {
        $this->contract('recurrence');
        $this->snapshot('recurrence', '2026-05-30', 5.0, 2.0);
        $this->snapshot('recurrence', '2026-05-31', 7.0, 2.0);
        $this->snapshot('recurrence', '2026-06-01', 9.0, 2.0);
        $this->snapshot('recurrence', '2026-06-02', 7.0, 2.0);
        $this->snapshot('recurrence', '2026-06-03', 7.0, 2.0);

        $anchor = $this->resolve('2026-06-03', 'recurrence', 7.0, 2.0);

        $this->assertSame('2026-06-02', $anchor->startedAt?->toDateString());
    }

    public function test_gap_and_dataset_boundary_are_left_censored(): void
    {
        $this->contract('gap');
        $this->contract('boundary');
        $this->snapshot('gap', '2026-05-30', 6.0, 2.0);
        $this->snapshot('gap', '2026-06-01', 7.0, 2.0);
        $this->snapshot('gap', '2026-06-02', 7.0, 2.0);
        $this->snapshot('boundary', '2026-06-01', 7.0, 2.0);
        $this->snapshot('boundary', '2026-06-02', 7.0, 2.0);

        $anchors = (new HistoricalPriceEpisodeResolver)->resolve(
            $this->date('2026-06-02'),
            [
                'gap' => new SupplierAdjustedCandidate('gap', 7.0, 2.0),
                'boundary' => new SupplierAdjustedCandidate('boundary', 7.0, 2.0),
            ],
        );

        $this->assertNull($anchors['gap']->startedAt);
        $this->assertContains('left_censored_price_episode', $anchors['gap']->flags);
        $this->assertContains('calendar_gap_before_matching_run', $anchors['gap']->flags);
        $this->assertNull($anchors['boundary']->startedAt);
        $this->assertContains('dataset_boundary_before_matching_run', $anchors['boundary']->flags);
    }

    public function test_target_price_or_fee_mismatch_returns_missing_anchor(): void
    {
        $this->contract('mismatch');
        $this->snapshot('mismatch', '2026-05-31', 6.0, 2.0);
        $this->snapshot('mismatch', '2026-06-01', 7.0, 3.0);

        $anchor = $this->resolve('2026-06-01', 'mismatch', 7.0, 2.0);

        $this->assertNull($anchor->startedAt);
        $this->assertSame(PriceEpisodeEvidenceBasis::Missing, $anchor->evidenceBasis);
        $this->assertContains('target_snapshot_price_mismatch', $anchor->flags);
    }

    public function test_candidates_resolve_in_one_bounded_snapshot_query_and_basis_must_be_explicit(): void
    {
        $this->contract('observed');
        $this->contract('canonical');
        $this->snapshot('observed', '2026-05-31', 6.0, 2.0);
        $this->snapshot('observed', '2026-06-01', 7.0, 2.0);
        $this->snapshot('canonical', '2026-05-31', 6.0, 2.0, 'canonical_calculation');
        $this->snapshot('canonical', '2026-06-01', 7.0, 2.0, 'canonical_calculation');

        $candidates = [
            'observed' => new SupplierAdjustedCandidate('observed', 7.0, 2.0),
            'canonical' => new SupplierAdjustedCandidate('canonical', 7.0, 2.0),
        ];
        $implicit = (new HistoricalPriceEpisodeResolver)->resolve($this->date('2026-06-01'), $candidates);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'contract_price_snapshots')) {
                $queries[] = $query->sql;
            }
        });
        $explicit = (new HistoricalPriceEpisodeResolver)->resolve(
            $this->date('2026-06-01'),
            $candidates,
            ContractPriceBasis::CanonicalCalculation,
        );

        $this->assertCount(1, $queries, implode("\n", $queries));
        $this->assertSame('2026-06-01', $implicit['observed']->startedAt?->toDateString());
        $this->assertNull($implicit['canonical']->startedAt);
        $this->assertSame('2026-06-01', $explicit['observed']->startedAt?->toDateString());
        $this->assertSame('2026-06-01', $explicit['canonical']->startedAt?->toDateString());
        $this->assertSame(PriceEpisodeEvidenceBasis::CanonicalSnapshotRun, $explicit['canonical']->evidenceBasis);
    }

    public function test_v3_boundary_fee_independence_gap_and_future_safety(): void
    {
        $this->contract('proxy');
        $this->snapshot('proxy', '2026-06-01', 7, 2);
        $this->snapshot('proxy', '2026-06-03', 7, 9);
        $candidate = new SupplierAdjustedCandidate('proxy', 7, 9);
        $anchor = $this->v3('2026-06-03', $candidate);
        $this->assertSame('2026-06-01', $anchor->startedAt?->toDateString());
        $this->assertContains('price_episode_left_censored', $anchor->flags);
        $this->assertContains('calendar_gap_within_observed_price_episode', $anchor->flags);
        $this->snapshot('proxy', '2026-06-04', 20, 30);
        $this->source('proxy', '2026-06-04', ['energy_general' => 20]);
        $this->assertEquals($anchor, $this->v3('2026-06-03', $candidate));
        foreach ([AnnualCostMethodVersion::AsOf, AnnualCostMethodVersion::AsOfV2] as $method) {
            $strict = (new HistoricalPriceEpisodeResolver)->resolve($this->date('2026-06-03'), ['proxy' => $candidate], methodVersion: $method);
            $this->assertNull($strict['proxy']->startedAt);
        }
    }

    public function test_v3_full_time_and_season_signatures_not_equal_averages(): void
    {
        foreach (['Time' => ['energy_day', 'energy_night', 3.0, -5.0], 'Season' => ['energy_seasonal_winter', 'energy_seasonal_other', 7.0, -5.0]] as $metering => [$first, $second, $deltaFirst, $deltaSecond]) {
            $this->contract($metering);
            foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $date) {
                $this->snapshot($metering, $date, 10, 2);
            }
            DB::table('contract_price_snapshots')->where('contract_id', $metering)->update(['metering' => $metering]);
            $this->source($metering, '2026-06-01', [$first => 10, $second => 10]);
            $this->source($metering, '2026-06-02', [$first => 10 + $deltaFirst, $second => 10 + $deltaSecond]);
            $this->source($metering, '2026-06-03', [$first => 10, $second => 10]);
            $candidate = new SupplierAdjustedCandidate($metering, 10, 2, [$first => 10, $second => 10], $metering);
            $this->assertSame('2026-06-01', $this->v3('2026-06-01', $candidate)->startedAt?->toDateString());
            $this->assertNull($this->v3('2026-06-02', $candidate)->startedAt);
            $this->assertSame('2026-06-03', $this->v3('2026-06-03', $candidate)->startedAt?->toDateString());
        }
    }

    public function test_v3_dated_fee_only_phases_do_not_reset_energy_identity(): void
    {
        $this->contract('fee-phases');
        $this->snapshot('fee-phases', '2026-06-01', 7, 2);
        $this->snapshot('fee-phases', '2026-06-02', 7, 9);
        $first = $this->source('fee-phases', '2026-06-01', ['energy_general' => 7]);
        $output = $first->output;
        $phase = $output['pricing']['phases'][0];
        $phase['components'][] = CanonicalPricingFixture::component(ComponentType::MonthlyFee, 2, ComponentUnit::EurPerMonth);
        $output['pricing']['phases'][] = $phase;
        $first->update(['output' => $output]);
        $this->source('fee-phases', '2026-06-02', ['energy_general' => 7]);
        $anchor = $this->v3('2026-06-02', new SupplierAdjustedCandidate('fee-phases', 7, 9));
        $this->assertSame('2026-06-01', $anchor->startedAt?->toDateString());
        $this->assertContains('price_episode_uses_retrospective_exact_source_interpretation', $anchor->flags);
    }

    public function test_v3_missing_and_ambiguous_source_cannot_be_raw_proof(): void
    {
        $this->contract('unsafe');
        foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $date) {
            $this->snapshot('unsafe', $date, 7, 2);
        }
        $missing = $this->source('unsafe', '2026-06-02', ['energy_general' => 7]);
        $missing->delete();
        $candidate = new SupplierAdjustedCandidate('unsafe', 7, 2);
        $this->assertNull($this->v3('2026-06-02', $candidate)->startedAt);
        $this->source('unsafe', '2026-06-03', ['energy_general' => 7]);
        $anchor = $this->v3('2026-06-03', $candidate);
        $this->assertSame('2026-06-03', $anchor->startedAt?->toDateString());
        $this->assertContains('prior_energy_evidence_changed_unknown_or_conflicting', $anchor->flags);
        $this->source('unsafe', '2026-06-03', ['energy_general' => 7]);
        $this->assertNull($this->v3('2026-06-03', $candidate)->startedAt);
    }

    public function test_v3_observed_basis_wins_without_using_later_canonical_evidence(): void
    {
        $this->contract('mixed');
        $this->snapshot('mixed', '2026-06-01', 7, 2);
        $this->snapshot('mixed', '2026-06-01 00:00:00', 20, 2, 'canonical_calculation');
        $this->snapshot('mixed', '2026-06-02', 20, 2, 'canonical_calculation');
        $anchor = $this->v3('2026-06-01', new SupplierAdjustedCandidate('mixed', 7, 2), ContractPriceBasis::CanonicalCalculation);
        $this->assertSame('2026-06-01', $anchor->startedAt?->toDateString());
        $this->assertSame(PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun, $anchor->evidenceBasis);
    }

    public function test_v3_component_only_unknown_day_breaks_a_matching_run(): void
    {
        $this->contract('unknown');
        $this->snapshot('unknown', '2026-06-01', 7, 2);
        $this->snapshot('unknown', '2026-06-03', 7, 2);
        DB::table('price_components')->insert([
            'id' => 'unknown-component', 'electricity_contract_id' => 'unknown',
            'price_date' => '2026-06-02', 'price_component_type' => 'General',
            'price' => 7, 'payment_unit' => 'CentPerKiloWattHour', 'has_discount' => false,
        ]);
        $anchor = $this->v3('2026-06-03', new SupplierAdjustedCandidate('unknown', 7, 2));
        $this->assertSame('2026-06-03', $anchor->startedAt?->toDateString());
        $this->assertContains('prior_energy_evidence_changed_unknown_or_conflicting', $anchor->flags);
    }

    public function test_v3_batches_all_contract_dates_and_never_reads_current_contracts(): void
    {
        $candidates = [];
        foreach (['one', 'two', 'three'] as $id) {
            $this->contract($id);
            foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $date) {
                $this->snapshot($id, $date, 7, 2);
            }
            $candidates[$id] = new SupplierAdjustedCandidate($id, 7, 2);
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $anchors = (new HistoricalPriceEpisodeResolver)->resolve($this->date('2026-06-03'), $candidates, methodVersion: AnnualCostMethodVersion::AsOfV3);
        $this->assertCount(3, $anchors);
        $this->assertCount(7, $queries, implode("\n", $queries));
        $this->assertStringNotContainsString('"electricity_contracts"', implode("\n", $queries));
    }

    public function test_v3_rechecks_day_after_inclusive_conflict_end_when_an_observation_still_covers_it(): void
    {
        $this->contract('interval-end');
        foreach (['2026-06-01', '2026-06-03', '2026-06-08', '2026-06-10'] as $date) {
            $this->snapshot('interval-end', $date, 8, 0);
        }
        $long = $this->source('interval-end', '2026-06-01', ['energy_general' => 8]);
        ContractSourceObservation::where('id', $long->analysis_source_observation_id)->update(['last_observed_at' => '2026-06-10 18:00:00']);
        $short = $this->source('interval-end', '2026-06-02', ['energy_general' => 9]);
        ContractSourceObservation::where('id', $short->analysis_source_observation_id)->update(['last_observed_at' => '2026-06-03 18:00:00']);
        $real = app(AsOfAnnualCostEvidenceResolver::class);
        $resolver = \Mockery::mock(AsOfAnnualCostEvidenceResolver::class);
        $resolver->shouldReceive('resolveForDates')->once()->andReturnUsing(function ($dates, $method, $ids, $prefer) use ($real) {
            $this->assertContains('2026-06-04', $dates);
            $this->assertNotContains('2026-06-11', $dates);

            return $real->resolveForDates($dates, $method, $ids, $prefer);
        });
        $this->app->instance(AsOfAnnualCostEvidenceResolver::class, $resolver);
        $anchor = $this->v3('2026-06-10', new SupplierAdjustedCandidate('interval-end', 8, 0));
        // No dated identity exists on June 4. Rechecking it cannot invent a known signature.
        $this->assertSame('2026-06-08', $anchor->startedAt?->toDateString());
    }

    private function v3(string $date, SupplierAdjustedCandidate $candidate, ?ContractPriceBasis $basis = null)
    {
        return (new HistoricalPriceEpisodeResolver)->resolve($this->date($date), [$candidate->contractId => $candidate], $basis, AnnualCostMethodVersion::AsOfV3)[$candidate->contractId];
    }

    private function source(string $id, string $date, array $rates): ContractInterpretation
    {
        $source = ContractSourceSnapshot::create([
            'contract_id' => $id, 'source_fingerprint' => hash('sha256', uniqid('', true)),
            'source_payload' => ['Details' => ['TargetGroup' => 'Household']],
            'first_observed_at' => $date.' 08:00:00', 'last_observed_at' => $date.' 18:00:00',
        ]);
        $observation = ContractSourceObservation::create([
            'contract_id' => $id, 'source_snapshot_id' => $source->id,
            'first_observed_at' => $date.' 08:00:00', 'last_observed_at' => $date.' 18:00:00',
        ]);
        $attributes = CanonicalPricingFixture::fixedAttributes();
        $attributes['canonical_pricing']['phases'][0]['components'] = array_map(
            fn ($type, $rate) => CanonicalPricingFixture::component(ComponentType::from($type), $rate, ComponentUnit::CentsPerKwh),
            array_keys($rates), array_values($rates),
        );

        return ContractInterpretation::create([
            'contract_id' => $id, 'source_snapshot_id' => $source->id,
            'analysis_source_observation_id' => $observation->id,
            'analysis_fingerprint' => hash('sha256', 'interpretation-'.$source->id),
            'status' => 'published', 'schema_version' => 'schema-v4', 'prompt_version' => 'prompt-v19',
            'validator_version' => 'validator-v17', 'provider' => 'test', 'model' => 'test',
            'output' => ['pricing' => $attributes['canonical_pricing'], 'source_consistency' => $attributes['canonical_source_consistency'], 'calculation' => $attributes['canonical_calculation']],
            'validation_errors' => [], 'completed_at' => '2026-07-01 12:00:00',
        ]);
    }

    private function resolve(string $date, string $contractId, float $energy, float $fee)
    {
        return (new HistoricalPriceEpisodeResolver)->resolve(
            $this->date($date),
            [$contractId => new SupplierAdjustedCandidate($contractId, $energy, $fee)],
        )[$contractId];
    }

    private function contract(string $id): void
    {
        ElectricityContract::factory()->forCompany('Historical Energy Oy')->create([
            'id' => $id,
            'name' => $id,
        ]);
    }

    private function snapshot(
        string $contractId,
        string $date,
        float $energy,
        float $fee,
        string $basis = 'observed_seller_data',
    ): void {
        DB::table('contract_price_snapshots')->insert([
            'snapshot_date' => $date,
            'contract_id' => $contractId,
            'company_name' => 'Historical Energy Oy',
            'contract_name' => $contractId,
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'segment_key' => 'open_ended',
            'pricing_basis' => $basis,
            'energy_price_cents_per_kwh' => $energy,
            'monthly_fee_eur' => $fee,
            'has_discount' => false,
            'includes_spot_price' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Europe/Helsinki')->startOfDay();
    }
}
