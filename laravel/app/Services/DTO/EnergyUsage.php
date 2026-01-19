<?php

namespace App\Services\DTO;

class EnergyUsage
{
    public function __construct(
        public readonly int $total,
        public readonly int $basicLiving = 0,
        public readonly ?int $roomHeating = null,
        public readonly ?int $bathroomUnderfloorHeating = null,
        public readonly ?int $water = null,
        public readonly ?int $sauna = null,
        public readonly ?int $electricityVehicle = null,
        public readonly ?float $cooling = null,
        public readonly ?array $heatingElectricityUseByMonth = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            total: $data['total'] ?? 0,
            basicLiving: $data['basic_living'] ?? $data['basicLiving'] ?? 0,
            roomHeating: $data['room_heating'] ?? $data['roomHeating'] ?? null,
            bathroomUnderfloorHeating: $data['bathroom_underfloor_heating'] ?? $data['bathroomUnderfloorHeating'] ?? null,
            water: $data['water'] ?? null,
            sauna: $data['sauna'] ?? null,
            electricityVehicle: $data['electricity_vehicle'] ?? $data['electricityVehicle'] ?? null,
            cooling: $data['cooling'] ?? null,
            heatingElectricityUseByMonth: $data['heating_electricity_use_by_month'] ?? $data['heatingElectricityUseByMonth'] ?? null,
        );
    }
}
