<?php

namespace App\Services\ContractStatistics\DTO;

use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use Carbon\CarbonImmutable;

readonly class AsOfSpotAssumptionsResult
{
    public const SOURCE_STORED_ROLLING_365D = 'spot_price_averages';

    public const SOURCE_HOURLY_RECONSTRUCTION = 'spot_price_hours';

    public const SOURCE_UNAVAILABLE = 'unavailable';

    public const EVIDENCE_COMPLETE = 'complete';

    public const EVIDENCE_PARTIAL_ABOVE_THRESHOLD = 'partial_above_threshold';

    public function __construct(
        public ?SpotAssumptions $assumptions,
        public string $source,
        public string $region,
        public CarbonImmutable $targetDate,
        public ?float $coverageRatio = null,
        public ?int $expectedHours = null,
        public ?int $actualHours = null,
        public ?int $hoursCount = null,
        /** @var list<string> */
        public array $provenanceFlags = [],
        public ?int $sourceRecordId = null,
        public ?string $unavailableReason = null,
    ) {}

    public function isAvailable(): bool
    {
        if (
            $this->assumptions === null
            || ! $this->assumptions->isAvailable()
            || $this->assumptions->overallAvgWithTax === null
            || $this->assumptions->periodStart === null
            || $this->assumptions->periodEnd === null
        ) {
            return false;
        }

        return is_finite($this->assumptions->dayAvgWithTax)
            && is_finite($this->assumptions->nightAvgWithTax)
            && is_finite($this->assumptions->overallAvgWithTax);
    }

    /** @param list<string> $provenanceFlags */
    public static function unavailable(
        string $region,
        CarbonImmutable $targetDate,
        string $reason,
        ?int $expectedHours = null,
        ?int $actualHours = null,
        array $provenanceFlags = [],
    ): self {
        return new self(
            assumptions: null,
            source: self::SOURCE_UNAVAILABLE,
            region: $region,
            targetDate: $targetDate,
            coverageRatio: $expectedHours !== null && $expectedHours > 0 && $actualHours !== null
                ? $actualHours / $expectedHours
                : null,
            expectedHours: $expectedHours,
            actualHours: $actualHours,
            hoursCount: $actualHours,
            provenanceFlags: $provenanceFlags,
            unavailableReason: $reason,
        );
    }
}
