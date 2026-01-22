<?php

namespace App\Services\DTO;

use App\Enums\BuildingEnergyRating;
use App\Enums\BuildingRegion;
use App\Enums\CurrentHeatingMethod;

class HeatPumpComparisonRequest
{
    public function __construct(
        // Building info
        public readonly int $livingArea,
        public readonly float $roomHeight,
        public readonly BuildingRegion $region,
        public readonly BuildingEnergyRating $energyRating,
        public readonly int $numPeople,

        // Input mode: 'model_based' or 'bill_based'
        public readonly string $inputMode,

        // Current heating
        public readonly CurrentHeatingMethod $currentHeatingMethod,

        // Bill-based consumption values (used when inputMode = 'bill_based')
        public readonly ?float $oilLitersPerYear = null,
        public readonly ?float $electricityKwhPerYear = null,
        public readonly ?float $districtHeatingEurosPerYear = null,

        // Prices
        public readonly float $electricityPrice = 15.0, // c/kWh including VAT and transfer
        public readonly float $oilPrice = 1.40, // €/liter
        public readonly float $districtHeatingPrice = 10.8, // c/kWh
        public readonly float $pelletPrice = 480.0, // €/ton

        // Investment costs (can be overridden by user)
        public readonly array $investments = [],

        // Financial parameters
        public readonly float $interestRate = 2.0, // percent
        public readonly int $calculationPeriod = 15, // years
    ) {
    }
}
