<?php

namespace App\Services\ImportPayload;

use Carbon\CarbonImmutable;

/**
 * What a fetch step hands to its store step: where the finished payload is and how to verify it.
 * Only this reference passes through Temporal or into checkpoint metadata, never the payload.
 */
final readonly class ImportPayloadReference
{
    public function __construct(
        public string $path,
        public string $sha256,
        public int $size,
        public CarbonImmutable $fetchedAt,
        public string $source,
        public string $runId,
        public int $requests,
        public int $failedUnits,
    ) {}

    /** A run is complete when every planned request got an answer the command accepted. */
    public function complete(): bool
    {
        return $this->failedUnits === 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'sha256' => $this->sha256,
            'size' => $this->size,
            'fetched_at' => $this->fetchedAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'source' => $this->source,
            'run_id' => $this->runId,
            'requests' => $this->requests,
            'failed_units' => $this->failedUnits,
            'complete' => $this->complete(),
        ];
    }
}
