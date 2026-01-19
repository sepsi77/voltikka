<?php

namespace App\Services;

use App\Enums\BuildingEnergyRating;
use App\Enums\BuildingRegion;
use App\Enums\HeatingMethod;
use App\Enums\SupplementaryHeatingMethod;
use App\Services\DTO\EnergyCalculatorRequest;
use App\Services\DTO\EnergyCalculatorResult;

class EnergyCalculator
{
    // Fuel prices
    private const HEATING_OIL_PRICE = 1.25; // € per liter
    private const PELLET_PRICE = 0.6; // € per kg
    private const FIREWOOD_PRICE = 65; // € per irtokuutio

    // Cooling electricity consumption
    private const COOLING_ELECTRICITY = 240; // kWh per year

    // Water heating constants
    private const WATER_USAGE_PER_PERSON = 120; // Average daily water usage per person in liters
    private const HOT_WATER_SHARE = 0.4; // Hot water as share of total water usage

    // Heating energy constants
    private const OIL_ENERGY_DENSITY = 10; // kWh per liter
    private const FIREWOOD_ENERGY_DENSITY = 1500; // kWh per pinokuutio
    private const PINOKUUTIO_TO_IRTOKUUTIO_RATIO = 0.67;
    private const PELLET_ENERGY_DENSITY = 4.7; // kWh per kg
    private const PELLET_BURNER_EFFICIENCY = 0.85;
    private const AVERAGE_ROOM_HEIGHT = 2.6; // meters
    private const BATHROOM_FLOOR_HEATING_FACTOR = 200; // kWh per sqm per year
    private const EV_WATTAGE_PER_KM = 0.199; // kWh per km

    // Heating need per month - starting from January
    // Source: https://www.ilmatieteenlaitos.fi/lammitystarveluvut for 2021 and Jyväskylä
    private const HEATING_NEED_PER_MONTH = [754, 768, 623, 426, 202, 0, 0, 36, 277, 336, 537, 812];

    // Heating energy required per cubic meter of heated space for different regions
    // Source: https://lammitysvertailu.eneuvonta.fi/
    private const HEATING_FACTORS_SOUTH = [8, 19, 32, 42, 47, 55, 56, 61, 65];
    private const HEATING_FACTORS_CENTRAL = [10, 24, 39, 51, 57, 66, 68, 74, 78];
    private const HEATING_FACTORS_NORTH = [12, 31, 49, 65, 73, 84, 86, 93, 99];

    // Oil heating efficiency by building age
    // Source: https://lammitysvertailu.eneuvonta.fi/
    private const OIL_EFFICIENCY = [
        'modern' => 0.8,   // 2000-luku
        '1990' => 0.75,    // 1990-2000
        '1980' => 0.72,    // 1980-1990
        '1970' => 0.70,    // 1970-1980
        'older' => 0.65,   // vanhempi kuin 1970
    ];

    // Fireplace efficiency by building age
    private const FIREPLACE_EFFICIENCY = [
        'modern' => 0.8,   // New buildings
        'older' => 0.65,   // Old buildings (1980 or older)
    ];

    // Energy loss by boiler size (kWh/year)
    // Source: https://www.edilex.fi/data/rakentamismaaraykset/D5_2012.pdf page 42
    private const ENERGY_LOSS_BY_BOILER_SIZE = [
        50 => 440,
        100 => 640,
        150 => 830,
        200 => 1000,
        300 => 1300,
        500 => 1700,
        1000 => 2100,
        2000 => 3000,
        3000 => 4000,
    ];

    // Supplementary heating methods configuration
    private const SUPPLEMENTARY_HEATING_METHODS = [
        'heat_pump' => [
            'share_of_heating' => 0.4,
            'efficiency' => 2.2,
        ],
        'exhaust_air_heat_pump' => [
            'share_of_heating' => 0.6,
            'efficiency' => 3,
        ],
        'fireplace' => [
            'share_of_heating' => 0.4,
            'efficiency' => null,
        ],
    ];

