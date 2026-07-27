<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\Enums\BoundaryKind;
use Carbon\CarbonImmutable;

/**
 * One exact canonical promotion period and all billed components changed in it.
 */
readonly class OfferTermData
{
    /**
     * @param  list<OfferComponentData>  $components
     */
    public function __construct(
        public BoundaryKind $endKind,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
        public ?int $durationMonths,
        public ?int $startsAfterMonths,
        public ?int $endsAfterMonths,
        public bool $startsAtWindowStart,
        public array $components,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'end_kind' => $this->endKind->value,
            'starts_on' => $this->startsOn->toDateString(),
            'ends_on' => $this->endsOn->toDateString(),
            'duration_months' => $this->durationMonths,
            'starts_after_months' => $this->startsAfterMonths,
            'ends_after_months' => $this->endsAfterMonths,
            'starts_at_window_start' => $this->startsAtWindowStart,
            'components' => array_map(
                static fn (OfferComponentData $component): array => $component->toArray(),
                $this->components,
            ),
        ];
    }
}
