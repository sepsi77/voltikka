<?php

namespace App\Services\DTO;

class EnergyCalculatorResult
{
    public function __construct(
        public readonly int $total,
        public readonly int $basicLiving,
        public readonly ?int $heatingTotal = null,
        public readonly ?int $roomHeating = null,
        public readonly ?int $bathroomUnderfloorHeating = null,
        public readonly ?int $water = null,
        public readonly ?int $sauna = null,
        public readonly ?int $electricityVehicle = null,
        public readonly ?float $cooling = null,
        public readonly ?float $firewoodConsumption = null,
        public readonly ?float $firewoodCost = null,
        public readonly ?float $heatingOilConsumption = null,
        public readonly ?float $heatingOilCost = null,
        public readonly ?float $pelletConsumption = null,
        public readonly ?float $pelletCost = null,
        public readonly ?array $heatingElectricityUseByMonth = null,
    ) {
    }

    public function toArray(): array
    {
        $result = [
            'total' => $this->total,
            'basic_living' => $this->basicLiving,
        ];

        if ($this->heatingTotal !== null) {
            $result['heating_total'] = $this->heatingTotal;
        }
        if ($this->roomHeating !== null) {
            $result['room_heating'] = $this->roomHeating;
        }
        if ($this->bathroomUnderfloorHeating !== null) {
            $result['bathroom_underfloor_heating'] = $this->bathroomUnderfloorHeating;
        }
        if ($this->water !== null) {
            $result['water'] = $this->water;
        }
        if ($this->sauna !== null) {
            $result['sauna'] = $this->sauna;
        }
        if ($this->electricityVehicle !== null) {
            $result['electricity_vehicle'] = $this->electricityVehicle;
        }
        if ($this->cooling !== null) {
            $result['cooling'] = $this->cooling;
        }
        if ($this->firewoodConsumption !== null) {
            $result['firewood_consumption'] = $this->firewoodConsumption;
        }
        if ($this->firewoodCost !== null) {
            $result['firewood_cost'] = $this->firewoodCost;
        }
        if ($this->heatingOilConsumption !== null) {
            $result['heating_oil_consumption'] = $this->heatingOilConsumption;
        }
        if ($this->heatingOilCost !== null) {
            $result['heating_oil_cost'] = $this->heatingOilCost;
        }
        if ($this->pelletConsumption !== null) {
            $result['pellet_consumption'] = $this->pelletConsumption;
        }
        if ($this->pelletCost !== null) {
            $result['pellet_cost'] = $this->pelletCost;
        }
        if ($this->heatingElectricityUseByMonth !== null) {
            $result['heating_electricity_use_by_month'] = $this->heatingElectricityUseByMonth;
        }

        return $result;
    }

    public function toEnergyUsage(): EnergyUsage
    {
        return new EnergyUsage(
            total: $this->total,
            basicLiving: $this->basicLiving,
            roomHeating: $this->roomHeating,
            bathroomUnderfloorHeating: $this->bathroomUnderfloorHeating,
            water: $this->water,
            sauna: $this->sauna,
            electricityVehicle: $this->electricityVehicle,
            cooling: $this->cooling,
            heatingElectricityUseByMonth: $this->heatingElectricityUseByMonth,
        );
    }
}
