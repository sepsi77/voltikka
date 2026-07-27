<?php

namespace App\Services\DTO;

/**
 * One ranked row in a bill comparison result.
 *
 * Market contracts and the user's own bill share this shape so the view can
 * render a single ranked table and slot the user's row at its real position.
 */
class BillComparisonRow
{
    public function __construct(
        public readonly ?string $contractId,
        public readonly string $name,
        public readonly string $companyName,
        public readonly ?string $companySlug,
        public readonly ?string $pricingModel,
        public readonly ?string $contractType,
        public readonly float $periodCostEur,
        public readonly ?float $annualCostEur,
        public readonly float $impliedCentsPerKwh,
        public readonly bool $isSpot,
        public readonly ?float $spotMargin,
        public readonly bool $hasPromo,
        public readonly bool $isUser,
        public readonly ?string $detailUrl = null,
        public readonly bool $available = true,
        public readonly ?string $pricingBasis = null,
        public readonly ?string $comparability = null,
        public readonly array $assumptions = [],
    ) {}
}
