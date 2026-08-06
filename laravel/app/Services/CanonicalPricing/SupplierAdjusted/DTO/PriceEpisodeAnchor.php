<?php

namespace App\Services\CanonicalPricing\SupplierAdjusted\DTO;

use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use Carbon\CarbonImmutable;

readonly class PriceEpisodeAnchor
{
    /** @param list<string> $flags */
    public function __construct(
        public ?CarbonImmutable $startedAt,
        public PriceEpisodeEvidenceBasis $evidenceBasis,
        public array $flags = [],
    ) {}

    public static function missing(): self
    {
        return new self(null, PriceEpisodeEvidenceBasis::Missing, ['missing_price_episode_anchor']);
    }
}
