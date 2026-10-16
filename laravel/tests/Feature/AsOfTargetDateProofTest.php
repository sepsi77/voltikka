<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\ContractStatistics\AsOfAnnualCostEvidenceResolver;
use App\Services\ContractStatistics\DTO\AsOfAnnualCostEvidence;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Real production interpretations that the current writer used on 2026-09-22..2026-10-15.
 * V3 target validation must accept them where the year comes from the observed target date.
 */
class AsOfTargetDateProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysis_date_citation_stays_valid_inside_its_covering_observation(): void
    {
        $id = $this->load('vaasa_analysis_date', '2026-10-01 03:00:26', '2026-10-15 03:00:25', ['2026-10-06']);
        $evidence = $this->resolve('2026-10-06', $id);
        $this->assertNotNull($evidence->canonicalData);
        $this->assertSame(4460, $evidence->sourceEvidenceIds['interpretation_id']);
        $this->assertNotContains('canonical_interpretation_rejected_by_exact_target_validation', $evidence->provenanceFlags);
    }

    public function test_analysis_date_before_the_covering_observation_is_not_moved_to_the_target(): void
    {
        $id = $this->load('vaasa_analysis_date', '2026-10-03 03:00:26', '2026-10-15 03:00:25', ['2026-10-06']);
        $evidence = $this->resolve('2026-10-06', $id);
        $this->assertNull($evidence->canonicalData);
        $this->assertSame([4460], $evidence->sourceEvidenceIds['target_evidence_rejected_interpretation_ids']);
    }

    public function test_cited_quarter_boundary_proves_the_window_that_contains_the_target(): void
    {
        $id = $this->load('kerava_quarterly_calendar', '2026-09-30 03:00:26', '2026-10-15 03:00:25', ['2026-09-30', '2026-10-06']);
        $this->assertSame(4479, $this->resolve('2026-10-06', $id)->sourceEvidenceIds['interpretation_id']);
        // Before the window starts, "1.10." has no year from the target and stays unproved.
        $early = $this->resolve('2026-09-30', $id);
        $this->assertNull($early->canonicalData);
        $this->assertContains('historical_temporal_source_validation_unavailable', $early->provenanceFlags);
    }

    public function test_announced_next_month_uses_the_following_window_of_the_same_cadence(): void
    {
        $id = $this->load('kokkola_monthly_next_period', '2026-09-18 03:00:25', '2026-09-29 03:00:26', ['2026-09-22']);
        $this->assertSame(4061, $this->resolve('2026-09-22', $id)->sourceEvidenceIds['interpretation_id']);
    }

    public function test_relative_month_boundaries_make_no_calendar_claim(): void
    {
        $id = $this->load('sahkotytot_relative_months', '2026-07-23 19:15:12', '2026-10-15 03:00:25', ['2026-09-22']);
        $evidence = $this->resolve('2026-09-22', $id);
        $this->assertSame(1584, $evidence->sourceEvidenceIds['interpretation_id']);
        $this->assertNotContains('historical_temporal_source_validation_unavailable', $evidence->provenanceFlags);
    }

    /** @param list<string> $dates */
    private function load(string $key, string $firstObserved, string $lastObserved, array $dates): string
    {
        $case = json_decode(file_get_contents(base_path('tests/Fixtures/as-of-v3-target-date-proof.json')), true)[$key];
        $details = $case['payload']['Details'];
        $company = $case['payload']['Company']['Name'] ?? 'Target Date Oy';
        Company::create(['name' => $company, 'name_slug' => 'target-date-'.$key]);
        ElectricityContract::factory()->forCompany($company)->create(['id' => $case['contract_id']]);
        $source = ContractSourceSnapshot::create([
            'contract_id' => $case['contract_id'], 'source_fingerprint' => hash('sha256', $key),
            'source_payload' => $case['payload'], 'first_observed_at' => $firstObserved, 'last_observed_at' => $lastObserved,
        ]);
        ContractSourceObservation::create([
            'contract_id' => $case['contract_id'], 'source_snapshot_id' => $source->id,
            'first_observed_at' => $firstObserved, 'last_observed_at' => $lastObserved,
        ]);
        ContractInterpretation::forceCreate([
            'id' => $case['interpretation_id'], 'contract_id' => $case['contract_id'], 'source_snapshot_id' => $source->id,
            'analysis_fingerprint' => hash('sha256', 'analysis-'.$key), 'schema_version' => 'schema-v4',
            'prompt_version' => 'prompt-v19', 'validator_version' => 'validator-v17', 'provider' => 'test', 'model' => 'test',
            'status' => 'published', 'output' => $case['output'], 'validation_errors' => [], 'completed_at' => $firstObserved,
        ]);
        foreach ($dates as $date) {
            DB::table('contract_price_snapshots')->insert([
                'snapshot_date' => $date, 'contract_id' => $case['contract_id'], 'company_name' => $company,
                'contract_name' => $key, 'pricing_model' => $details['PricingModel'], 'contract_type' => $details['ContractType'],
                'metering' => $details['Metering'], 'segment_key' => 'quarterly', 'pricing_basis' => 'canonical_calculation',
                'has_discount' => false, 'includes_spot_price' => false,
                'annual_cost_2000_kwh' => 1, 'annual_cost_5000_kwh' => 1, 'annual_cost_18000_kwh' => 1,
            ]);
        }

        return $case['contract_id'];
    }

    private function resolve(string $date, string $contractId): AsOfAnnualCostEvidence
    {
        return app(AsOfAnnualCostEvidenceResolver::class)->resolveDate($date, AnnualCostMethodVersion::AsOfV3)[$contractId];
    }
}
