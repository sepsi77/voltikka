<?php

namespace App\Services\ContractCard\DTO;

/**
 * One itemised price row in the card's receipt block.
 *
 * `soft` marks a row whose value is estimated rather than contractual, so the breakdown
 * itself shows which parts of the price are known. The card renders soft rows in a lighter
 * weight and colour; it must not be the only signal, which is why an estimated total also
 * carries the Arvio chip.
 */
readonly class CardReceiptLine
{
    public function __construct(
        public string $label,
        public string $value,
        public ?string $unit = null,
        public bool $soft = false,
    ) {
    }
}
