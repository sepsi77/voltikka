<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\Enums\BoundaryKind;

/**
 * A resolved-or-not phase boundary from canonical pricing (schema-v4 `$defs.boundary`).
 * The raw `value` interpretation depends on `kind`:
 *  - date          → ISO date string
 *  - after_months  → integer month count as a string
 *  - others        → null / ignored
 */
readonly class PhaseBoundary
{
    public function __construct(
        public BoundaryKind $kind,
        public ?string $value,
    ) {}

    public function afterMonths(): ?int
    {
        if ($this->kind !== BoundaryKind::AfterMonths || $this->value === null) {
            return null;
        }

        if (! is_numeric($this->value)) {
            return null;
        }

        return (int) $this->value;
    }
}
