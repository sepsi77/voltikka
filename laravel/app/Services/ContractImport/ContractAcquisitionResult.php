<?php

namespace App\Services\ContractImport;

final readonly class ContractAcquisitionResult
{
    public bool $complete;

    /**
     * @param  list<array<string, mixed>>  $contracts
     * @param  list<string>  $failedPostcodes
     */
    public function __construct(
        public array $contracts,
        public array $failedPostcodes,
    ) {
        $this->complete = $failedPostcodes === [];
    }
}
