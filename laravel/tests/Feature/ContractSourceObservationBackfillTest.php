<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractSourceObservationBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_overlapping_snapshot_ranges_become_full_episodes(): void
    {
        $contract = $this->contract('non-overlap');
        $first = $this->snapshot($contract, 'a', '2026-07-01 06:00:00', '2026-07-03 06:00:00');
        $second = $this->snapshot($contract, 'b', '2026-07-04 06:00:00', '2026-07-06 06:00:00');

        $this->backfill()->up();

        $episodes = DB::table('contract_source_observations')->orderBy('id')->get();
        $this->assertCount(2, $episodes);
        $this->assertSame('2026-07-01 06:00:00', $episodes[0]->first_observed_at);
        $this->assertSame('2026-07-03 06:00:00', $episodes[0]->last_observed_at);
        $this->assertSame($second->id, $episodes[1]->source_snapshot_id);
        $this->assertSame($episodes[1]->id, $contract->fresh()->current_source_observation_id);
        $this->assertSame([$first->id, $second->id], ContractSourceSnapshot::orderBy('id')->pluck('id')->all());
    }

    public function test_overlapping_ranges_become_event_points_and_leave_intervals_unknown(): void
    {
        $contract = $this->contract('overlap');
        $first = $this->snapshot($contract, 'a', '2026-07-01 06:00:00', '2026-07-05 06:00:00');
        $second = $this->snapshot($contract, 'b', '2026-07-03 06:00:00', '2026-07-04 06:00:00');

        $this->backfill()->up();

        $episodes = DB::table('contract_source_observations')->orderBy('first_observed_at')->get();
        $this->assertCount(4, $episodes);
        $this->assertTrue($episodes->every(
            fn (object $episode) => $episode->first_observed_at === $episode->last_observed_at,
        ));
        $this->assertSame(0, DB::table('contract_source_observations')
            ->where('first_observed_at', '<=', '2026-07-02 23:59:59')
            ->where('last_observed_at', '>=', '2026-07-02 00:00:00')
            ->count());
        $this->assertSame($first->id, DB::table('contract_source_observations')
            ->where('id', $contract->fresh()->current_source_observation_id)
            ->value('source_snapshot_id'));
        $this->assertSame([$first->id, $second->id], ContractSourceSnapshot::orderBy('id')->pluck('id')->all());
    }

    public function test_preflight_failure_writes_no_episode_or_pointer(): void
    {
        $validContract = $this->contract('valid-before-invalid');
        $this->snapshot($validContract, 'a', '2026-07-01 06:00:00', '2026-07-02 06:00:00');
        $invalidContract = $this->contract('invalid-range');
        $this->snapshot($invalidContract, 'b', '2026-07-03 06:00:00', '2026-07-02 06:00:00');

        try {
            $this->backfill()->up();
            $this->fail('The invalid range must fail preflight.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('first_observed_at after last_observed_at', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('contract_source_observations')->count());
        $this->assertSame(0, DB::table('electricity_contracts')->whereNotNull('current_source_observation_id')->count());
    }

    private function contract(string $id): ElectricityContract
    {
        Company::firstOrCreate(['name' => 'Backfill Energy'], ['name_slug' => 'backfill-energy']);

        return ElectricityContract::create([
            'id' => $id,
            'api_id' => $id.'-api',
            'company_name' => 'Backfill Energy',
            'name' => $id,
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'FixedPrice',
            'availability_is_national' => true,
        ]);
    }

    private function snapshot(
        ElectricityContract $contract,
        string $version,
        string $first,
        string $last,
    ): ContractSourceSnapshot {
        return ContractSourceSnapshot::create([
            'contract_id' => $contract->id,
            'source_fingerprint' => hash('sha256', $contract->id.$version),
            'source_payload' => ['version' => $version],
            'first_observed_at' => $first,
            'last_observed_at' => $last,
        ]);
    }

    private function backfill(): object
    {
        return require database_path('migrations/2026_07_30_000002_backfill_contract_source_observations.php');
    }
}
