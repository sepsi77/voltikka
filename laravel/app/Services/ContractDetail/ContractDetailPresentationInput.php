<?php

namespace App\Services\ContractDetail;

use App\Models\ElectricityContract;

final readonly class ContractDetailPresentationInput
{
    /**
     * @param  array{general: ?float, day: ?float, night: ?float, winter: ?float, other: ?float, margin: ?float, fee: ?float, package_included_kwh: ?float, package_excess_rate: ?float}  $currentDisplayValues
     * @param  array<string, mixed>  $calculatedCost
     * @param  array<string, array<array{date: string, price: float|int}>>  $relationalPriceHistory
     * @param  array<string, mixed>|null  $cheaperContractSummary
     * @param  array<string, mixed>  $co2Facts
     * @param  list<array{id: string, question: string, answer: string}>  $faqItems
     */
    public function __construct(
        public ?ElectricityContract $contract,
        public bool $isActive,
        public string $displayName,
        public ?int $priceRank,
        public int $totalContracts,
        public int $consumption,
        public array $currentDisplayValues,
        public array $calculatedCost,
        public array $relationalPriceHistory,
        public ?array $cheaperContractSummary,
        public bool $isPricingExcluded,
        public array $co2Facts,
        public string $canonicalUrl,
        public string $applicationUrl,
        public array $faqItems,
    ) {}
}
