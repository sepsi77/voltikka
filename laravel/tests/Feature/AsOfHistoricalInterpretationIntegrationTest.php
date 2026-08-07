<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractHistoricalInterpretation;
use App\Models\ContractHistoricalInterpretationEpisode;
use App\Models\ContractInterpretation;
use App\Models\ContractPriceAnnualCost;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\ContractInterpretation\HistoricalContractEpisodeBuilder;
use App\Services\ContractInterpretation\HistoricalInterpretationFingerprint;
use App\Services\ContractStatistics\AnnualCostStatisticsWriter;
use App\Services\ContractStatistics\AsOfAnnualCostCalculator;
use App\Services\ContractStatistics\AsOfAnnualCostEvidenceResolver;
use App\Services\ContractStatistics\Enums\AnnualCostCalculationBasis;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\Support\CanonicalPricingFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AsOfHistoricalInterpretationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-06-01';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-10 09:00:00 Europe/Helsinki');
        Company::create(['name' => 'Historical AsOf Energy Oy', 'name_slug' => 'historical-asof-energy-oy']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pre_source_date_uses_validated_retrospective_output_and_changes_the_annual_result(): void
    {
        $contract = $this->evidence('historical-canonical', 8.0);
        [$episode, $interpretation] = $this->historicalInterpretation($contract, 2.0);

        $result = $this->annualResult($contract->id, 5000);

        $this->assertTrue($result->isAvailable());
        $this->assertSame(AnnualCostCalculationBasis::CanonicalOutcome, $result->calculationBasis);
        $this->assertEqualsWithDelta(100.0, $result->totalCost, 0.01);
        $this->assertNotEqualsWithDelta(400.0, $result->totalCost, 0.01);
        $this->assertSame($episode->id, $result->sourceEvidenceIds['historical_episode_id']);
        $this->assertSame($interpretation->id, $result->sourceEvidenceIds['historical_interpretation_id']);
        $this->assertNull($result->sourceEvidenceIds['source_snapshot_id']);
        $this->assertNull($result->sourceEvidenceIds['interpretation_id']);
        $this->assertContains('retrospective_historical_interpretation', $result->provenanceFlags);
        $this->assertTrue(collect($result->provenanceFlags)->contains(
            fn (string $flag): bool => str_starts_with($flag, 'historical_interpretation_completed_at_'),
        ));
    }

    public function test_target_outside_episode_does_not_use_historical_output(): void
    {
        $contract = $this->evidence('outside-episode', 8.0);
        $this->historicalInterpretation($contract, 2.0);
        $this->snapshot($contract, '2026-06-02');
        $this->addComponent($contract, 8.0, '2026-06-02');

        $result = $this->annualResult($contract->id, 5000, '2026-06-02');

        $this->assertSame(AnnualCostCalculationBasis::ObservedRelationalComponents, $result->calculationBasis);
        $this->assertEqualsWithDelta(400.0, $result->totalCost, 0.01);
        $this->assertContains('historical_canonical_omitted_no_covering_current_builder_episode', $result->provenanceFlags);
    }

    public function test_exact_immutable_source_path_wins_over_dedicated_historical_output(): void
    {
        $contract = $this->evidence('source-wins', 8.0);
        $this->historicalInterpretation($contract, 2.0);
        $source = $this->sourceInterpretation($contract, 4.0, '2026-05-31 00:00:00', '2026-06-01 23:59:00');

        $result = $this->annualResult($contract->id, 5000);

        $this->assertEqualsWithDelta(200.0, $result->totalCost, 0.01);
        $this->assertSame($source->id, $result->sourceEvidenceIds['interpretation_id']);
        $this->assertNull($result->sourceEvidenceIds['historical_episode_id']);
        $this->assertNotContains('retrospective_historical_interpretation', $result->provenanceFlags);
    }

    public function test_ambiguous_source_coverage_never_falls_back_to_historical_output(): void
    {
        $contract = $this->evidence('ambiguous-source', 8.0);
        $this->historicalInterpretation($contract, 2.0);
        $this->sourceInterpretation($contract, 3.0, '2026-05-31 00:00:00', '2026-06-01 23:59:00');
        $this->sourceInterpretation($contract, 4.0, '2026-05-30 00:00:00', '2026-06-01 22:00:00');

        $result = $this->annualResult($contract->id, 5000);

        $this->assertSame(AnnualCostCalculationBasis::ObservedRelationalComponents, $result->calculationBasis);
        $this->assertContains('canonical_omitted_ambiguous_covering_source_snapshots', $result->provenanceFlags);
        $this->assertNotContains('retrospective_historical_interpretation', $result->provenanceFlags);
    }

    public function test_exact_target_manifest_mismatch_fails_closed(): void
    {
        $contract = $this->evidence('manifest-mismatch', 8.0);
        $this->historicalInterpretation($contract, 2.0, function (array $manifest): array {
            $manifest['target_days'][0]['component_ids'] = ['wrong|2026-06-01'];

            return $manifest;
        });

        $result = $this->annualResult($contract->id, 5000);

        $this->assertSame(AnnualCostCalculationBasis::ObservedRelationalComponents, $result->calculationBasis);
        $this->assertContains('historical_canonical_omitted_exact_target_manifest_mismatch', $result->provenanceFlags);
    }

    public function test_exact_target_value_change_fails_economic_manifest_check(): void
    {
        $contract = $this->evidence('economic-manifest-mismatch', 8.0);
        $this->historicalInterpretation($contract, 2.0);
        DB::table('price_components')
            ->where('electricity_contract_id', $contract->id)
            ->whereDate('price_date', self::DATE)
            ->update(['price' => 9.0]);

        $result = $this->annualResult($contract->id, 5000);

        $this->assertSame(AnnualCostCalculationBasis::ObservedRelationalComponents, $result->calculationBasis);
        $this->assertEqualsWithDelta(450.0, $result->totalCost, 0.01);
        $this->assertContains('historical_canonical_omitted_exact_target_manifest_mismatch', $result->provenanceFlags);
    }

    public function test_pending_failed_wrong_version_and_wrong_fingerprint_fail_closed_while_old_versions_are_ignored(): void
    {
        $contract = $this->evidence('bad-candidates', 8.0);
        [$episode, $interpretation] = $this->historicalInterpretation($contract, 2.0);

        foreach ([
            ['status' => ContractHistoricalInterpretation::STATUS_PENDING, 'flag' => 'historical_canonical_omitted_interpretation_status_pending'],
            ['status' => ContractHistoricalInterpretation::STATUS_FAILED, 'flag' => 'historical_canonical_omitted_interpretation_status_failed'],
        ] as $case) {
            $interpretation->update(['status' => $case['status']]);
            $this->assertContains($case['flag'], $this->annualResult($contract->id, 5000)->provenanceFlags);
        }

        $interpretation->update([
            'status' => ContractHistoricalInterpretation::STATUS_VALIDATED,
            'prompt_version' => 'stale-prompt',
        ]);
        $this->assertContains(
            'historical_canonical_omitted_stale_wrong_version_or_fingerprint_interpretation',
            $this->annualResult($contract->id, 5000)->provenanceFlags,
        );

        $interpretation->update([
            'prompt_version' => config('contract_interpretation.prompt_version'),
            'analysis_fingerprint' => hash('sha256', 'wrong-fingerprint'),
        ]);
        $this->assertContains(
            'historical_canonical_omitted_stale_wrong_version_or_fingerprint_interpretation',
            $this->annualResult($contract->id, 5000)->provenanceFlags,
        );

        $current = ContractHistoricalInterpretation::create(
            $this->interpretationAttributes($episode, $this->fixedOutput(3.0)),
        );
        $result = $this->annualResult($contract->id, 5000);
        $this->assertSame($current->id, $result->sourceEvidenceIds['historical_interpretation_id']);
        $this->assertEqualsWithDelta(150.0, $result->totalCost, 0.01);
    }

    public function test_parser_invalid_validated_output_fails_closed(): void
    {
        $contract = $this->evidence('parser-invalid', 8.0);
        [, $interpretation] = $this->historicalInterpretation($contract, 2.0);
        $interpretation->update(['output' => ['pricing' => [], 'calculation' => [], 'source_consistency' => []]]);

        $result = $this->annualResult($contract->id, 5000);

        $this->assertSame(AnnualCostCalculationBasis::ObservedRelationalComponents, $result->calculationBasis);
        $this->assertContains('historical_canonical_omitted_parser_invalid_output', $result->provenanceFlags);
    }

    public function test_historical_provenance_persists_in_all_rows_and_aggregates_are_deterministic(): void
    {
        $contract = $this->evidence('historical-persist', 8.0);
        [$episode, $interpretation] = $this->historicalInterpretation($contract, 2.0);
        $calculator = app(AsOfAnnualCostCalculator::class);
        $writer = app(AnnualCostStatisticsWriter::class);

        $writer->write(self::DATE, $calculator->calculate(self::DATE));
        $firstAggregate = ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::AsOf)
            ->orderBy('consumption_kwh')->pluck('median_value')->all();
        $writer->write(self::DATE, $calculator->calculate(self::DATE));
        $secondAggregate = ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::AsOf)
            ->orderBy('consumption_kwh')->pluck('median_value')->all();

        $rows = ContractPriceAnnualCost::query()->orderBy('consumption_kwh')->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame($episode->id, $row->historical_episode_id);
            $this->assertSame($interpretation->id, $row->historical_interpretation_id);
            $this->assertSame($episode->evidence_grade->value, $row->historical_evidence_grade);
            $this->assertNull($row->source_snapshot_id);
            $this->assertNull($row->source_interpretation_id);
            $this->assertSame($episode->id, $row->provenance['source_evidence_ids']['historical_episode_id']);
            $this->assertContains('retrospective_historical_interpretation', $row->provenance['flags']);
        }
        $this->assertSame($firstAggregate, $secondAggregate);
    }

    public function test_resolver_does_not_read_or_mutate_current_contract_state(): void
    {
        $contract = $this->evidence('no-current-state', 8.0);
        $this->historicalInterpretation($contract, 2.0);
        $contract->update([
            'short_description' => 'Changed current prose',
            'canonical_pricing' => ['current' => 'must not be read'],
            'canonical_calculation' => ['current' => 'must not be read'],
            'canonical_source_consistency' => ['current' => 'must not be read'],
        ]);
        $before = $contract->fresh()->getRawOriginal();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $result = $this->annualResult($contract->id, 5000);
        $resolverQueries = $queries;

        $this->assertEqualsWithDelta(100.0, $result->totalCost, 0.01);
        $this->assertFalse(collect($resolverQueries)->contains(
            fn (string $sql): bool => str_contains($sql, 'from "electricity_contracts"'),
        ));
        $this->assertSame($before, $contract->fresh()->getRawOriginal());
    }

    public function test_multi_date_historical_resolver_queries_are_bounded_by_tables(): void
    {
        foreach (range(1, 8) as $index) {
            $contract = $this->contract('batch-historical-'.$index);
            foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $date) {
                $this->snapshot($contract, $date);
                $this->addComponent($contract, 8.0, $date);
            }
            $this->historicalInterpretation($contract, 2.0, null, '2026-06-03');
        }

        DB::enableQueryLog();
        $resolved = app(AsOfAnnualCostEvidenceResolver::class)->resolveForDates([
            '2026-06-01', '2026-06-02', '2026-06-03',
        ]);
        $queries = DB::getQueryLog();

        $this->assertCount(8, $resolved['2026-06-01']);
        $this->assertCount(8, $resolved['2026-06-03']);
        $this->assertLessThanOrEqual(6, count($queries), collect($queries)->pluck('query')->implode("\n"));
    }

    public function test_rebuild_dry_run_consumes_validated_episode_and_public_method_remains_legacy(): void
    {
        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::Legacy->value);
        $contract = $this->evidence('rebuild-historical', 8.0);
        $this->historicalInterpretation($contract, 2.0);
        $result = $this->annualResult($contract->id, 5000);

        $this->assertEqualsWithDelta(100.0, $result->totalCost, 0.01);
        $this->artisan('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE])
            ->expectsOutputToContain('evidence=3 available=3 unavailable=0')
            ->assertSuccessful();
        $this->assertSame(0, ContractPriceAnnualCost::count());
        $this->assertSame(AnnualCostMethodVersion::Legacy->value, config('contract_statistics.annual_cost.active_method_version'));
    }

    private function evidence(string $id, float $price): ElectricityContract
    {
        $contract = $this->contract($id);
        $this->snapshot($contract, self::DATE, $price);
        $this->addComponent($contract, $price, self::DATE);

        return $contract;
    }

    private function contract(string $id): ElectricityContract
    {
        return ElectricityContract::factory()->forCompany('Historical AsOf Energy Oy')->legacy()->create([
            'id' => $id,
            'name' => $id,
            'pricing_model' => 'FixedPrice',
            'pricing_name' => 'FixedPrice',
        ]);
    }

    private function snapshot(ElectricityContract $contract, string $date, float $price = 8.0): int
    {
        return (int) DB::table('contract_price_snapshots')->insertGetId([
            'snapshot_date' => $date,
            'contract_id' => $contract->id,
            'company_name' => $contract->company_name,
            'contract_name' => $contract->name,
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'fixed_time_range' => null,
            'metering' => 'General',
            'segment_key' => 'open_ended',
            'pricing_basis' => 'observed_seller_data',
            'energy_price_cents_per_kwh' => $price,
            'monthly_fee_eur' => 0,
            'annual_cost_2000_kwh' => 1,
            'annual_cost_5000_kwh' => 1,
            'annual_cost_18000_kwh' => 1,
            'has_discount' => false,
            'includes_spot_price' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addComponent(ElectricityContract $contract, float $price, string $date): void
    {
        DB::table('price_components')->insert([
            'id' => $contract->id.'-'.$date,
            'price_date' => $date,
            'price_component_type' => 'General',
            'electricity_contract_id' => $contract->id,
            'has_discount' => false,
            'price' => $price,
            'payment_unit' => 'CentPerKiloWattHour',
        ]);
    }

    /**
     * @return array{ContractHistoricalInterpretationEpisode, ContractHistoricalInterpretation}
     */
    private function historicalInterpretation(
        ElectricityContract $contract,
        float $canonicalPrice,
        ?callable $manifestMutation = null,
        string $cutoff = self::DATE,
    ): array {
        $plan = app(HistoricalContractEpisodeBuilder::class)
            ->discover(CarbonImmutable::parse($cutoff), [$contract->id])['episodes'][0];
        if ($manifestMutation !== null) {
            $plan['evidence_manifest'] = $manifestMutation($plan['evidence_manifest']);
            $fingerprints = app(HistoricalInterpretationFingerprint::class);
            $plan['manifest_fingerprint'] = $fingerprints->manifest($plan['evidence_manifest']);
            $plan['evidence_fingerprint'] = $fingerprints->evidence($plan['analysis_input'], $plan['evidence_manifest']);
            $plan['episode_fingerprint'] = $fingerprints->episode(
                HistoricalContractEpisodeBuilder::VERSION,
                $contract->id,
                $plan['episode_start'],
                $plan['episode_end'],
                $plan['evidence_fingerprint'],
            );
            $plan['analysis_fingerprint'] = $fingerprints->analysis($plan['episode_fingerprint']);
        }

        $episode = ContractHistoricalInterpretationEpisode::create(collect($plan)->only([
            'contract_id', 'episode_start', 'episode_end', 'builder_version', 'episode_fingerprint',
            'evidence_fingerprint', 'manifest_fingerprint', 'evidence_grade', 'analysis_input', 'evidence_manifest',
        ])->all());
        $interpretation = ContractHistoricalInterpretation::create(
            $this->interpretationAttributes($episode, $this->fixedOutput($canonicalPrice)),
        );

        return [$episode, $interpretation];
    }

    /** @return array<string, mixed> */
    private function interpretationAttributes(ContractHistoricalInterpretationEpisode $episode, array $output): array
    {
        return [
            'episode_id' => $episode->id,
            'contract_id' => $episode->contract_id,
            'analysis_fingerprint' => app(HistoricalInterpretationFingerprint::class)->analysis($episode->episode_fingerprint),
            'status' => ContractHistoricalInterpretation::STATUS_VALIDATED,
            'schema_version' => config('contract_interpretation.schema_version'),
            'prompt_version' => config('contract_interpretation.prompt_version'),
            'historical_addendum_version' => config('contract_interpretation.historical.addendum_version'),
            'validator_version' => config('contract_interpretation.validator_version'),
            'parser_version' => CanonicalPricingParser::VERSION,
            'provider' => config('contract_interpretation.provider'),
            'model' => config('contract_interpretation.model'),
            'reasoning_effort' => config('contract_interpretation.reasoning_effort'),
            'output' => $output,
            'validation_errors' => [],
            'completed_at' => '2026-08-01 12:00:00',
        ];
    }

    private function sourceInterpretation(
        ElectricityContract $contract,
        float $canonicalPrice,
        string $firstObserved,
        string $lastObserved,
    ): ContractInterpretation {
        $snapshot = ContractSourceSnapshot::create([
            'contract_id' => $contract->id,
            'source_fingerprint' => hash('sha256', $contract->id.$firstObserved),
            'source_payload' => ['id' => $contract->id],
            'first_observed_at' => $firstObserved,
            'last_observed_at' => $lastObserved,
        ]);
        $observation = ContractSourceObservation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $snapshot->id,
            'first_observed_at' => $firstObserved,
            'last_observed_at' => $lastObserved,
        ]);

        return ContractInterpretation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $snapshot->id,
            'analysis_source_observation_id' => $observation->id,
            'analysis_fingerprint' => hash('sha256', 'source-'.$snapshot->id),
            'status' => 'published',
            'schema_version' => 'test',
            'prompt_version' => 'test',
            'validator_version' => 'test',
            'provider' => 'test',
            'model' => 'test',
            'output' => $this->fixedOutput($canonicalPrice),
            'validation_errors' => [],
            'completed_at' => CarbonImmutable::parse($firstObserved, 'Europe/Helsinki')->addHour(),
        ]);
    }

    /** @return array<string, mixed> */
    private function fixedOutput(float $price): array
    {
        $attributes = CanonicalPricingFixture::attributes(
            phases: [CanonicalPricingFixture::phase(
                'Hinta',
                PhaseKind::CurrentStructured,
                CanonicalPricingFixture::boundary(BoundaryKind::ContractStart),
                CanonicalPricingFixture::boundary(BoundaryKind::None),
                [CanonicalPricingFixture::component(ComponentType::EnergyGeneral, $price, ComponentUnit::CentsPerKwh)],
            )],
            calculationStatus: CalculationStatus::Exact,
        );

        return [
            'pricing' => $attributes['canonical_pricing'],
            'source_consistency' => $attributes['canonical_source_consistency'],
            'calculation' => $attributes['canonical_calculation'],
        ];
    }

    private function annualResult(string $contractId, int $consumption, string $date = self::DATE)
    {
        return collect(app(AsOfAnnualCostCalculator::class)->calculate($date))
            ->first(fn ($result): bool => $result->contractId === $contractId
                && $result->consumptionKwh === $consumption);
    }
}
