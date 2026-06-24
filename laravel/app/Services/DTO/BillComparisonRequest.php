<?php

namespace App\Services\DTO;

use Carbon\CarbonInterface;

/**
 * Request for the "Maksatko liikaa" bill comparison.
 *
 * The user enters what is printed on their electricity bill for one billing
 * period: the date range, the period consumption (kWh) and the total they paid
 * for the energy contract portion (excluding transmission / siirto).
 *
 * The total must be VAT-normalized to the same basis Voltikka uses for market
 * contract costs: energy only, including ALV 25.5 %. If the visitor entered a
 * pre-VAT total, the Livewire component multiplies by 1.255 before constructing
 * this request so the service can always compare like-for-like.
 */
class BillComparisonRequest
{
    public function __construct(
        public readonly CarbonInterface $startDate,
        public readonly CarbonInterface $endDate,
        public readonly float $kwh,
        public readonly float $userTotalEur,
        public readonly bool $includesHeating = false,
        public readonly ?float $annualKwhOverride = null,
    ) {
    }
}
