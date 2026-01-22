<?php

namespace App\Services;

use App\Enums\BuildingEnergyRating;
use App\Enums\BuildingRegion;
use App\Enums\CurrentHeatingMethod;
use App\Services\DTO\HeatPumpComparisonRequest;
use App\Services\DTO\HeatPumpComparisonResult;
use App\Services\DTO\HeatingSystemCost;

class HeatPumpComparisonService
{
    // Heating energy required per cubic meter of heated space for different regions
    // Source: https://lammitysvertailu.eneuvonta.fi/
    private const HEATING_FACTORS_SOUTH = [8, 19, 32, 42, 47, 55, 56, 61, 65];
    private const HEATING_FACTORS_CENTRAL = [10, 24, 39, 51, 57, 66, 68, 74, 78];
    private const HEATING_FACTORS_NORTH = [12, 31, 49, 65, 73, 84, 86, 93, 99];

    // Water heating constants
    private const WATER_USAGE_PER_PERSON = 120; // liters/day
    private const HOT_WATER_SHARE = 0.4;
    private const WATER_HEATING_FACTOR = 58; // kWh per 1000 liters at 55°C rise

    // CO2 emissions (g/kWh)
    // Sources: Fingrid, Motiva, Energiateollisuus
    private const CO2_ELECTRICITY = 80;   // Finnish grid average 2024 (Fingrid)
    private const CO2_DISTRICT_HEATING = 130;  // Finnish 3-year average 2021-2023 (Motiva/Statistics Finland)
    private const CO2_OIL = 267;
    private const CO2_PELLETS = 30;   // Biogenic carbon neutral, only processing/transport
    private const CO2_FIREWOOD = 30;  // Biogenic carbon neutral, only processing/transport

    // Oil heating constants
    private const OIL_ENERGY_DENSITY = 10; // kWh per liter
    private const OIL_BOILER_EFFICIENCY = 0.75;

    // Pellet constants
    private const PELLET_ENERGY_DENSITY = 4700; // kWh per ton
    private const PELLET_EFFICIENCY = 0.77;

    // Heating systems configuration
    private const HEATING_SYSTEMS = [
        'ground_source_hp' => [
            'label' => 'Maalämpöpumppu',
            'spf' => 2.9,
            'default_investment' => 20000,
            'coverage' => 1.0,
            'uses_electricity' => true,
            'notes' => null,
        ],
        'air_to_water_hp' => [
            'label' => 'Ilma-vesilämpöpumppu',
            'spf' => 2.3,
            'default_investment' => 12000,
            'coverage' => 0.8,
            'uses_electricity' => true,
            'notes' => 'Tarvitsee lisälämmönlähteen kovilla pakkasilla (20% suorasähkö)',
        ],
        'air_to_air_hp' => [
            'label' => 'Ilmalämpöpumppu',
            'cop' => 2.3,
            'default_investment' => 2800,
            'coverage' => 0.5,
            'uses_electricity' => true,
            'notes' => 'Kattaa noin 50% lämmitystarpeesta, loppuosa suorasähkö',
        ],
        'exhaust_air_hp' => [
            'label' => 'Poistoilmalämpöpumppu',
            'spf' => 3.0,
            'default_investment' => 12000,
            'coverage' => 0.6,
            'uses_electricity' => true,
            'notes' => 'Soveltuu taloihin joissa koneellinen ilmanvaihto, 40% suorasähkö',
        ],
        'ilp_fireplace' => [
            'label' => 'Ilmalämpöpumppu + tulisija',
            'cop' => 2.3,
            'default_investment' => 5800,
            'coverage' => 0.5,
            'fireplace_coverage' => 0.3,
            'uses_electricity' => true,
            'uses_firewood' => true,
            'notes' => 'ILP 50%, tulisija 30%, loppuosa suorasähkö',
        ],
        'exhaust_air_hp_fireplace' => [
            'label' => 'Poistoilmalämpöpumppu + tulisija',
            'spf' => 3.0,
            'default_investment' => 15000,
            'coverage' => 0.6,
            'fireplace_coverage' => 0.25,
            'uses_electricity' => true,
            'uses_firewood' => true,
            'notes' => 'IVLP 60%, tulisija 25%, loppuosa suorasähkö',
        ],
        'pellets' => [
            'label' => 'Pellettikattila',
            'efficiency' => 0.77,
            'default_investment' => 15000,
            'coverage' => 1.0,
            'uses_electricity' => false,
            'uses_pellets' => true,
            'notes' => null,
        ],
    ];

    // Firewood price per irtokuutio
    private const FIREWOOD_PRICE = 65;
    // Firewood energy content (kWh per irtokuutio, accounting for 65% efficiency)
    private const FIREWOOD_EFFECTIVE_ENERGY = 1500 * 0.67 * 0.65;