    // Primary heating methods configuration
    private const HEATING_METHODS = [
        'electricity' => [
            'efficiency' => 1,
            'fixed_energy_cost' => 0,
        ],
        'air_to_water_heat_pump' => [
            'efficiency' => 2.2,
            'fixed_energy_cost' => 0,
        ],
        'ground_heat_pump' => [
            'efficiency' => 2.9,
            'fixed_energy_cost' => 0,
        ],
        'district_heating' => [
            'efficiency' => null,
            'fixed_energy_cost' => 700,
        ],
        'oil' => [
            'efficiency' => null,
            'fixed_energy_cost' => 300,
        ],
        'fireplace' => [
            'efficiency' => null,
            'fixed_energy_cost' => 0,
        ],
        'pellets' => [
            'efficiency' => null,
            'fixed_energy_cost' => 0,
        ],
        'other' => [
            'efficiency' => null,
            'fixed_energy_cost' => 0,
        ],
    ];

    public function estimate(EnergyCalculatorRequest $request): EnergyCalculatorResult
    {
        // Initialize variables
        $primaryHeatingEnergyUsage = 0;
        $primaryHeatingElectricityNeed = 0;
        $supplementaryHeatingEnergyUsage = 0;
        $supplementaryHeatingElectricityNeed = 0;
        $heatingElectricityNeed = 0;
        $waterHeatingEnergyUsage = 0;
        $waterHeatingElectricityNeed = 0;
        $bathroomUnderfloorHeatingElectricityNeed = 0;
        $saunaElectricityNeed = 0;
        $evChargingElectricityNeed = 0;
        $basicElectricityNeed = 0;
        $coolingElectricityNeed = 0;

        // Fuel consumption
        $heatingOilNeeded = 0;
        $firewoodNeeded = 0;
        $pelletsNeeded = 0;

        $heatingElectricityUseByMonth = null;

        // Calculate heating electricity usage
        if (!$request->externalHeating && $request->heatingMethod !== null) {
            $heatingMethodKey = $request->heatingMethod->value;

            // Calculate total energy needed for heating
            $baseHeatingEnergyNeed = $this->calculateBuildingHeatingEnergyNeed(
                $request->livingArea,
                $request->buildingEnergyEfficiency,
                $request->buildingRegion
            );

            $primaryHeatingEnergyUsage = $baseHeatingEnergyNeed;

            // Calculate supplementary heating contribution
            if ($request->supplementaryHeating !== null) {
                $suppKey = $request->supplementaryHeating->value;
                $suppConfig = self::SUPPLEMENTARY_HEATING_METHODS[$suppKey];

                $supplementaryHeatingEnergyUsage = $baseHeatingEnergyNeed * $suppConfig['share_of_heating'];
                $primaryHeatingEnergyUsage = $baseHeatingEnergyNeed - $supplementaryHeatingEnergyUsage;

                if ($suppConfig['efficiency'] !== null) {
                    $supplementaryHeatingElectricityNeed = $supplementaryHeatingEnergyUsage / $suppConfig['efficiency'];
                }
            }

            // Calculate primary heating electricity usage
            $heatingConfig = self::HEATING_METHODS[$heatingMethodKey];
            $efficiency = $heatingConfig['efficiency'];

            if ($efficiency !== null) {
                $primaryHeatingElectricityNeed = $primaryHeatingEnergyUsage / $efficiency;
            }

            $heatingElectricityNeed = $primaryHeatingElectricityNeed
                + $heatingConfig['fixed_energy_cost']
                + $supplementaryHeatingElectricityNeed;

            // Calculate fuel consumption for non-electric heating
            if ($request->heatingMethod === HeatingMethod::Oil) {
                $heatingOilNeeded = $this->calculateOilConsumption($primaryHeatingEnergyUsage, $request->buildingEnergyEfficiency);
            } elseif ($request->heatingMethod === HeatingMethod::Fireplace) {
                $firewoodNeeded = $this->calculateFirewoodNeed($primaryHeatingEnergyUsage, $request->buildingEnergyEfficiency);
            } elseif ($request->heatingMethod === HeatingMethod::Pellets) {
                $pelletsNeeded = $this->calculatePelletsNeed($primaryHeatingEnergyUsage);
            }

            // Add firewood for supplementary fireplace heating
            if ($request->supplementaryHeating === SupplementaryHeatingMethod::Fireplace) {
                $firewoodNeeded += $this->calculateFirewoodNeed($supplementaryHeatingEnergyUsage, $request->buildingEnergyEfficiency);
            }
        }

        // Calculate water heating energy usage
        if (!$request->externalHeatingWater && $request->heatingMethod !== null) {
            if ($request->heatingMethod === HeatingMethod::DistrictHeating) {
                $waterHeatingElectricityNeed = 0;
            } else {
                $waterHeatingEnergyUsage = $this->calculateWaterHeatingEnergyUsage($request->numPeople);

                if ($request->heatingMethod === HeatingMethod::Oil) {
                    // Water heated with oil
                    $heatingOilNeeded += $this->calculateOilConsumption($waterHeatingEnergyUsage, $request->buildingEnergyEfficiency);
                    $waterHeatingElectricityNeed = 0;
                } elseif (in_array($request->heatingMethod, [HeatingMethod::AirToWaterHeatPump, HeatingMethod::GroundHeatPump])) {
                    // Water heated with heat pump
                    $heatingConfig = self::HEATING_METHODS[$request->heatingMethod->value];
                    $waterHeatingElectricityNeed = (int) round($waterHeatingEnergyUsage / $heatingConfig['efficiency']);
                } else {
                    // Direct electric water heating
                    $waterHeatingElectricityNeed = (int) round($waterHeatingEnergyUsage);
                }
            }
        }

        // Calculate other electricity needs
        if ($request->bathroomHeatingArea > 0) {
            $bathroomUnderfloorHeatingElectricityNeed = $this->calculateBathroomUnderfloorHeatingEnergyUsage($request->bathroomHeatingArea);
        }

        if ($request->saunaIsAlwaysOnType || $request->saunaUsagePerWeek > 0) {
            $saunaElectricityNeed = $this->calculateSaunaElectricityNeed($request->saunaUsagePerWeek, $request->saunaIsAlwaysOnType);
        }

        if ($request->electricVehicleKmsPerMonth > 0) {
            $evChargingElectricityNeed = $this->calculateElectricVehicleChargingNeed($request->electricVehicleKmsPerMonth);
        }

        if ($request->cooling) {
            $coolingElectricityNeed = self::COOLING_ELECTRICITY;
        }

        $basicElectricityNeed = $this->calculateBasicElectricityUsage($request->numPeople, $request->livingArea);

        $heatingTotal = (int) round($heatingElectricityNeed + $bathroomUnderfloorHeatingElectricityNeed + $waterHeatingElectricityNeed);

        // Note: Python code has a bug - EV charging is added twice
        $totalElectricityNeed = $heatingTotal
            + $saunaElectricityNeed
            + $evChargingElectricityNeed
            + $evChargingElectricityNeed  // Matches Python bug: added twice
            + $basicElectricityNeed
            + $coolingElectricityNeed;

        // Calculate monthly heating distribution if applicable
        if (!$request->externalHeating
            && $request->heatingMethod !== null
            && $heatingElectricityNeed > self::HEATING_METHODS[$request->heatingMethod->value]['fixed_energy_cost']
        ) {
            $heatingElectricityUseByMonth = $this->calculateHeatingUseByMonth($heatingElectricityNeed);
        }

        // Build result
        return new EnergyCalculatorResult(
            total: (int) $totalElectricityNeed,
            basicLiving: $basicElectricityNeed,
            heatingTotal: !$request->externalHeating ? $heatingTotal : null,
            roomHeating: !$request->externalHeating ? (int) round($heatingElectricityNeed) : null,
            bathroomUnderfloorHeating: $request->bathroomHeatingArea > 0 ? $bathroomUnderfloorHeatingElectricityNeed : null,
            water: !$request->externalHeatingWater ? $waterHeatingElectricityNeed : null,
            sauna: ($request->saunaIsAlwaysOnType || $request->saunaUsagePerWeek > 0) ? $saunaElectricityNeed : null,
            electricityVehicle: $request->electricVehicleKmsPerMonth > 0 ? $evChargingElectricityNeed : null,
            cooling: $request->cooling ? $coolingElectricityNeed : null,
            firewoodConsumption: $firewoodNeeded > 0 ? $firewoodNeeded : null,
            firewoodCost: $firewoodNeeded > 0 ? $firewoodNeeded * self::FIREWOOD_PRICE : null,
            heatingOilConsumption: $heatingOilNeeded > 0 ? $heatingOilNeeded : null,
            heatingOilCost: $heatingOilNeeded > 0 ? $heatingOilNeeded * self::HEATING_OIL_PRICE : null,
            pelletConsumption: $pelletsNeeded > 0 ? $pelletsNeeded : null,
            pelletCost: $pelletsNeeded > 0 ? $pelletsNeeded * self::PELLET_PRICE : null,
            heatingElectricityUseByMonth: $heatingElectricityUseByMonth,
        );
    }

