<?php

namespace App\Services\ContractImport;

use Carbon\CarbonImmutable;

final readonly class ContractPostImportResult
{
    /**
     * @param  array<string, string>  $requiredFailures
     * @param  array<string, string>  $optionalFailures
     * @param  list<int>  $interpretationDispatchFailureSnapshotIds
     */
    public function __construct(
        public array $requiredFailures,
        public array $optionalFailures,
        public array $interpretationDispatchFailureSnapshotIds,
        public ?CarbonImmutable $statisticsStartedAt,
        public ?CarbonImmutable $statisticsCompletedAt,
    ) {}

    public function succeeded(): bool
    {
        return $this->requiredFailures === [];
    }
}