    public function compare(HeatPumpComparisonRequest $request): HeatPumpComparisonResult
    {
        // Calculate energy needs
        $heatingEnergyNeed = $this->calculateHeatingEnergyNeed($request);
        $hotWaterEnergyNeed = $this->calculateHotWaterEnergyNeed($request->numPeople);
        $totalEnergyNeed = $heatingEnergyNeed + $hotWaterEnergyNeed;

        // Calculate current system costs
        $currentSystem = $this->calculateCurrentSystemCost($request, $totalEnergyNeed);

        // Calculate alternatives
        $alternatives = $this->calculateAlternatives($request, $totalEnergyNeed, $currentSystem->annualCost);

        return new HeatPumpComparisonResult(
            heatingEnergyNeed: $heatingEnergyNeed,
            hotWaterEnergyNeed: $hotWaterEnergyNeed,
            totalEnergyNeed: $totalEnergyNeed,
            currentSystem: $currentSystem,
            alternatives: $alternatives,
        );
    }

    public function calculateHeatingEnergyNeed(HeatPumpComparisonRequest $request): float
    {
        if ($request->inputMode === 'bill_based') {
            return $this->reverseCalculateFromBill($request);
        }

        // Model-based calculation
        $heatedVolume = $request->livingArea * $request->roomHeight;
        $ratingIndex = $request->energyRating->index();

        $heatingFactor = match ($request->region) {
            BuildingRegion::South => self::HEATING_FACTORS_SOUTH[$ratingIndex],
            BuildingRegion::Central => self::HEATING_FACTORS_CENTRAL[$ratingIndex],
            BuildingRegion::North => self::HEATING_FACTORS_NORTH[$ratingIndex],
        };

        return $heatedVolume * $heatingFactor;
    }

    public function reverseCalculateFromBill(HeatPumpComparisonRequest $request): float
    {
        return match ($request->currentHeatingMethod) {
            CurrentHeatingMethod::Oil => $this->reverseCalculateFromOil($request->oilLitersPerYear ?? 0),
            CurrentHeatingMethod::Electricity => $request->electricityKwhPerYear ?? 0,
            CurrentHeatingMethod::DistrictHeating => $this->reverseCalculateFromDistrictHeating(
                $request->districtHeatingEurosPerYear ?? 0,
                $request->districtHeatingPrice
            ),
        };
    }

    private function reverseCalculateFromOil(float $litersPerYear): float
    {
        // Oil liters × 10 kWh/l × 0.75 efficiency = useful energy
        return $litersPerYear * self::OIL_ENERGY_DENSITY * self::OIL_BOILER_EFFICIENCY;
    }

    private function reverseCalculateFromDistrictHeating(float $eurosPerYear, float $pricePerKwh): float
    {
        // Euros / (price in c/kWh / 100) = kWh
        if ($pricePerKwh <= 0) {
            return 0;
        }

        return $eurosPerYear / ($pricePerKwh / 100);
    }

    public function calculateHotWaterEnergyNeed(int $numPeople): float
    {
        // Source: https://www.motiva.fi/koti_ja_asuminen/taloyhtiot_-_yhdessa_energiatehokkaasti/vesi_ja_vedenkulutus
        // Formula: inhabitants × 120 liters/day × 0.4 (hot water share) × 365 days × 58 kWh/1000L
        return $numPeople * self::WATER_USAGE_PER_PERSON * self::HOT_WATER_SHARE * 365 * self::WATER_HEATING_FACTOR / 1000;
    }

    private function calculateCurrentSystemCost(HeatPumpComparisonRequest $request, float $totalEnergyNeed): HeatingSystemCost
    {
        $electricityCost = 0;
        $fuelCost = 0;
        $co2Kg = 0;
        $label = '';

        switch ($request->currentHeatingMethod) {
            case CurrentHeatingMethod::Electricity:
                $label = 'Suora sähkölämmitys';
                // Energy need equals electricity consumption for direct electric
                $electricityCost = $totalEnergyNeed * ($request->electricityPrice / 100);
                $co2Kg = $totalEnergyNeed * self::CO2_ELECTRICITY / 1000;
                break;

            case CurrentHeatingMethod::Oil:
                $label = 'Öljylämmitys';
                // Calculate oil needed: energy / efficiency / energy_density
                $oilLiters = $totalEnergyNeed / self::OIL_BOILER_EFFICIENCY / self::OIL_ENERGY_DENSITY;
                $fuelCost = $oilLiters * $request->oilPrice;
                // Add small electricity cost for pumps/controls (~500 kWh/year)
                $electricityCost = 500 * ($request->electricityPrice / 100);
                $co2Kg = $oilLiters * self::OIL_ENERGY_DENSITY * self::CO2_OIL / 1000;
                break;

            case CurrentHeatingMethod::DistrictHeating:
                $label = 'Kaukolämpö';
                // District heating price is already in c/kWh
                $fuelCost = $totalEnergyNeed * ($request->districtHeatingPrice / 100);
                // Small electricity cost for pumps (~200 kWh/year)
                $electricityCost = 200 * ($request->electricityPrice / 100);
                $co2Kg = $totalEnergyNeed * self::CO2_DISTRICT_HEATING / 1000;
                break;
        }

        $annualCost = $electricityCost + $fuelCost;

        return new HeatingSystemCost(
            key: 'current',
            label: $label,
            annualCost: $annualCost,
            electricityCost: $electricityCost,
            fuelCost: $fuelCost,
            investment: 0,
            annualSavings: 0,
            paybackYears: null,
            annualizedTotalCost: $annualCost,
            co2KgPerYear: $co2Kg,
            notes: null,
        );
    }

