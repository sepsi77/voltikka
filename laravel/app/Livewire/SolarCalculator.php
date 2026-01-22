<?php

namespace App\Livewire;

use App\Models\ElectricityContract;
use App\Services\DigitransitGeocodingService;
use App\Services\DTO\GeocodingResult;
use App\Services\DTO\SolarEstimateRequest;
use App\Services\SolarCalculatorService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SolarCalculator extends Component
{
    // Address input
    public string $addressQuery = '';
    public array $addressSuggestions = [];
    public bool $showSuggestions = false;

    // Selected address
    public ?string $selectedLabel = null;
    public ?float $selectedLat = null;
    public ?float $selectedLon = null;

    // System settings
    public float $systemKwp = 5.0;
    public string $shadingLevel = 'none';

    // Results (stored as array for Livewire serialization)
    public array $calculationResult = [];
    public bool $isCalculating = false;
    public ?string $errorMessage = null;

    // Savings calculation
    public ?string $selectedContractId = null;
    public string $priceMode = 'contract'; // 'contract' or 'manual'
    public ?float $manualPrice = null;
    public int $selfConsumptionPercent = 30;

    // Shading level labels
    public array $shadingLabels = [
        'none' => 'Ei varjostusta',
        'some' => 'Vähän varjostusta',
        'heavy' => 'Paljon varjostusta',
    ];

    // Finnish month names
    public array $monthNames = [
        'Tammi', 'Helmi', 'Maalis', 'Huhti', 'Touko', 'Kesä',
        'Heinä', 'Elo', 'Syys', 'Loka', 'Marras', 'Joulu',
    ];

    public function updatedAddressQuery(): void
    {
        if (strlen($this->addressQuery) < 2) {
            $this->addressSuggestions = [];
            $this->showSuggestions = false;
            return;
        }

        $this->searchAddresses();
    }

    public function searchAddresses(): void
    {
        try {
            $service = app(DigitransitGeocodingService::class);
            $results = $service->search($this->addressQuery);

            $this->addressSuggestions = array_map(
                fn(GeocodingResult $result) => [
                    'label' => $result->label,
                    'lat' => $result->lat,
                    'lon' => $result->lon,
                ],
                array_slice($results, 0, 5) // Limit to 5 suggestions
            );

            $this->showSuggestions = count($this->addressSuggestions) > 0;
        } catch (\Exception $e) {
            $this->addressSuggestions = [];
            $this->showSuggestions = false;
        }
    }

    public function selectAddress(string $label, float $lat, float $lon): void
    {
        $this->selectedLabel = $label;
        $this->selectedLat = $lat;
        $this->selectedLon = $lon;
        $this->addressQuery = $label;
        $this->addressSuggestions = [];
        $this->showSuggestions = false;

        $this->calculateEstimate();
    }

    public function clearAddress(): void
    {
        $this->addressQuery = '';
        $this->selectedLabel = null;
        $this->selectedLat = null;
        $this->selectedLon = null;
        $this->addressSuggestions = [];
        $this->showSuggestions = false;
        $this->calculationResult = [];
        $this->errorMessage = null;
    }

    public function hideSuggestions(): void
    {
        $this->showSuggestions = false;
    }

    public function updatedSystemKwp(): void
    {
        if ($this->selectedLat !== null && $this->selectedLon !== null) {
            $this->calculateEstimate();
        }
    }

    public function updatedShadingLevel(): void
    {
        if ($this->selectedLat !== null && $this->selectedLon !== null) {
            $this->calculateEstimate();
        }
    }

    public function updatedPriceMode(): void
    {
        if ($this->priceMode === 'manual') {
            $this->selectedContractId = null;
        } else {
            $this->manualPrice = null;
        }
    }

    public function calculateEstimate(): void
    {
        if ($this->selectedLat === null || $this->selectedLon === null) {
            $this->errorMessage = 'Valitse ensin osoite.';
            return;
        }

        $this->isCalculating = true;
        $this->errorMessage = null;

        try {
            $service = app(SolarCalculatorService::class);

            $request = new SolarEstimateRequest(
                lat: $this->selectedLat,
                lon: $this->selectedLon,
                system_kwp: max(0.5, min(20.0, $this->systemKwp)),
                shading_level: $this->shadingLevel,
            );

            $result = $service->calculate($request);
            $this->calculationResult = $result->toArray();
        } catch (\Exception $e) {
            $this->errorMessage = 'Virhe laskennassa. Yritä uudelleen.';
            $this->calculationResult = [];
        } finally {
            $this->isCalculating = false;
        }
    }

    #[Computed]
    public function hasResults(): bool
    {
        return !empty($this->calculationResult) && isset($this->calculationResult['annual_kwh']);
    }

    #[Computed]
    public function annualKwh(): float
    {
        return $this->calculationResult['annual_kwh'] ?? 0;
    }

    #[Computed]
    public function monthlyKwh(): array
    {
        return $this->calculationResult['monthly_kwh'] ?? [];
    }

    #[Computed]
    public function maxMonthlyKwh(): float
    {
        $monthly = $this->monthlyKwh;
        return count($monthly) > 0 ? max($monthly) : 0;
    }

    #[Computed]
    public function availableContracts(): array
    {
        return ElectricityContract::with(['priceComponents' => function ($query) {
            $query->orderByDesc('price_date');
        }])
            ->where('target_group', 'Household')
            ->whereIn('pricing_model', ['FixedPrice', 'Hybrid'])
            ->orderBy('company_name')
            ->orderBy('name')
            ->get()
            ->map(function ($contract) {
                $price = $this->getContractPrice($contract);
                return [
                    'id' => $contract->id,
                    'name' => $contract->name,
                    'company_name' => $contract->company_name,
                    'metering' => $contract->metering,
                    'price_cents' => $price,
                ];
            })
            ->filter(fn($c) => $c['price_cents'] !== null)
            ->values()
            ->toArray();
    }

    #[Computed]
    public function selectedContract(): ?array
    {
        if (!$this->selectedContractId) {
            return null;
        }

        return collect($this->availableContracts)->firstWhere('id', $this->selectedContractId);
    }

    #[Computed]
    public function effectivePrice(): ?float
    {
        if ($this->priceMode === 'manual' && $this->manualPrice !== null) {
            return $this->manualPrice;
        }

        if ($this->selectedContract) {
            return $this->selectedContract['price_cents'];
        }

        return null;
    }

    #[Computed]
    public function annualSavings(): float
    {
        if (!$this->hasResults || $this->effectivePrice === null) {
            return 0;
        }

        // Formula: annual_kwh * self_consumption_pct * price_cents / 100
        return $this->annualKwh * ($this->selfConsumptionPercent / 100) * $this->effectivePrice / 100;
    }

    #[Computed]
    public function hasSavings(): bool
    {
        return $this->hasResults && $this->effectivePrice !== null;
    }

    private function getContractPrice(ElectricityContract $contract): ?float
    {
        $priceComponents = $contract->priceComponents;

        if ($contract->metering === 'Time') {
            // For time-based contracts, use day price (solar production is primarily during day)
            $dayPrice = $priceComponents->firstWhere('price_component_type', 'DayTime');
            return $dayPrice?->price;
        }

        // For general metering, use the General price
        $generalPrice = $priceComponents->firstWhere('price_component_type', 'General');
        return $generalPrice?->price;
    }

    public function render()
    {
        return view('livewire.solar-calculator')
            ->layout('layouts.app', [
                'title' => 'Aurinkopaneelilaskuri - Voltikka',
                'metaDescription' => 'Laske aurinkopaneelien tuotto osoitteesi perusteella. Ilmainen aurinkopaneelilaskuri näyttää arvion vuosituotannosta.',
            ]);
    }
}
