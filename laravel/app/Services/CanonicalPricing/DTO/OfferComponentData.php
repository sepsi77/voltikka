<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;

/**
 * One exact billed component change inside a canonical offer term.
 */
readonly class OfferComponentData
{
    public function __construct(
        public ComponentType $type,
        public ComponentUnit $unit,
        public float $amount,
        public float $normalAmount,
    ) {}

    /** @return array{component_type:string,unit:string,amount:float,normal_amount:float} */
    public function toArray(): array
    {
        return [
            'component_type' => $this->type->value,
            'unit' => $this->unit->value,
            'amount' => $this->amount,
            'normal_amount' => $this->normalAmount,
        ];
    }
}
