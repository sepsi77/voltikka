<?php

namespace App\Services\DTO;

class HeatPumpComparisonResult
{
    /**
     * @param  array<HeatingSystemCost>  $alternatives
     */
    public function __construct(
        public readonly float $heatingEnergyNeed,
        public readonly float $hotWaterEnergyNeed,
        public readonly float $totalEnergyNeed,
        public readonly HeatingSystemCost $currentSystem,
        public readonly array $alternatives,
    ) {
    }

    public function toArray(): array
    {
        return [
            'heatingEnergyNeed' => $this->heatingEnergyNeed,
            'hotWaterEnergyNeed' => $this->hotWaterEnergyNeed,
            'totalEnergyNeed' => $this->totalEnergyNeed,
            'currentSystem' => $this->currentSystem->toArray(),
            'alternatives' => array_map(fn (HeatingSystemCost $alt) => $alt->toArray(), $this->alternatives),
        ];
    }
}
