<?php

namespace App\Services\Caching;

use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * The shared price-cache producer lease was held by another process for the whole wait, so
 * `ContractListCacheService::refresh()` built nothing. Only this acquisition timeout is a
 * transient wait: a lock timeout inside the build (promotion or required retirement of a written
 * candidate) stays a plain LockTimeoutException, because its candidate may lack cleanup tracking.
 */
final class ContractPriceCacheProducerBusy extends LockTimeoutException {}
