<?php

namespace Tests\Feature;

use App\Services\CanonicalPricing\SupplierAdjusted\CurrentPriceEpisodeResolver;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CurrentPriceEpisodeChronologyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.episodes', ['driver' => 'sqlite', 'database' => ':memory:']);
        DB::setDefaultConnection('episodes');
        // Allow duplicate dates to test evidence ordering independently of storage uniqueness.
        DB::statement('CREATE TABLE contract_price_snapshots (contract_id TEXT, snapshot_date TEXT, pricing_basis TEXT, energy_price_cents_per_kwh REAL, monthly_fee_eur REAL)');
        DB::statement('CREATE TABLE electricity_contracts (id TEXT, current_source_observation_id INTEGER, published_interpretation_id INTEGER)');
        DB::statement('CREATE TABLE contract_source_observations (id INTEGER, contract_id TEXT, source_snapshot_id INTEGER, first_observed_at TEXT)');
        DB::statement('CREATE TABLE contract_interpretations (id INTEGER, contract_id TEXT, source_snapshot_id INTEGER, status TEXT)');
    }

    public function test_recurring_price_uses_latest_episode_across_both_bases(): void
    {
        $this->snapshot('a', '2026-06-01', 8, 'observed_seller_data');
        $this->snapshot('a', '2026-06-02', 8, 'observed_seller_data');
        $this->snapshot('a', '2026-07-01', 10);
        $this->snapshot('a', '2026-08-01', 8);
        $this->snapshot('a', '2026-08-02', 8);
        $this->snapshot('b', '2026-08-01', 8, 'observed_seller_data');
        $this->snapshot('b', '2026-08-02', 8);

        DB::enableQueryLog();
        $anchors = (new CurrentPriceEpisodeResolver)->resolve($this->candidates(['a', 'b']));

        $this->assertCount(1, DB::getQueryLog());
        foreach ($anchors as $anchor) {
            $this->assertSame('2026-08-01', $anchor->startedAt?->toDateString());
            $this->assertSame(PriceEpisodeEvidenceBasis::CanonicalSnapshotRun, $anchor->evidenceBasis);
        }
    }

    public function test_daily_duplicates_are_deterministic_and_do_not_break_continuity(): void
    {
        foreach (['forward', 'reverse'] as $id) {
            $rows = [
                ['2026-08-01', 8, 'observed_seller_data'],
                ['2026-08-01', 10, 'canonical_calculation'],
                ['2026-08-02', 8, 'canonical_calculation'],
                ['2026-08-02', null, 'observed_seller_data'],
                ['2026-08-03', 8, 'canonical_calculation'],
                ['2026-08-03', 8, 'canonical_calculation'],
            ];
            foreach ($id === 'forward' ? $rows : array_reverse($rows) as [$date, $rate, $basis]) {
                $this->snapshot($id, $date, $rate, $basis);
            }
        }
        $anchors = (new CurrentPriceEpisodeResolver)->resolve($this->candidates(['forward', 'reverse']));
        foreach ($anchors as $anchor) {
            $this->assertSame('2026-08-01', $anchor->startedAt?->toDateString());
            $this->assertSame(PriceEpisodeEvidenceBasis::CanonicalSnapshotRun, $anchor->evidenceBasis);
        }
    }

    public function test_even_one_missing_day_starts_a_new_run(): void
    {
        $this->snapshot('gap', '2026-08-01', 8, 'observed_seller_data');
        $this->snapshot('gap', '2026-08-03', 8);
        $this->snapshot('gap', '2026-08-04', 8);

        $anchor = (new CurrentPriceEpisodeResolver)->resolve($this->candidates(['gap']))['gap'];

        $this->assertSame('2026-08-03', $anchor->startedAt?->toDateString());
    }

    public function test_latest_nonmatching_or_unknown_evidence_uses_only_valid_current_source_fallback(): void
    {
        $ids = ['source', 'mismatch', 'missing', 'unknown', 'conflict', 'observed-change'];
        foreach ($ids as $id) {
            $this->snapshot($id, '2026-06-01', 8, 'observed_seller_data');
            $this->snapshot($id, '2026-08-01', $id === 'unknown' ? null : 10);
        }
        $this->snapshot('conflict', '2026-08-01', 8);
        $this->snapshot('observed-change', '2026-08-01', 8);
        $this->snapshot('observed-change', '2026-08-01', 10, 'observed_seller_data');
        foreach (['source' => 1, 'mismatch' => 2] as $id => $key) {
            DB::table('electricity_contracts')->insert(['id' => $id, 'current_source_observation_id' => $key, 'published_interpretation_id' => $key]);
            DB::table('contract_source_observations')->insert(['id' => $key, 'contract_id' => $id, 'source_snapshot_id' => $key, 'first_observed_at' => '2026-08-02 06:00:00']);
            DB::table('contract_interpretations')->insert(['id' => $key, 'contract_id' => $id, 'source_snapshot_id' => $id === 'source' ? $key : 99, 'status' => 'published']);
        }

        DB::enableQueryLog();
        $anchors = (new CurrentPriceEpisodeResolver)->resolve($this->candidates($ids));

        $this->assertCount(2, DB::getQueryLog());
        $this->assertSame('2026-08-02', $anchors['source']->startedAt?->toDateString());
        $this->assertSame(PriceEpisodeEvidenceBasis::CurrentSourceObservation, $anchors['source']->evidenceBasis);
        foreach (array_slice($ids, 1) as $id) {
            $this->assertNull($anchors[$id]->startedAt);
            $this->assertSame(PriceEpisodeEvidenceBasis::Missing, $anchors[$id]->evidenceBasis);
            $this->assertSame(['missing_price_episode_anchor'], $anchors[$id]->flags);
        }
    }

    private function snapshot(string $id, string $date, ?float $rate, string $basis = 'canonical_calculation'): void
    {
        DB::table('contract_price_snapshots')->insert([
            'contract_id' => $id,
            'snapshot_date' => $date,
            'pricing_basis' => $basis,
            'energy_price_cents_per_kwh' => $rate,
            'monthly_fee_eur' => 0,
        ]);
    }

    private function candidates(array $ids): array
    {
        $candidates = [];
        foreach ($ids as $id) {
            $candidates[$id] = new SupplierAdjustedCandidate($id, 8, 0);
        }

        return $candidates;
    }
}
