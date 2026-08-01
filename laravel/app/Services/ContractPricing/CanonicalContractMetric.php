<?php

namespace App\Services\ContractPricing;

use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\DTO\ContractPricingIntegrity;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use InvalidArgumentException;

/**
 * One typed canonical batch evaluation. Arrays exist only for stable transport compatibility.
 */
final readonly class CanonicalContractMetric
{
    private function __construct(
        private ContractPricingViewData $pricing,
        private ContractComparability $comparability,
        private bool $listed,
        private ?float $sortKey,
        private ContractPricingIntegrity $integrity,
    ) {}

    public static function fromEvaluation(
        CanonicalPricingOutcome $outcome,
        ContractPricingIntegrity $integrity,
    ): self {
        return new self(
            pricing: ContractPricingViewData::fromCanonicalOutcome($outcome),
            comparability: $outcome->comparability,
            listed: $outcome->isListed(),
            sortKey: $outcome->isListed() ? self::finiteSortKey($outcome->totalCost) : null,
            integrity: $integrity,
        );
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        foreach (['calculated_cost', 'comparability', 'is_listed', 'sort_key', 'integrity'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new InvalidArgumentException('Canonical metric is missing required key '.$key.'.');
            }
        }
        if (! is_array($payload['calculated_cost']) || ! is_array($payload['integrity'])) {
            throw new InvalidArgumentException('Canonical metric pricing and integrity must be arrays.');
        }

        $pricing = ContractPricingViewData::fromArray($payload['calculated_cost']);
        $comparability = is_string($payload['comparability'])
            ? ContractComparability::tryFrom($payload['comparability'])
            : null;
        if ($comparability === null || ! is_bool($payload['is_listed'])) {
            throw new InvalidArgumentException('Canonical metric comparability or listability is invalid.');
        }
        if ($pricing->pricingBasis() !== 'canonical' || $pricing->comparability() !== $comparability) {
            throw new InvalidArgumentException('Canonical metric pricing does not match its comparability.');
        }

        $listed = $payload['is_listed'];
        $sortKey = $payload['sort_key'] === null ? null : self::finiteSortKey($payload['sort_key']);
        if ($listed !== $comparability->isListed()
            || ($listed && ($sortKey === null || $pricing->total() === null))
            || (! $listed && $sortKey !== null)) {
            throw new InvalidArgumentException('Canonical metric listability and sort key do not match pricing.');
        }

        return new self(
            pricing: $pricing,
            comparability: $comparability,
            listed: $listed,
            sortKey: $sortKey,
            integrity: ContractPricingIntegrity::fromArray($payload['integrity']),
        );
    }

    public function pricing(): ContractPricingViewData
    {
        return $this->pricing;
    }

    public function comparability(): ContractComparability
    {
        return $this->comparability;
    }

    public function isListed(): bool
    {
        return $this->listed;
    }

    public function sortKey(): ?float
    {
        return $this->sortKey;
    }

    public function integrity(): ContractPricingIntegrity
    {
        return $this->integrity;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'calculated_cost' => $this->pricing->toArray(),
            'comparability' => $this->comparability->value,
            'is_listed' => $this->listed,
            'sort_key' => $this->sortKey,
            'integrity' => $this->integrity->toArray(),
        ];
    }

    private static function finiteSortKey(mixed $value): float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException('A listed canonical metric requires a finite sort key.');
        }

        return (float) $value;
    }
}
