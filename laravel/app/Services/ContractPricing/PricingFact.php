<?php

namespace App\Services\ContractPricing;

/**
 * A validated optional pricing record that keeps harmless auxiliary fields intact.
 */
final readonly class PricingFact
{
    /** @param array<string, mixed> $payload */
    public function __construct(private array $payload) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }

    public function string(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function integer(string $key): ?int
    {
        $value = $this->payload[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    public function number(string $key): ?float
    {
        $value = $this->payload[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    public function boolean(string $key): ?bool
    {
        $value = $this->payload[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /** @return list<self>|null */
    public function records(string $key): ?array
    {
        $value = $this->payload[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $records = [];
        foreach ($value as $record) {
            if (! is_array($record)) {
                return null;
            }
            $records[] = new self($record);
        }

        return $records;
    }
}
