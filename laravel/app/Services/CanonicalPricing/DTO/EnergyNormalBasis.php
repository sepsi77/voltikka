<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\Enums\EnergyPriceRuleKind;

final readonly class EnergyNormalBasis
{
    public function __construct(
        public EnergyPriceRuleKind $kind = EnergyPriceRuleKind::Unknown,
        public ?PhaseBoundary $starts = null,
        public ?PhaseBoundary $ends = null,
    ) {}
}
