<?php

namespace App\Services\ElectricityFutures;

use RuntimeException;

/**
 * Thrown inside the `futures:fetch-eex` store transaction when its final checkpoint write finds
 * a newer owner of the date, so the run's rows and after-commit work roll back with it.
 */
final class EexCheckpointOwnershipLost extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A newer EEX run owns the checkpoint date.');
    }
}
