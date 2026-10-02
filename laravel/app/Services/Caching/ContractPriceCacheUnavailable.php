<?php

namespace App\Services\Caching;

use RuntimeException;

final class ContractPriceCacheUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Verified contract price cache is unavailable.');
    }
}
