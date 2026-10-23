<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeContractSourceSnapshot;
use App\Models\ContractInterpretation;
use App\Models\ElectricityContract;
use App\Services\ContractInterpretation\ContractInterpretationDispatcher;
use Illuminate\Console\Command;

class InterpretContracts extends Command
{
    protected $signature = 'contracts:interpret
        {--contract= : One local contract ID}
        {--include-inactive : Include contracts that are not currently active}
        {--retry-failed : Queue failed interpretations again}
        {--history-only=* : Queue these failed interpretation IDs of non-current sources for dated statistics only; never publishes}';

    protected $description = 'Interpret the source snapshot from each pointed contract observation';

    public function handle(ContractInterpretationDispatcher $dispatcher): int
    {
        if ($this->option('history-only') !== []) {
            return $this->queueHistoryOnly($this->option('history-only'));
        }

        $query = ElectricityContract::query()->with('currentSourceObservation.sourceSnapshot');

        if ($contractId = $this->option('contract')) {
            $query->whereKey($contractId);
        } elseif (! $this->option('include-inactive')) {
            $query->active();
        }

        $queued = 0;
        $skipped = 0;

        $query->chunkById(100, function ($contracts) use ($dispatcher, &$queued, &$skipped): void {
            foreach ($contracts as $contract) {
                $observation = $contract->currentSourceObservation;
                if ($observation === null || $observation->sourceSnapshot === null) {
                    $skipped++;

                    continue;
                }

                $interpretation = $dispatcher->dispatch(
                    $observation,
                    runWhenDisabled: true,
                    retryFailed: (bool) $this->option('retry-failed'),
                );

                if ($interpretation?->wasRecentlyCreated
                    || ($this->option('retry-failed') && $interpretation?->status === 'pending')) {
                    $queued++;
                } else {
                    $skipped++;
                }
            }
        }, 'id');

        $this->info("Queued {$queued} contract interpretations; skipped {$skipped}.");

        return self::SUCCESS;
    }

    /** @param list<string> $ids */
    private function queueHistoryOnly(array $ids): int
    {
        if (! config('services.openrouter.api_key')) {
            $this->error('OPENROUTER_API_KEY is not configured.');

            return self::FAILURE;
        }

        $queued = 0;
        foreach ($ids as $id) {
            $interpretation = ContractInterpretation::query()->with('contract')->find($id);
            $current = $interpretation?->contract?->currentSourceObservation;
            if ($interpretation === null || $interpretation->status !== ContractInterpretation::STATUS_FAILED
                || $current === null || $current->source_snapshot_id === $interpretation->source_snapshot_id) {
                $this->warn("Skipped {$id}: it must be a failed interpretation of a source that is no longer current.");

                continue;
            }

            // Keep the attempt history; the job merges earlier attempts.
            $interpretation->update(['status' => ContractInterpretation::STATUS_PENDING, 'error' => null, 'started_at' => null, 'completed_at' => null]);
            AnalyzeContractSourceSnapshot::dispatch($interpretation->id, historyOnly: true);
            $queued++;
        }

        $this->info("Queued {$queued} history-only contract interpretations.");

        return self::SUCCESS;
    }
}
