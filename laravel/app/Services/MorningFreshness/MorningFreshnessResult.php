<?php

namespace App\Services\MorningFreshness;

final readonly class MorningFreshnessResult
{
    /** @param array<string, string> $failures */
    public function __construct(public array $failures) {}

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
