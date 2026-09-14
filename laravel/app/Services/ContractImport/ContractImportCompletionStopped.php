<?php

namespace App\Services\ContractImport;

use RuntimeException;

final class ContractImportCompletionStopped extends RuntimeException
{
    public readonly string $reason;

    public function __construct(string $reason)
    {
        $this->reason = in_array($reason, [
            'ownership_changed', 'date_expired', 'superseded', 'publication_missing',
            'active_set_empty', 'waiting', 'deadline_exhausted', 'checks_exhausted',
            'interrupted_execution',
        ], true) ? $reason : 'unexpected';
        parent::__construct('Contract import completion stopped.');
    }
}
