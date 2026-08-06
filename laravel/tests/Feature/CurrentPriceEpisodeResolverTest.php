<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\SupplierAdjusted\CurrentPriceEpisodeResolver;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CurrentPriceEpisodeResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Company::create(['name' => 'Episode Energy Oy', 'name_slug' => 'episode-energy-oy']);
    }

    public function test_snapshot_runs_for_many_candidates_resolve_in_one_batched_query(): void
    {
        $this->contract('episode-a');
        $this->contract('episode-b');

        foreach ([
            ['episode-a', '2026-05-01', 'canonical_calculation', 7.4, 4.2],
            ['episode-a', '2026-06-01', 'observed_seller_data', 9.4, 4.2],
            ['episode-a', '2026-06-02', 'observed_seller_data', 7.4, 4.2],
            ['episode-a', '2026-06-03', 'observed_seller_data', 7.4, 4.2],
            ['episode-a', '2026-06-05', 'observed_seller_data', 7.4, 4.2],
            ['episode-a', '2026-06-06', 'observed_seller_data', 7.4, 4.2],
            ['episode-b', '2026-07-01', 'canonical_calculation', 8.0, 3.0],
            ['episode-b', '2026-07-02', 'canonical_calculation', 8.0, 3.0],
        ] as [$id, $date, $basis, $energy, $fee]) {
            $this->snapshot($id, $date, $basis, $energy, $fee);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $anchors = (new CurrentPriceEpisodeResolver)->resolve([
            'episode-a' => new SupplierAdjustedCandidate('episode-a', 7.4, 4.2),
            'episode-b' => new SupplierAdjustedCandidate('episode-b', 8.0, 3.0),
        ]);

        $this->assertCount(1, $queries, implode("\n", $queries));
        $this->assertSame('2026-06-05', $anchors['episode-a']->startedAt?->toDateString());
        $this->assertSame(PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun, $anchors['episode-a']->evidenceBasis);
        $this->assertSame('2026-07-01', $anchors['episode-b']->startedAt?->toDateString());
        $this->assertSame(PriceEpisodeEvidenceBasis::CanonicalSnapshotRun, $anchors['episode-b']->evidenceBasis);
    }

    public function test_weighted_time_and_season_representatives_match_snapshot_metrics(): void
    {
        $this->contract('time-rate');
        $this->contract('season-rate');
        $this->snapshot('time-rate', '2026-06-01', 'observed_seller_data', (8 * 15 + 4 * 9) / 24, 4.65);
        $this->snapshot('season-rate', '2026-05-01', 'observed_seller_data', (12 * 5 + 4 * 7) / 12, 4.65);

        $anchors = (new CurrentPriceEpisodeResolver)->resolve([
            'time-rate' => new SupplierAdjustedCandidate('time-rate', 6.5, 4.65),
            'season-rate' => new SupplierAdjustedCandidate('season-rate', 22 / 3, 4.65),
        ]);

        $this->assertSame('2026-06-01', $anchors['time-rate']->startedAt?->toDateString());
        $this->assertSame('2026-05-01', $anchors['season-rate']->startedAt?->toDateString());
        $this->assertSame(PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun, $anchors['time-rate']->evidenceBasis);
        $this->assertSame(PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun, $anchors['season-rate']->evidenceBasis);
    }

    public function test_source_observation_fallback_requires_the_published_snapshot_to_match(): void
    {
        $matching = $this->contract('source-match');
        $missing = $this->contract('source-mismatch');
        $matchingSnapshot = $this->sourceSnapshot($matching, 'a');
        $otherSnapshot = $this->sourceSnapshot($matching, 'b');
        $mismatchedCurrent = $this->sourceSnapshot($missing, 'c');
        $mismatchedPublished = $this->sourceSnapshot($missing, 'd');

        $matchingObservation = $this->observation($matching, $matchingSnapshot, '2026-06-01 06:00:00');
        $mismatchedObservation = $this->observation($missing, $mismatchedCurrent, '2026-06-02 06:00:00');
        $matchingInterpretation = $this->interpretation($matching, $matchingSnapshot, 'e');
        $mismatchedInterpretation = $this->interpretation($missing, $mismatchedPublished, 'f');
        $matching->update([
            'current_source_observation_id' => $matchingObservation->id,
            'published_interpretation_id' => $matchingInterpretation->id,
        ]);
        $missing->update([
            'current_source_observation_id' => $mismatchedObservation->id,
            'published_interpretation_id' => $mismatchedInterpretation->id,
        ]);

        $anchors = (new CurrentPriceEpisodeResolver)->resolve([
            'source-match' => new SupplierAdjustedCandidate('source-match', 7.4, 4.2),
            'source-mismatch' => new SupplierAdjustedCandidate('source-mismatch', 8.0, 3.0),
        ]);

        $this->assertSame('2026-06-01', $anchors['source-match']->startedAt?->toDateString());
        $this->assertSame(PriceEpisodeEvidenceBasis::CurrentSourceObservation, $anchors['source-match']->evidenceBasis);
        $this->assertSame(PriceEpisodeEvidenceBasis::Missing, $anchors['source-mismatch']->evidenceBasis);
        $this->assertNull($anchors['source-mismatch']->startedAt);

        // Keep this otherwise unused snapshot explicit: the current observation must match the
        // exact published interpretation snapshot, not another snapshot for the same contract.
        $this->assertNotSame($otherSnapshot->id, $matchingInterpretation->source_snapshot_id);
    }

    private function contract(string $id): ElectricityContract
    {
        return ElectricityContract::factory()->forCompany('Episode Energy Oy')->create([
            'id' => $id,
            'name' => $id,
        ]);
    }

    private function snapshot(string $contractId, string $date, string $basis, float $energy, float $fee): void
    {
        DB::table('contract_price_snapshots')->insert([
            'snapshot_date' => $date,
            'contract_id' => $contractId,
            'company_name' => 'Episode Energy Oy',
            'contract_name' => $contractId,
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'segment_key' => 'fixed',
            'pricing_basis' => $basis,
            'energy_price_cents_per_kwh' => $energy,
            'monthly_fee_eur' => $fee,
            'has_discount' => false,
            'includes_spot_price' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sourceSnapshot(ElectricityContract $contract, string $fingerprint): ContractSourceSnapshot
    {
        return ContractSourceSnapshot::create([
            'contract_id' => $contract->id,
            'source_fingerprint' => str_repeat($fingerprint, 64),
            'source_payload' => ['id' => $contract->id, 'version' => $fingerprint],
            'first_observed_at' => '2026-06-01 06:00:00',
            'last_observed_at' => '2026-06-02 06:00:00',
        ]);
    }

    private function observation(ElectricityContract $contract, ContractSourceSnapshot $snapshot, string $date): ContractSourceObservation
    {
        return ContractSourceObservation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $snapshot->id,
            'first_observed_at' => $date,
            'last_observed_at' => $date,
        ]);
    }

    private function interpretation(ElectricityContract $contract, ContractSourceSnapshot $snapshot, string $fingerprint): ContractInterpretation
    {
        return ContractInterpretation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $snapshot->id,
            'analysis_fingerprint' => str_repeat($fingerprint, 64),
            'status' => ContractInterpretation::STATUS_PUBLISHED,
            'schema_version' => 'test-schema',
            'prompt_version' => 'test-prompt',
            'validator_version' => 'test-validator',
            'provider' => 'test',
            'model' => 'test',
            'published_at' => '2026-06-03 06:00:00',
        ]);
    }
}
