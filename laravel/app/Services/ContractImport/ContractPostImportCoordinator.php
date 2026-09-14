<?php

namespace App\Services\ContractImport;

use App\Models\ContractSourceObservation;
use App\Services\ContractInterpretation\ContractInterpretationDispatcher;
use App\Services\ContractStatistics\ContractPercentileService;
use Throwable;

class ContractPostImportCoordinator
{
    public function __construct(
        private readonly ContractInterpretationDispatcher $interpretationDispatcher,
        private readonly ContractImportCompletion $completion,
        private readonly ContractPercentileService $percentiles,
    ) {}

    public function run(ContractImportResult $import, string $importDate, ?string $runUuid = null): ContractPostImportResult
    {
        $optionalFailures = [];
        $dispatchFailureIds = [];
        $targets = [];

        // Preserve the exact dispatcher return, including date-scoped fallback ownership.
        foreach ($import->observedObservationIds as $observationId) {
            try {
                $observation = ContractSourceObservation::query()->with('sourceSnapshot')->findOrFail($observationId);
                $targets[$observationId] = $this->interpretationDispatcher->dispatch($observation)?->id;
            } catch (Throwable) {
                $dispatchFailureIds[] = $observationId;
                $optionalFailures["interpretation:{$observationId}"] = 'Interpretation dispatch failed.';
            }
        }

        $required = $this->completion->initial($import, $importDate, $runUuid, $targets);
        try {
            $this->percentiles->calculate();
        } catch (Throwable) {
            $optionalFailures['percentiles'] = 'Percentile calculation failed.';
        }

        return new ContractPostImportResult(
            requiredFailures: $required->requiredFailures,
            optionalFailures: $optionalFailures + $required->optionalFailures,
            interpretationDispatchFailureObservationIds: $dispatchFailureIds,
            statisticsStartedAt: $required->statisticsStartedAt,
            statisticsCompletedAt: $required->statisticsCompletedAt,
            requiredExceptions: $required->requiredExceptions,
            deferred: $required->deferred,
            completionMetadata: $required->completionMetadata,
        );
    }
}