    /**
     * @return array<HeatingSystemCost>
     */
    private function calculateAlternatives(HeatPumpComparisonRequest $request, float $totalEnergyNeed, float $currentAnnualCost): array
    {
        $alternatives = [];

        foreach (self::HEATING_SYSTEMS as $key => $config) {
            $investment = $request->investments[$key] ?? $config['default_investment'];
            $electricityCost = 0;
            $fuelCost = 0;
            $co2Kg = 0;

            if (isset($config['spf'])) {
                // Heat pump with SPF
                $coverage = $config['coverage'];
                $hpElectricity = ($totalEnergyNeed * $coverage) / $config['spf'];
                $directElectricity = $totalEnergyNeed * (1 - $coverage);

                // Check for fireplace coverage
                if (isset($config['fireplace_coverage'])) {
                    $firewoodEnergy = $totalEnergyNeed * $config['fireplace_coverage'];
                    $directElectricity = $totalEnergyNeed * (1 - $coverage - $config['fireplace_coverage']);

                    // Calculate firewood cost
                    $firewoodIrtokuutio = $firewoodEnergy / self::FIREWOOD_EFFECTIVE_ENERGY;
                    $fuelCost = $firewoodIrtokuutio * self::FIREWOOD_PRICE;
                    $co2Kg += $firewoodEnergy * self::CO2_FIREWOOD / 1000;
                }

                $totalElectricity = $hpElectricity + $directElectricity;
                $electricityCost = $totalElectricity * ($request->electricityPrice / 100);
                $co2Kg += $totalElectricity * self::CO2_ELECTRICITY / 1000;
            } elseif (isset($config['cop'])) {
                // Air-to-air heat pump with COP
                $coverage = $config['coverage'];
                $hpElectricity = ($totalEnergyNeed * $coverage) / $config['cop'];
                $directElectricity = $totalEnergyNeed * (1 - $coverage);

                // Check for fireplace coverage
                if (isset($config['fireplace_coverage'])) {
                    $firewoodEnergy = $totalEnergyNeed * $config['fireplace_coverage'];
                    $directElectricity = $totalEnergyNeed * (1 - $coverage - $config['fireplace_coverage']);

                    // Calculate firewood cost
                    $firewoodIrtokuutio = $firewoodEnergy / self::FIREWOOD_EFFECTIVE_ENERGY;
                    $fuelCost = $firewoodIrtokuutio * self::FIREWOOD_PRICE;
                    $co2Kg += $firewoodEnergy * self::CO2_FIREWOOD / 1000;
                }

                $totalElectricity = $hpElectricity + $directElectricity;
                $electricityCost = $totalElectricity * ($request->electricityPrice / 100);
                $co2Kg += $totalElectricity * self::CO2_ELECTRICITY / 1000;
            } elseif (isset($config['uses_pellets']) && $config['uses_pellets']) {
                // Pellet boiler
                $pelletTons = $totalEnergyNeed / $config['efficiency'] / self::PELLET_ENERGY_DENSITY;
                $fuelCost = $pelletTons * $request->pelletPrice;
                // Small electricity for controls (~300 kWh/year)
                $electricityCost = 300 * ($request->electricityPrice / 100);
                $co2Kg = $totalEnergyNeed * self::CO2_PELLETS / 1000;
            }

            $annualCost = $electricityCost + $fuelCost;
            $annualSavings = $currentAnnualCost - $annualCost;
            $paybackYears = $this->calculatePayback($investment, $annualSavings);
            $annualizedInvestment = $this->calculateAnnuity($investment, $request->interestRate / 100, $request->calculationPeriod);
            $annualizedTotalCost = $annualCost + $annualizedInvestment;

            $alternatives[] = new HeatingSystemCost(
                key: $key,
                label: $config['label'],
                annualCost: $annualCost,
                electricityCost: $electricityCost,
                fuelCost: $fuelCost,
                investment: $investment,
                annualSavings: $annualSavings,
                paybackYears: $paybackYears,
                annualizedTotalCost: $annualizedTotalCost,
                co2KgPerYear: $co2Kg,
                notes: $config['notes'],
            );
        }

        // Sort by annualized total cost
        usort($alternatives, fn ($a, $b) => $a->annualizedTotalCost <=> $b->annualizedTotalCost);

        return $alternatives;
    }

    public function calculateAnnuity(float $investment, float $interestRate, int $years): float
    {
        if ($investment <= 0) {
            return 0;
        }

        if ($interestRate <= 0) {
            // No interest: simple division
            return $investment / $years;
        }

        // Annuity formula: P × r(1+r)^n / ((1+r)^n - 1)
        $r = $interestRate;
        $n = $years;
        $factor = pow(1 + $r, $n);

        return $investment * ($r * $factor) / ($factor - 1);
    }

    public function calculatePayback(float $investment, float $annualSavings): ?float
    {
        if ($annualSavings <= 0) {
            return null;
        }

        return $investment / $annualSavings;
    }
}
