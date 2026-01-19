<?php

namespace App\Services\DTO;

use App\Enums\BuildingEnergyRating;
use App\Enums\BuildingRegion;
use App\Enums\BuildingType;
use App\Enums\HeatingMethod;
use App\Enums\SupplementaryHeatingMethod;

class EnergyCalculatorRequest
{
    public function __construct(
        public readonly int $livingArea,
        public readonly int $numPeople,
        public readonly BuildingType $buildingType = BuildingType::Apartment,
        public readonly ?HeatingMethod $heatingMethod = null,
        public readonly ?SupplementaryHeatingMethod $supplementaryHeating = null,
        public readonly ?BuildingEnergyRating $buildingEnergyEfficiency = null,
        public readonly ?BuildingRegion $buildingRegion = null,
        public readonly int $electricVehicleKmsPerMonth = 0,
        public readonly int $bathroomHeatingArea = 0,
        public readonly int $saunaUsagePerWeek = 0,
        public readonly bool $saunaIsAlwaysOnType = false,
        public readonly bool $externalHeating = true,
        public readonly bool $externalHeatingWater = true,
        public readonly bool $cooling = false,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $buildingTypeValue = $data['building_type'] ?? $data['buildingType'] ?? null;
        $heatingMethodValue = $data['heating_method'] ?? $data['heatingMethod'] ?? null;
        $supplementaryHeatingValue = $data['supplementary_heating'] ?? $data['supplementaryHeating'] ?? null;
        $buildingEnergyEfficiencyValue = $data['building_energy_efficiency'] ?? $data['buildingEnergyEfficiency'] ?? null;
        $buildingRegionValue = $data['building_region'] ?? $data['buildingRegion'] ?? null;

        return new self(
            livingArea: $data['living_area'] ?? $data['livingArea'],
            numPeople: $data['num_people'] ?? $data['numPeople'],
            buildingType: $buildingTypeValue !== null
                ? (is_string($buildingTypeValue) ? BuildingType::from($buildingTypeValue) : $buildingTypeValue)
                : BuildingType::Apartment,
            heatingMethod: $heatingMethodValue !== null
                ? (is_string($heatingMethodValue) ? HeatingMethod::from($heatingMethodValue) : $heatingMethodValue)
                : null,
            supplementaryHeating: $supplementaryHeatingValue !== null
                ? (is_string($supplementaryHeatingValue) ? SupplementaryHeatingMethod::from($supplementaryHeatingValue) : $supplementaryHeatingValue)
                : null,
            buildingEnergyEfficiency: $buildingEnergyEfficiencyValue !== null
                ? (is_string($buildingEnergyEfficiencyValue) ? BuildingEnergyRating::from($buildingEnergyEfficiencyValue) : $buildingEnergyEfficiencyValue)
                : null,
            buildingRegion: $buildingRegionValue !== null
                ? (is_string($buildingRegionValue) ? BuildingRegion::from($buildingRegionValue) : $buildingRegionValue)
                : null,
            electricVehicleKmsPerMonth: $data['electric_vehicle_kms_per_month'] ?? $data['electricVehicleKmsPerMonth'] ?? 0,
            bathroomHeatingArea: $data['bathroom_heating_area'] ?? $data['bathroomHeatingArea'] ?? 0,
            saunaUsagePerWeek: $data['sauna_usage_per_week'] ?? $data['saunaUsagePerWeek'] ?? 0,
            saunaIsAlwaysOnType: $data['sauna_is_always_on_type'] ?? $data['saunaIsAlwaysOnType'] ?? false,
            externalHeating: $data['external_heating'] ?? $data['externalHeating'] ?? true,
            externalHeatingWater: $data['external_heating_water'] ?? $data['externalHeatingWater'] ?? true,
            cooling: $data['cooling'] ?? false,
        );
    }
}
