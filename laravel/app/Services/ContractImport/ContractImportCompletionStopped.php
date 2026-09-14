<?php

namespace App\Services\ContractImport;

use RuntimeException;

final class ContractImportCompletionStopped extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Contract import completion stopped.');
    }
}
