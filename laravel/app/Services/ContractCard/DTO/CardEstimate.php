<?php

namespace App\Services\ContractCard\DTO;

/**
 * The explanation behind an estimated 12-month total, shown in the Arvio popover.
 *
 * Every sentence is generated from typed fields (estimate method, spot averages, reset
 * payload, term length). The interpretation `summary` string is never rendered, exactly as
 * in `CanonicalPricing\MarketReset\ResetEstimateCopy`.
 */
readonly class CardEstimate
{
    public function __construct(
        public string $heading,
        public string $body,
        public string $linkUrl = 'https://voltikka.fi/tietoa#menetelma',
        public string $linkText = 'Näin laskemme arviot',
    ) {
    }
}