    public function calculateBasicElectricityUsage(int $numPeople, int $floorArea): int
    {
        $peopleElectricityUsage = $numPeople * 400;
        $floorElectricityUsage = $floorArea * 30;

        return $peopleElectricityUsage + $floorElectricityUsage;
    }

    public function calculateSaunaElectricityNeed(int $usagePerWeek, bool $alwaysOnType = false): int
    {
        if ($alwaysOnType) {
            // Always on type uses 2000-3200 kWh/year on average
            // Source: https://www.turkuenergia.fi/kotitaloudet/energiansaastovinkit/kodin-laitteiden-sahkonkulutus/
            return 2750;
        }

        // Regular sauna: 5-10 kWh per session, average 7.5
        return (int) ($usagePerWeek * 7.5 * 52);
    }

    public function calculateElectricVehicleChargingNeed(int $kmPerMonth): int
    {
        // Source: https://ev-database.org/cheatsheet/energy-consumption-electric-car
        // Average: 0.199 kWh per km
        return (int) round($kmPerMonth * self::EV_WATTAGE_PER_KM * 12);
    }

    public function calculateBuildingHeatingEnergyNeed(
        int $livingSpace,
        BuildingEnergyRating $energyRating,
        BuildingRegion $region
    ): float {
        $heatingArea = $livingSpace * self::AVERAGE_ROOM_HEIGHT;
        $ratingIndex = $energyRating->index();

        $heatingFactor = match ($region) {
            BuildingRegion::South => self::HEATING_FACTORS_SOUTH[$ratingIndex],
            BuildingRegion::Central => self::HEATING_FACTORS_CENTRAL[$ratingIndex],
            BuildingRegion::North => self::HEATING_FACTORS_NORTH[$ratingIndex],
        };

        return $heatingArea * $heatingFactor;
    }

