<?php

namespace App\Livewire;

use App\Enums\BuildingEnergyRating;
use App\Enums\BuildingRegion;
use App\Enums\BuildingType;
use App\Enums\HeatingMethod;
use App\Enums\SupplementaryHeatingMethod;
use App\Services\DTO\EnergyCalculatorRequest;
use App\Services\DTO\EnergyCalculatorResult;
use App\Services\EnergyCalculator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class ConsumptionCalculator extends Component
{
    // Tab state
    public string $activeTab = 'presets';

    // Basic form fields
    public int $livingArea = 80;
    public int $numPeople = 2;
    public string $buildingType = 'apartment';

    // Heating settings
    public bool $includeHeating = false;
    public string $heatingMethod = 'electricity';
    public string $buildingRegion = 'central';
    public string $buildingEnergyEfficiency = '2000';
    public ?string $supplementaryHeating = null;

    // Extras
    public int $bathroomHeatingArea = 0;
    public int $saunaUsagePerWeek = 0;
    public bool $saunaIsAlwaysOnType = false;
    public int $electricVehicleKmsPerMonth = 0;
    public bool $cooling = false;

    // Results (stored as array for Livewire serialization)
    public array $calculationResult = [];

    // Presets for quick selection
    #[Url]
    public ?string $selectedPreset = null;

    public array $presets = [
        'small_apartment' => [
            'label' => 'Pieni yksiö',
            'description' => '1 hlö, 35 m²',
            'icon' => 'apartment',
            'consumption' => 2000,
            'config' => [
                'livingArea' => 35,
                'numPeople' => 1,
                'buildingType' => 'apartment',
                'includeHeating' => false,
            ],
        ],
        'medium_apartment' => [
            'label' => 'Kerrostalo 2 hlö',
            'description' => '2 hlö, 60 m²',
            'icon' => 'apartment',
            'consumption' => 3500,
            'config' => [
                'livingArea' => 60,
                'numPeople' => 2,
                'buildingType' => 'apartment',
                'includeHeating' => false,
            ],
        ],
        'large_apartment' => [
            'label' => 'Kerrostalo perhe',
            'description' => '4 hlö, 80 m²',
            'icon' => 'apartment',
            'consumption' => 5000,
            'config' => [
                'livingArea' => 80,
                'numPeople' => 4,
                'buildingType' => 'apartment',
                'includeHeating' => false,
            ],
        ],
        'small_house_no_heat' => [
            'label' => 'Pieni omakotitalo',
            'description' => 'Ei sähkölämmitystä',
            'icon' => 'house',
            'consumption' => 5000,
            'config' => [
                'livingArea' => 100,
                'numPeople' => 3,
                'buildingType' => 'detached_house',
                'includeHeating' => false,
            ],
        ],
        'medium_house_heat_pump' => [
            'label' => 'Omakotitalo + ILP',
            'description' => 'Ilma-vesilämpöpumppu',
            'icon' => 'house',
            'consumption' => 8000,
            'config' => [
                'livingArea' => 120,
                'numPeople' => 4,
                'buildingType' => 'detached_house',
                'includeHeating' => true,
                'heatingMethod' => 'air_to_water_heat_pump',
                'buildingRegion' => 'central',
                'buildingEnergyEfficiency' => '2000',
            ],
        ],
        'large_house_electric' => [
            'label' => 'Suuri talo + sähkö',
            'description' => 'Suora sähkölämmitys',
            'icon' => 'house',
            'consumption' => 18000,
            'config' => [
                'livingArea' => 150,
                'numPeople' => 4,
                'buildingType' => 'detached_house',
                'includeHeating' => true,
                'heatingMethod' => 'electricity',
                'buildingRegion' => 'central',
                'buildingEnergyEfficiency' => '1990',
            ],
        ],
        'large_house_ground_pump' => [
            'label' => 'Suuri talo + MLP',
            'description' => 'Maalämpöpumppu',
            'icon' => 'house',
            'consumption' => 12000,
            'config' => [
                'livingArea' => 180,
                'numPeople' => 4,
                'buildingType' => 'detached_house',
                'includeHeating' => true,
                'heatingMethod' => 'ground_heat_pump',
                'buildingRegion' => 'central',
                'buildingEnergyEfficiency' => '2010',
            ],
        ],
    ];

    // Labels for dropdowns
    public array $buildingTypeLabels = [
        'apartment' => 'Kerrostalo',
        'row_house' => 'Rivitalo',
        'detached_house' => 'Omakotitalo',
    ];

    public array $heatingMethodLabels = [
        'electricity' => 'Suora sähkölämmitys',
        'air_to_water_heat_pump' => 'Ilma-vesilämpöpumppu',
        'ground_heat_pump' => 'Maalämpöpumppu',
        'district_heating' => 'Kaukolämpö',
        'oil' => 'Öljylämmitys',
        'fireplace' => 'Puulämmitys',
        'pellets' => 'Pelletti',
        'other' => 'Muu',
    ];

    public array $buildingRegionLabels = [
        'south' => 'Etelä-Suomi',
        'central' => 'Keski-Suomi',
        'north' => 'Pohjois-Suomi',
    ];

    public array $buildingEnergyEfficiencyLabels = [
        'passive' => 'Passiivitalo',
        'low_energy' => 'Matalaenergiatalo',
        '2010' => '2010-luku',
        '2000' => '2000-luku',
        '1990' => '1990-luku',
        '1980' => '1980-luku',
        '1970' => '1970-luku',
        '1960' => '1960-luku',
        'older' => 'Vanhempi',
    ];

    public array $supplementaryHeatingLabels = [
        'heat_pump' => 'Ilmalämpöpumppu',
        'exhaust_air_heat_pump' => 'Poistoilmalämpöpumppu',
        'fireplace' => 'Takka / puulämmitys',
    ];

    public function mount(): void
    {
        // Calculate initial result
        $this->calculate();
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function selectPreset(string $preset): void
    {
        $this->selectedPreset = $preset;

        if (isset($this->presets[$preset])) {
            $config = $this->presets[$preset]['config'];

            // Apply preset configuration
            $this->livingArea = $config['livingArea'];
            $this->numPeople = $config['numPeople'];
            $this->buildingType = $config['buildingType'];
            $this->includeHeating = $config['includeHeating'] ?? false;

            if ($this->includeHeating) {
                $this->heatingMethod = $config['heatingMethod'] ?? 'electricity';
                $this->buildingRegion = $config['buildingRegion'] ?? 'central';
                $this->buildingEnergyEfficiency = $config['buildingEnergyEfficiency'] ?? '2000';
            }

            // Reset extras when preset is selected
            $this->bathroomHeatingArea = 0;
            $this->saunaUsagePerWeek = 0;
            $this->saunaIsAlwaysOnType = false;
            $this->electricVehicleKmsPerMonth = 0;
            $this->cooling = false;
            $this->supplementaryHeating = null;

            $this->calculate();

            // Track preset selection
            $this->dispatch('track',
                eventName: 'Energy Preset Selected',
                props: [
                    'preset' => $preset,
                    'consumption' => $this->presets[$preset]['consumption'],
                ]
            );
        }
    }

    public function selectBuildingType(string $type): void
    {
        $this->buildingType = $type;
        $this->selectedPreset = null;
        $this->calculate();
    }

    public function toggleIncludeHeating(): void
    {
        $this->includeHeating = !$this->includeHeating;
        $this->selectedPreset = null;
        $this->calculate();
    }

    public function toggleCooling(): void
    {
        $this->cooling = !$this->cooling;
        $this->selectedPreset = null;
        $this->calculate();
    }

    public function updated($property): void
    {
        // Clear preset when user modifies any form value
        if (!in_array($property, ['activeTab', 'selectedPreset'])) {
            $this->selectedPreset = null;
        }

        $this->calculate();
    }

    public function calculate(): void
    {
        $calculator = app(EnergyCalculator::class);

        $request = new EnergyCalculatorRequest(
            livingArea: max(10, $this->livingArea),
            numPeople: max(1, $this->numPeople),
            buildingType: BuildingType::from($this->buildingType),
            heatingMethod: $this->includeHeating ? HeatingMethod::from($this->heatingMethod) : null,
            supplementaryHeating: $this->includeHeating && $this->supplementaryHeating
                ? SupplementaryHeatingMethod::from($this->supplementaryHeating)
                : null,
            buildingEnergyEfficiency: $this->includeHeating
                ? BuildingEnergyRating::from($this->buildingEnergyEfficiency)
                : null,
            buildingRegion: $this->includeHeating
                ? BuildingRegion::from($this->buildingRegion)
                : null,
            electricVehicleKmsPerMonth: $this->electricVehicleKmsPerMonth,
            bathroomHeatingArea: $this->bathroomHeatingArea,
            saunaUsagePerWeek: $this->saunaUsagePerWeek,
            saunaIsAlwaysOnType: $this->saunaIsAlwaysOnType,
            externalHeating: !$this->includeHeating,
            externalHeatingWater: !$this->includeHeating,
            cooling: $this->cooling,
        );

        $result = $calculator->estimate($request);
        $this->calculationResult = $result->toArray();
    }

    #[Computed]
    public function totalConsumption(): int
    {
        return $this->calculationResult['total'] ?? 0;
    }

    #[Computed]
    public function basicLivingConsumption(): int
    {
        return $this->calculationResult['basic_living'] ?? 0;
    }

    public function compareContracts(): void
    {
        // Track compare button click
        $this->dispatch('track',
            eventName: 'Energy Compare Clicked',
            props: [
                'total_kwh' => $this->totalConsumption,
                'includes_heating' => $this->includeHeating,
            ]
        );

        $this->redirect('/sahkosopimus?consumption=' . $this->totalConsumption);
    }

    public function render()
    {
        return view('livewire.consumption-calculator')
            ->layout('layouts.app', ['title' => 'Sähkönkulutuslaskuri - Voltikka']);
    }
}
