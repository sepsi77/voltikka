<?php

namespace App\Services\MorningFreshness;

final readonly class MorningFreshnessResult
{
    /**
     * @param  array<string, string>  $failures
     * @param  list<string>  $skippedContractIds  Active contracts left out of a ready retail run because their current interpretation failed validation
     */
    public function __construct(
        public array $failures,
        public array $skippedContractIds = [],
    ) {}

    public function ready(): bool
    {
        return $this->failures === [];
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_values($this->failures);
    }

    public function summary(): string
    {
        return implode('; ', $this->messages());
    }
}