    public function calculateBathroomUnderfloorHeatingEnergyUsage(int $bathroomHeatingArea): int
    {
        return $bathroomHeatingArea * self::BATHROOM_FLOOR_HEATING_FACTOR;
    }

    public function calculateWaterHeatingEnergyUsage(int $numInhabitants): float
    {
        // Formula: inhabitants * 120 * 0.4 * 365 * 58 / 1000
        // Source: https://www.motiva.fi/julkinen_sektori/kiinteiston_energiankaytto/kulutuksen_normitus/laskukaavat_lammin_kayttovesi
        // Source: https://www.motiva.fi/koti_ja_asuminen/taloyhtiot_-_yhdessa_energiatehokkaasti/vesi_ja_vedenkulutus
        $waterHeatingEnergyNeed = $numInhabitants * self::WATER_USAGE_PER_PERSON * self::HOT_WATER_SHARE * 365 * 58 / 1000;
        $boilerEnergyLoss = $this->calculateBoilerEnergyLoss($numInhabitants);

        return $waterHeatingEnergyNeed + $boilerEnergyLoss;
    }

    private function calculateBoilerEnergyLoss(int $numInhabitants): int
    {
        // Source: https://www.edilex.fi/data/rakentamismaaraykset/D5_2012.pdf page 42
        $boilerSize = $this->calculateBoilerSize($numInhabitants);

        $energyLoss = 0;
        foreach (self::ENERGY_LOSS_BY_BOILER_SIZE as $size => $loss) {
            if ($size > $boilerSize) {
                $energyLoss = $loss;
                break;
            }
        }

        return $energyLoss;
    }

