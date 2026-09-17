<?php

namespace App\Services\CanonicalPricing\ForwardPremium;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class PremiumTarget
{
    public CarbonImmutable $asOfDate;

    public function __construct(
        public string $contractId,
        public string $lineageId,
        public string $companyName,
        public PremiumCompatibility $compatibility,
        CarbonImmutable $asOfDate,
    ) {
        if (trim($contractId) === '' || trim($lineageId) === '' || trim($companyName) === '') {
            throw new InvalidArgumentException('Target requires contract, trusted lineage, and exact source company.');
        }
        $this->asOfDate = $asOfDate->startOfDay();
    }
}
