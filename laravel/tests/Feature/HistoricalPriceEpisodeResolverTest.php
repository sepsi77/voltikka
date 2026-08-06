<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use App\Services\ContractStatistics\ContractPriceBasis;
use App\Services\ContractStatistics\HistoricalPriceEpisodeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistoricalPriceEpisodeResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
