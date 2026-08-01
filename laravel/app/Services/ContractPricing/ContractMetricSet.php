<?php

namespace App\Services\ContractPricing;

use InvalidArgumentException;

final readonly class ContractMetricSet
{
    /**
     * @param  array<string, ContractMetric>  $metrics
     * @param  list<string>  $sortedIds
     * @param  list<string>  $excludedIds
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        private array $metrics,
        private array $sortedIds,
        private array $excludedIds,
        private int $consumption,
        private array $payload,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        foreach (['contracts', 'sorted_ids', 'excluded_ids', 'consumption'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new InvalidArgumentException('Contract metric set is missing required key '.$key.'.');
            }
        }
        if (! is_array($payload['contracts']) || array_is_list($payload['contracts']) && $payload['contracts'] !== []) {
            throw new InvalidArgumentException('Contract metric set contracts must be a map.');
        }
        if (! is_int($payload['consumption']) || $payload['consumption'] <= 0) {
            throw new InvalidArgumentException('Contract metric set consumption must be a positive integer.');
        }

        $metrics = [];
        foreach ($payload['contracts'] as $contractId => $row) {
            if (! is_string($contractId) || ! is_array($row)) {
                throw new InvalidArgumentException('Contract metric set contains an invalid contract row.');
            }
            $metrics[$contractId] = ContractMetric::fromArray($contractId, $row);
        }

        $sortedIds = self::idList($payload['sorted_ids'], 'sorted_ids');
        $excludedIds = self::idList($payload['excluded_ids'], 'excluded_ids');
        if (array_intersect($sortedIds, $excludedIds) !== []) {
            throw new InvalidArgumentException('Sorted and excluded contract IDs must not overlap.');
        }
        if (array_values(array_merge($sortedIds, $excludedIds)) !== array_values(array_unique(array_merge($sortedIds, $excludedIds)))) {
            throw new InvalidArgumentException('Contract metric set ID lists must not contain duplicates.');
        }

        $listedMap = array_fill_keys($sortedIds, true);
        $excludedMap = array_fill_keys($excludedIds, true);
        foreach ($metrics as $id => $metric) {
            if ($metric->isListed() && ! isset($listedMap[$id])) {
                throw new InvalidArgumentException('Listed contract metric '.$id.' is absent from sorted_ids.');
            }
            if (! $metric->isListed() && ! isset($excludedMap[$id])) {
                throw new InvalidArgumentException('Excluded contract metric '.$id.' is absent from excluded_ids.');
            }
        }
        foreach (array_merge($sortedIds, $excludedIds) as $id) {
            if (! isset($metrics[$id])) {
                throw new InvalidArgumentException('Contract metric set references unknown contract ID '.$id.'.');
            }
        }

        return new self($metrics, $sortedIds, $excludedIds, $payload['consumption'], $payload);
    }

    public function metric(string $contractId): ?ContractMetric
    {
        return $this->metrics[$contractId] ?? null;
    }

    public function hasMetric(string $contractId): bool
    {
        return isset($this->metrics[$contractId]);
    }

    /** @return array<string, ContractMetric> */
    public function metrics(): array
    {
        return $this->metrics;
    }

    /** @return list<string> */
    public function sortedIds(): array
    {
        return $this->sortedIds;
    }

    /** @return list<string> */
    public function excludedIds(): array
    {
        return $this->excludedIds;
    }

    public function consumption(): int
    {
        return $this->consumption;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }

    /** @return list<string> */
    private static function idList(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Contract metric set '.$path.' must be a list.');
        }
        foreach ($value as $id) {
            if (! is_string($id) || trim($id) === '') {
                throw new InvalidArgumentException('Contract metric set '.$path.' contains an invalid ID.');
            }
        }

        return $value;
    }
}
