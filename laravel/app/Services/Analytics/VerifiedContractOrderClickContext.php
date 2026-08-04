<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;

final readonly class VerifiedContractOrderClickContext
{
    public function __construct(
        public string $contractId,
        public string $contractName,
        public string $companyName,
        public ?float $annualPriceEur,
        public int $consumptionKwh,
        public ?int $priceRank,
        public ?int $rankTotal,
        public ?int $rankConsumptionKwh,
        public bool $isEstimate,
        public ?string $pricingBasis,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $expiresAt,
    ) {}
}
