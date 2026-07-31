<?php

namespace App\Console\Commands;

use App\Models\ElectricityContract;
use App\Services\ContractInterpretation\ContractInterpretationDispatcher;
use Illuminate\Console\Command;

class InterpretContracts extends Command
{
    protected $signature = 'contracts:interpret
        {--contract= : One local contract ID}
        {--include-inactive : Include contracts that are not currently active}
        {--retry-failed : Queue failed interpretations again}';

    protected $description = 'Interpret the source snapshot from each pointed contract observation';

    public function handle(ContractInterpretationDispatcher $dispatcher): int
    {
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
}
