<?php

namespace App\Services\CanonicalPricing\Exceptions;

use RuntimeException;

/**
 * Thrown when canonical interpretation JSON cannot be parsed into typed pricing data.
 * The orchestrator catches this and fails closed: the contract is excluded from
 * comparison rather than costed on unverifiable data.
 */
class CanonicalPricingParseException extends RuntimeException
{
}
