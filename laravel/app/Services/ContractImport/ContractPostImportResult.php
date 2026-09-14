<?php

namespace App\Services\ContractImport;

use Carbon\CarbonImmutable;

final readonly class ContractPostImportResult
{
    /**
     * @param  array<string, string>  $requiredFailures
     * @param  array<string, string>  $optionalFailures
     * @param  list<int>  $interpretationDispatchFailureObservationIds
     */
    public function __construct(
        public array $requiredFailures,
        public array $optionalFailures,
        public array $interpretationDispatchFailureObservationIds,
        public ?CarbonImmutable $statisticsStartedAt,
        public ?CarbonImmutable $statisticsCompletedAt,
        /** @var array<string, \Throwable> */
        public array $requiredExceptions = [],
        public bool $deferred = false,
        public array $completionMetadata = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->requiredFailures === [];
    }
}