    private function calculateBoilerSize(int $numInhabitants): float
    {
        $dailyHotWaterUsage = $numInhabitants * self::WATER_USAGE_PER_PERSON * self::HOT_WATER_SHARE;
        $margin = 1.2;

        return $dailyHotWaterUsage * $margin;
    }

    public function calculateOilConsumption(float $baseHeatingEnergyNeed, BuildingEnergyRating $energyRating): float
    {
        $efficiency = match ($energyRating) {
            BuildingEnergyRating::Passive,
            BuildingEnergyRating::LowEnergy,
            BuildingEnergyRating::Year2010,
            BuildingEnergyRating::Year2000 => self::OIL_EFFICIENCY['modern'],
            BuildingEnergyRating::Year1990 => self::OIL_EFFICIENCY['1990'],
            BuildingEnergyRating::Year1980 => self::OIL_EFFICIENCY['1980'],
            BuildingEnergyRating::Year1970 => self::OIL_EFFICIENCY['1970'],
            default => self::OIL_EFFICIENCY['older'],
        };

        $oilEnergyRequirements = $baseHeatingEnergyNeed / $efficiency;

        return $oilEnergyRequirements / self::OIL_ENERGY_DENSITY;
    }

    public function calculateFirewoodNeed(float $baseHeatingEnergyNeed, BuildingEnergyRating $energyRating): float
    {
        // Source: https://www.motiva.fi/ratkaisut/uusiutuva_energia/bioenergia/puulammitys_kiinteistoissa/kodin_tulisijat
        $efficiency = match ($energyRating) {
            BuildingEnergyRating::Passive,
            BuildingEnergyRating::LowEnergy,
            BuildingEnergyRating::Year2010,
            BuildingEnergyRating::Year2000,
            BuildingEnergyRating::Year1990 => self::FIREPLACE_EFFICIENCY['modern'],
            default => self::FIREPLACE_EFFICIENCY['older'],
        };

        $woodEnergyRequirements = $baseHeatingEnergyNeed / $efficiency;

        return $woodEnergyRequirements / self::FIREWOOD_ENERGY_DENSITY / self::PINOKUUTIO_TO_IRTOKUUTIO_RATIO;
    }

    public function calculatePelletsNeed(float $baseHeatingEnergyNeed): float
    {
        // Source: https://www.motiva.fi/ratkaisut/uusiutuva_energia/bioenergia/puulammitys_kiinteistoissa/kodin_tulisijat
        return $baseHeatingEnergyNeed / self::PELLET_BURNER_EFFICIENCY / self::PELLET_ENERGY_DENSITY;
    }

    public function calculateHeatingUseByMonth(float $roomHeating): array
    {
        $totalHeatingNeed = array_sum(self::HEATING_NEED_PER_MONTH);

        return array_map(
            fn ($monthNeed) => $monthNeed * $roomHeating / $totalHeatingNeed,
            self::HEATING_NEED_PER_MONTH
        );
    }
}
