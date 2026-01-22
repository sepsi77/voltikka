<?php

namespace App\Livewire;

use App\Enums\BuildingEnergyRating;
use App\Enums\BuildingRegion;
use App\Enums\CurrentHeatingMethod;
use App\Models\SpotPriceAverage;
use App\Services\DTO\HeatPumpComparisonRequest;
use App\Services\HeatPumpComparisonService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class HeatPumpCalculator extends Component
{
    // Building info
    public int $livingArea = 150;
    public float $roomHeight = 2.5;
    public string $buildingRegion = 'central';
    public string $buildingEnergyEfficiency = '2000';
    public int $numPeople = 4;

    // Input mode: 'model_based' or 'bill_based'
    public string $inputMode = 'model_based';

    // Current heating method
    public string $currentHeatingMethod = 'electricity';

    // Bill-based inputs (used when inputMode = 'bill_based')
    public ?float $oilLitersPerYear = null;
    public ?float $electricityKwhPerYear = null;
    public ?float $districtHeatingEurosPerYear = null;

    // Prices
    public float $electricityPrice = 15.0; // c/kWh including VAT and transfer
    public float $oilPrice = 1.40; // €/liter
    public float $districtHeatingPrice = 10.8; // c/kWh
    public float $pelletPrice = 480.0; // €/ton

    // Investment costs (user-adjustable)
    public array $investments = [
        'ground_source_hp' => 20000,
        'air_to_water_hp' => 12000,
        'air_to_air_hp' => 2800,
        'exhaust_air_hp' => 12000,
        'ilp_fireplace' => 5800,
        'exhaust_air_hp_fireplace' => 15000,
        'pellets' => 15000,
    ];

    // Financial parameters
    public float $interestRate = 2.0; // percent
    public int $calculationPeriod = 15; // years

    // Results (stored as array for Livewire serialization)
    public array $calculationResult = [];
    public ?string $errorMessage = null;

    // Show/hide advanced settings
    public bool $showAdvancedSettings = false;

    // Labels for UI
    public array $regionLabels = [
        'south' => 'Etelä-Suomi',
        'central' => 'Keski-Suomi',
        'north' => 'Pohjois-Suomi',
    ];

    public array $buildingAgeLabels = [
        'passive' => 'Passiivitalo',
        'low_energy' => 'Matalaenergiatalo',
        '2010' => '2010-luku tai uudempi',
        '2000' => '2000-luku',
        '1990' => '1990-luku',
        '1980' => '1980-luku',
        '1970' => '1970-luku',
        '1960' => '1960-luku',
        'older' => 'Vanhempi',
    ];

    public array $heatingMethodLabels = [
        'electricity' => 'Suora sähkölämmitys',
        'oil' => 'Öljylämmitys',
        'district_heating' => 'Kaukolämpö',
    ];

    public function mount(): void
    {
        // Prefill electricity price with average spot price from last year
        $avgSpot = SpotPriceAverage::latestRolling365Days('FI');
        if ($avgSpot && $avgSpot->avg_price_with_tax) {
            // Add estimated transfer fee (~4-6 c/kWh)
            $this->electricityPrice = round($avgSpot->avg_price_with_tax + 5, 1);
        }

        // Calculate initial results
        $this->calculate();
    }

    public function updatedInputMode(): void
    {
        // Reset bill-based values when switching modes
        if ($this->inputMode === 'model_based') {
            $this->oilLitersPerYear = null;
            $this->electricityKwhPerYear = null;
            $this->districtHeatingEurosPerYear = null;
        }
        $this->calculate();
    }

    public function updatedCurrentHeatingMethod(): void
    {
        $this->calculate();
    }

    public function updatedLivingArea(): void
    {
        $this->calculate();
    }

    public function updatedRoomHeight(): void
    {
        $this->calculate();
    }

    public function updatedBuildingRegion(): void
    {
        $this->calculate();
    }

    public function updatedBuildingEnergyEfficiency(): void
    {
        $this->calculate();
    }

    public function updatedNumPeople(): void
    {
        $this->calculate();
    }

    public function updatedOilLitersPerYear(): void
    {
        $this->calculate();
    }

    public function updatedElectricityKwhPerYear(): void
    {
        $this->calculate();
    }

    public function updatedDistrictHeatingEurosPerYear(): void
    {
        $this->calculate();
    }

    public function updatedElectricityPrice(): void
    {
        $this->calculate();
    }

    public function updatedOilPrice(): void
    {
        $this->calculate();
    }

    public function updatedDistrictHeatingPrice(): void
    {
        $this->calculate();
    }

    public function updatedPelletPrice(): void
    {
        $this->calculate();
    }

    public function updatedInterestRate(): void
    {
        $this->calculate();
    }

    public function updatedCalculationPeriod(): void
    {
        $this->calculate();
    }

    public function updateInvestment(string $key, $value): void
    {
        $this->investments[$key] = (float) $value;
        $this->calculate();
    }

    public function toggleAdvancedSettings(): void
    {
        $this->showAdvancedSettings = !$this->showAdvancedSettings;
    }

    public function calculate(): void
    {
        $this->errorMessage = null;

        try {
            // Validate inputs
            if ($this->livingArea < 20 || $this->livingArea > 500) {
                $this->errorMessage = 'Pinta-ala pitää olla välillä 20-500 m².';
                return;
            }

            if ($this->inputMode === 'bill_based') {
                if ($this->currentHeatingMethod === 'oil' && (!$this->oilLitersPerYear || $this->oilLitersPerYear <= 0)) {
                    $this->errorMessage = 'Syötä öljynkulutus litroina.';
                    return;
                }
                if ($this->currentHeatingMethod === 'electricity' && (!$this->electricityKwhPerYear || $this->electricityKwhPerYear <= 0)) {
                    $this->errorMessage = 'Syötä sähkönkulutus kWh.';
                    return;
                }
                if ($this->currentHeatingMethod === 'district_heating' && (!$this->districtHeatingEurosPerYear || $this->districtHeatingEurosPerYear <= 0)) {
                    $this->errorMessage = 'Syötä kaukolämpölasku euroina.';
                    return;
                }
            }

            $service = app(HeatPumpComparisonService::class);

            $request = new HeatPumpComparisonRequest(
                livingArea: $this->livingArea,
                roomHeight: $this->roomHeight,
                region: BuildingRegion::from($this->buildingRegion),
                energyRating: BuildingEnergyRating::from($this->buildingEnergyEfficiency),
                numPeople: $this->numPeople,
                inputMode: $this->inputMode,
                currentHeatingMethod: CurrentHeatingMethod::from($this->currentHeatingMethod),
                oilLitersPerYear: $this->oilLitersPerYear,
                electricityKwhPerYear: $this->electricityKwhPerYear,
                districtHeatingEurosPerYear: $this->districtHeatingEurosPerYear,
                electricityPrice: $this->electricityPrice,
                oilPrice: $this->oilPrice,
                districtHeatingPrice: $this->districtHeatingPrice,
                pelletPrice: $this->pelletPrice,
                investments: $this->investments,
                interestRate: $this->interestRate,
                calculationPeriod: $this->calculationPeriod,
            );

            $result = $service->compare($request);
            $this->calculationResult = $result->toArray();

            // Track successful calculation
            $this->dispatch('track',
                eventName: 'Heat Pump Calculation Completed',
                props: [
                    'current_heating' => $this->currentHeatingMethod,
                    'input_mode' => $this->inputMode,
                    'living_area' => $this->livingArea,
                    'region' => $this->buildingRegion,
                ]
            );
        } catch (\Exception $e) {
            $this->errorMessage = 'Virhe laskennassa. Yritä uudelleen.';
            $this->calculationResult = [];
        }
    }

    #[Computed]
    public function hasResults(): bool
    {
        return !empty($this->calculationResult) && isset($this->calculationResult['currentSystem']);
    }

    #[Computed]
    public function currentSystem(): array
    {
        return $this->calculationResult['currentSystem'] ?? [];
    }

    #[Computed]
    public function alternatives(): array
    {
        return $this->calculationResult['alternatives'] ?? [];
    }

    #[Computed]
    public function totalEnergyNeed(): float
    {
        return $this->calculationResult['totalEnergyNeed'] ?? 0;
    }

    #[Computed]
    public function heatingEnergyNeed(): float
    {
        return $this->calculationResult['heatingEnergyNeed'] ?? 0;
    }

    #[Computed]
    public function hotWaterEnergyNeed(): float
    {
        return $this->calculationResult['hotWaterEnergyNeed'] ?? 0;
    }

    public function getJsonLdSchema(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebApplication',
            'name' => 'Lämpöpumppulaskuri',
            'description' => 'Vertaile eri lämpöpumppuvaihtoehtoja ja laske säästöt nykyiseen lämmitysjärjestelmään verrattuna.',
            'url' => route('heatpump.calculator'),
            'applicationCategory' => 'UtilitiesApplication',
            'operatingSystem' => 'Any',
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'EUR',
            ],
        ];
    }

    public function render()
    {
        $year = date('Y');

        return view('livewire.heat-pump-calculator')
            ->layout('layouts.app', [
                'title' => "Lämpöpumppulaskuri {$year} – Vertaile lämpöpumppuja ja laske säästöt | Voltikka",
                'metaDescription' => "Ilmainen lämpöpumppulaskuri {$year}. Vertaile maalämpöä, ilmalämpöpumppua ja ilma-vesilämpöpumppua. Laske säästöt ja takaisinmaksuaika sähkö-, öljy- tai kaukolämmön vaihtamisesta lämpöpumppuun.",
                'canonical' => route('heatpump.calculator'),
                'jsonLd' => $this->getJsonLdSchema(),
            ]);
    }
}
