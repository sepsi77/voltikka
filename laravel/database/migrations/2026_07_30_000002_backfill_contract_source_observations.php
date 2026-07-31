<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->assertEmptyTarget();

            // Lock contracts before their snapshots. The broad stable-order lock keeps
            // planning and writes in one consistent view without guessing worker order.
            DB::table('electricity_contracts')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            $snapshotsByContract = DB::table('contract_source_snapshots')
                ->orderBy('contract_id')
                ->orderBy('first_observed_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'contract_id', 'first_observed_at', 'last_observed_at'])
                ->groupBy('contract_id');
            $plans = $this->buildPlans($snapshotsByContract);

            // Complete every preflight check before the first insert.
            $this->assertEmptyTarget();

            foreach ($plans as $plan) {
                $currentObservationId = null;

                foreach ($plan['episodes'] as $episode) {
                    $observationId = DB::table('contract_source_observations')->insertGetId($episode);

                    if ($episode['source_snapshot_id'] === $plan['current_snapshot_id']
                        && $episode['last_observed_at'] === $plan['current_at']) {
                        $currentObservationId = $observationId;
                    }
                }

                if ($currentObservationId === null) {
                    throw new \RuntimeException("Contract {$plan['contract_id']} has no current source observation after reconstruction.");
                }

                DB::table('electricity_contracts')
                    ->where('id', $plan['contract_id'])
                    ->update(['current_source_observation_id' => $currentObservationId]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('electricity_contracts')->update(['current_source_observation_id' => null]);
            DB::table('contract_source_observations')->delete();
        });
    }

    /**
     * @return list<array{contract_id: string, episodes: list<array<string, int|string>>, current_snapshot_id: int, current_at: string}>
     */
    private function buildPlans($snapshotsByContract): array
    {
        $plans = [];

        foreach ($snapshotsByContract as $contractId => $snapshots) {
            foreach ($snapshots as $snapshot) {
                if ((string) $snapshot->first_observed_at > (string) $snapshot->last_observed_at) {
                    throw new \RuntimeException("Source snapshot {$snapshot->id} has first_observed_at after last_observed_at.");
                }
            }

            $overlaps = false;
            $greatestPriorEnd = null;
            foreach ($snapshots as $snapshot) {
                $first = (string) $snapshot->first_observed_at;
                $last = (string) $snapshot->last_observed_at;

                if ($greatestPriorEnd !== null && $first <= $greatestPriorEnd) {
                    $overlaps = true;
                    break;
                }

                if ($greatestPriorEnd === null || $last > $greatestPriorEnd) {
                    $greatestPriorEnd = $last;
                }
            }

            $episodes = [];
            if (! $overlaps) {
                foreach ($snapshots as $snapshot) {
                    $episodes[] = [
                        'contract_id' => (string) $contractId,
                        'source_snapshot_id' => (int) $snapshot->id,
                        'first_observed_at' => (string) $snapshot->first_observed_at,
                        'last_observed_at' => (string) $snapshot->last_observed_at,
                    ];
                }
            } else {
                $seenEvents = [];
                foreach ($snapshots as $snapshot) {
                    foreach ([(string) $snapshot->first_observed_at, (string) $snapshot->last_observed_at] as $eventAt) {
                        $eventKey = $snapshot->id.'|'.$eventAt;
                        if (isset($seenEvents[$eventKey])) {
                            continue;
                        }

                        $seenEvents[$eventKey] = true;
                        $episodes[] = [
                            'contract_id' => (string) $contractId,
                            'source_snapshot_id' => (int) $snapshot->id,
                            'first_observed_at' => $eventAt,
                            'last_observed_at' => $eventAt,
                        ];
                    }
                }
            }

            $greatestAt = collect($episodes)->max('last_observed_at');
            $greatestSnapshotIds = collect($episodes)
                ->where('last_observed_at', $greatestAt)
                ->pluck('source_snapshot_id')
                ->unique()
                ->values();

            if ($greatestSnapshotIds->count() !== 1) {
                throw new \RuntimeException("Contract {$contractId} has different source snapshots tied at the greatest observation timestamp.");
            }

            $plans[] = [
                'contract_id' => (string) $contractId,
                'episodes' => $episodes,
                'current_snapshot_id' => (int) $greatestSnapshotIds->first(),
                'current_at' => $greatestAt,
            ];
        }

        return $plans;
    }

    private function assertEmptyTarget(): void
    {
        if (DB::table('contract_source_observations')->exists()
            || DB::table('electricity_contracts')->whereNotNull('current_source_observation_id')->exists()) {
            throw new \RuntimeException('Source observation backfill requires an empty observation table and null contract pointers. Roll back or clean the incomplete target before retrying.');
        }
    }
};
