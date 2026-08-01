<?php

namespace App\Services\ContractPricing;

use App\Services\CanonicalPricing\DTO\ContractPricingIntegrity;
use InvalidArgumentException;

final readonly class ContractMetric
{
    private function __construct(
        private string $contractId,
        private ContractPricingViewData $pricing,
        private ?float $emissionFactor,
        private bool $exceedsConsumptionLimit,
        private ?string $comparability,
        private bool $listed,
        private ?float $sortKey,
        private ?ContractPricingIntegrity $integrity,
        private array $payload,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(string $contractId, array $payload): self
    {
        if (trim($contractId) === '') {
            throw new InvalidArgumentException('Contract metric ID must not be empty.');
        }

        foreach (['calculated_cost', 'emission_factor', 'exceeds_consumption_limit', 'total_cost', 'comparability', 'is_listed', 'sort_key', 'pricing_integrity'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new InvalidArgumentException('Contract metric '.$contractId.' is missing required key '.$key.'.');
            }
        }

        if (! is_array($payload['calculated_cost'])) {
            throw new InvalidArgumentException('Contract metric calculated_cost must be an array.');
        }
        $pricing = ContractPricingViewData::fromArray($payload['calculated_cost']);
        $emission = self::nullableFiniteNumber($payload['emission_factor'], 'emission_factor');
        if (! is_bool($payload['exceeds_consumption_limit'])) {
            throw new InvalidArgumentException('Contract metric exceeds_consumption_limit must be a boolean.');
        }
        $serializedTotal = $payload['total_cost'];
        self::nullableFiniteNumber($serializedTotal, 'total_cost');
        if ($payload['comparability'] !== null && (! is_string($payload['comparability']) || trim($payload['comparability']) === '')) {
            throw new InvalidArgumentException('Contract metric comparability must be a non-empty string or null.');
        }
        if (! is_bool($payload['is_listed'])) {
            throw new InvalidArgumentException('Contract metric is_listed must be a boolean.');
        }
        $sortKey = self::nullableFiniteNumber($payload['sort_key'], 'sort_key');
        if ($payload['pricing_integrity'] !== null && ! is_array($payload['pricing_integrity'])) {
            throw new InvalidArgumentException('Contract metric pricing_integrity must be an array or null.');
        }

        $listed = $payload['is_listed'];
        if ($listed && ($pricing->total() === null || $sortKey === null)) {
            throw new InvalidArgumentException('A listed contract metric requires finite pricing total and sort key.');
        }
        if (! $listed && $sortKey !== null) {
            throw new InvalidArgumentException('An excluded contract metric requires a null sort key.');
        }

        $pricingComparability = $pricing->comparability();
        if ($pricing->pricingBasis() === 'canonical') {
            if ($pricingComparability?->value !== $payload['comparability']) {
                throw new InvalidArgumentException('Contract metric comparability does not match calculated_cost.');
            }
            if ($pricingComparability->isListed() !== $listed) {
                throw new InvalidArgumentException('Contract metric listability does not match calculated_cost.');
            }
        }

        return new self(
            contractId: $contractId,
            pricing: $pricing,
            emissionFactor: $emission,
            exceedsConsumptionLimit: $payload['exceeds_consumption_limit'],
            comparability: $payload['comparability'],
            listed: $listed,
            sortKey: $sortKey,
            integrity: $payload['pricing_integrity'] === null
                ? null
                : ContractPricingIntegrity::fromArray($payload['pricing_integrity']),
            payload: $payload,
        );
    }

    public function contractId(): string
    {
        return $this->contractId;
    }

    public function pricing(): ContractPricingViewData
    {
        return $this->pricing;
    }

    public function emissionFactor(): ?float
    {
        return $this->emissionFactor;
    }

    public function exceedsConsumptionLimit(): bool
    {
        return $this->exceedsConsumptionLimit;
    }

    public function comparability(): ?string
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

    public function integrity(): ?ContractPricingIntegrity
    {
        return $this->integrity;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }

    private static function nullableFiniteNumber(mixed $value, string $path): ?float
    {
        if ($value === null) {
            return null;
        }
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException('Contract metric '.$path.' must be a finite number or null.');
        }

        return (float) $value;
    }
}
