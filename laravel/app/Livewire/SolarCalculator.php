<?php

namespace App\Livewire;

use App\Models\SpotPriceAverage;
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

    // Savings calculation - only manual price input
    public ?float $manualPrice = null;
    public int $selfConsumptionPercent = 30;

    // Shading level labels
    public array $shadingLabels = [
        'none' => 'Ei varjostusta',
        'some' => 'Vähän varjostusta',
        'heavy' => 'Paljon varjostusta',
    ];

    // Finnish month abbreviations (short)
    public array $monthNames = [
        'Tam', 'Hel', 'Maa', 'Huh', 'Tou', 'Kes',
        'Hei', 'Elo', 'Syy', 'Lok', 'Mar', 'Jou',
    ];

    // Finnish month names (full)
    public array $monthNamesFull = [
        'Tammikuu', 'Helmikuu', 'Maaliskuu', 'Huhtikuu', 'Toukokuu', 'Kesäkuu',
        'Heinäkuu', 'Elokuu', 'Syyskuu', 'Lokakuu', 'Marraskuu', 'Joulukuu',
    ];

    public function mount(): void
    {
        // Prefill with average spot price from last year (with tax)
        $avgSpot = SpotPriceAverage::latestRolling365Days('FI');
        if ($avgSpot && $avgSpot->avg_price_with_tax) {
            $this->manualPrice = round($avgSpot->avg_price_with_tax, 2);
        } else {
            // Fallback to a reasonable default if no data
            $this->manualPrice = 8.0;
        }
    }

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

            // Track successful calculation
            $this->dispatch('track',
                eventName: 'Solar Calculation Completed',
                props: [
                    'system_kwp' => $this->systemKwp,
                    'annual_kwh' => round($result->annual_kwh),
                    'shading_level' => $this->shadingLevel,
                ]
            );
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
    public function effectivePrice(): ?float
    {
        return $this->manualPrice;
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
        return $this->hasResults && $this->effectivePrice !== null && $this->effectivePrice > 0;
    }

    public function render()
    {
        $year = date('Y');

        return view('livewire.solar-calculator')
            ->layout('layouts.app', [
                'title' => "Aurinkopaneelilaskuri {$year} – Laske aurinkopaneelien tuotto | Voltikka",
                'metaDescription' => "Ilmainen aurinkopaneelilaskuri {$year}. Laske aurinkopaneelien arvioitu vuosituotto ja säästöt osoitteesi perusteella. Käytämme EU:n PVGIS-tietokantaa tarkkaan tuottoarvioon Suomessa.",
                'canonical' => route('solar.calculator'),
            ]);
    }
}
