<?php

namespace App\Livewire;

use App\Models\SpotPriceAverage;
use App\Services\DigitransitGeocodingService;
use App\Services\DTO\GeocodingResult;
use App\Services\DTO\SolarEstimateRequest;
use App\Services\SolarCalculatorService;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SolarCalculator extends Component
{
    // Default-location worked example (Helsinki) shown before any address is entered,
    // so first-time visitors see the payoff shape instead of an empty box.
    private const EXAMPLE_LAT = 60.1699;
    private const EXAMPLE_LON = 24.9384;
    // Bounds the PVGIS estimate is meaningful within.
    private const MIN_KWP = 0.5;
    private const MAX_KWP = 20.0;
    private const MAX_PRICE_CENTS = 50.0;

    // Address input
    public string $addressQuery = '';
    public array $addressSuggestions = [];
    public bool $showSuggestions = false;
    public ?string $addressNotice = null;

    // Validation notices for the optional adjustments.
    public ?string $systemKwpNotice = null;

    // Selected address
    public ?string $selectedLabel = null;
    public ?float $selectedLat = null;
    public ?float $selectedLon = null;

    // System settings
    public float|string|null $systemKwp = 5.0;
    public string $shadingLevel = 'none';

    // Results (stored as array for Livewire serialization)
    public array $calculationResult = [];
    public bool $isCalculating = false;
    public ?string $errorMessage = null;

    // Default-location example shown until the visitor enters their own address.
    public array $exampleResult = [];

    // Savings calculation - only manual price input
    public ?float $manualPrice = null;
    public string $selfConsumptionScenario = 'with_battery';

    // Shading level labels
    public array $shadingLabels = [
        'none' => 'Ei varjostusta',
        'some' => 'Vähän varjostusta',
        'heavy' => 'Paljon varjostusta',
    ];

    // Self-consumption scenarios
    public array $selfConsumptionScenarios = [
        'no_battery' => [
            'label' => 'Ilman akkua',
            'description' => 'Ei akkua, sähkö käytetään suoraan päivällä',
            'percent' => 30,
        ],
        'with_battery' => [
            'label' => 'Akun kanssa',
            'description' => 'Akku varastoi ylituotannon omaan käyttöön',
            'percent' => 70,
        ],
        'smart_system' => [
            'label' => 'Akku + älykäs ohjaus',
            'description' => 'Akku ja kuormanohjaus (esim. lämminvesivaraaja)',
            'percent' => 90,
        ],
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

        $this->exampleResult = $this->buildExampleEstimate();
    }

    /**
     * Build the default-location (Helsinki) worked example shown before the visitor
     * enters an address. It mirrors the currently selected system size and shading so
     * the preview stays consistent with the controls on screen.
     *
     * Bot-safe: crawlers never trigger an uncached PVGIS request (it can hang long
     * enough to hit the PHP request timeout); a static fallback keeps the page useful
     * even on a cold cache or PVGIS failure.
     */
    private function buildExampleEstimate(): array
    {
        if ($this->isCrawlerRequest()) {
            return $this->staticExampleEstimate();
        }

        try {
            $service = app(SolarCalculatorService::class);

            $request = new SolarEstimateRequest(
                lat: self::EXAMPLE_LAT,
                lon: self::EXAMPLE_LON,
                system_kwp: $this->normalizedSystemKwp(),
                shading_level: $this->shadingLevel,
            );

            return $service->calculate($request)->toArray();
        } catch (\Throwable $e) {
            return $this->staticExampleEstimate();
        }
    }

    /**
     * Static Helsinki estimate used when a live PVGIS result is unavailable.
     * Base figures are for 5 kWp; scaled linearly to the selected system size.
     */
    private function staticExampleEstimate(): array
    {
        $baseMonthly = [40, 170, 390, 560, 680, 660, 650, 540, 370, 210, 80, 40];
        $scale = $this->normalizedSystemKwp() / 5.0;

        $monthly = array_map(fn ($kwh) => round($kwh * $scale), $baseMonthly);

        return [
            'annual_kwh' => array_sum($monthly),
            'monthly_kwh' => $monthly,
            'assumptions' => [],
        ];
    }

    private function isCrawlerRequest(): bool
    {
        $userAgent = Str::lower((string) request()->userAgent());

        return Str::contains($userAgent, [
            'bot', 'crawler', 'spider', 'slurp',
            'duckduckbot', 'baiduspider', 'yandex', 'bingpreview',
        ]);
    }

    public function updatedAddressQuery(): void
    {
        if (strlen($this->addressQuery) < 2) {
            $this->addressSuggestions = [];
            $this->showSuggestions = false;
            $this->addressNotice = null;
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

            // Tell the user a search ran but matched nothing, rather than silently
            // showing an empty dropdown that looks broken.
            $this->addressNotice = ($this->selectedLabel === null && !$this->showSuggestions)
                ? 'Osoitetta ei löytynyt. Tarkista kirjoitusasu, esim. "Mannerheimintie 1, Helsinki".'
                : null;
        } catch (\Exception $e) {
            $this->addressSuggestions = [];
            $this->showSuggestions = false;
            $this->addressNotice = 'Osoitehaku ei juuri nyt onnistu. Yritä hetken kuluttua uudelleen.';
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
        $this->addressNotice = null;

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
        $this->addressNotice = null;
        $this->calculationResult = [];
        $this->errorMessage = null;
    }

    public function hideSuggestions(): void
    {
        $this->showSuggestions = false;
    }

    public function updatedSystemKwp($value = null): void
    {
        // Mobile browsers/Livewire can send an empty string (hydrated as null) while the
        // user clears the number input. Keep the public property string/null tolerant and
        // normalize it here so typed-property hydration cannot unset it and later trigger
        // PropertyNotFoundException.
        $numericValue = is_numeric($value) ? (float) $value : self::MIN_KWP;
        $clamped = max(self::MIN_KWP, min(self::MAX_KWP, $numericValue));

        if (! is_numeric($value) || abs($clamped - $numericValue) > 0.0001) {
            $this->systemKwpNotice = 'Laskemme kokoarvion välillä '
                .rtrim(rtrim(number_format(self::MIN_KWP, 1, ',', ' '), '0'), ',')
                .'–'.(int) self::MAX_KWP.' kWp.';
            $this->systemKwp = $clamped;
        } else {
            $this->systemKwpNotice = null;
            $this->systemKwp = $numericValue;
        }

        if ($this->selectedLat !== null && $this->selectedLon !== null) {
            $this->calculateEstimate();
        } else {
            // Keep the default-location example in step with the selected size.
            $this->exampleResult = $this->buildExampleEstimate();
        }
    }

    private function normalizedSystemKwp(): float
    {
        $value = is_numeric($this->systemKwp) ? (float) $this->systemKwp : self::MIN_KWP;

        return max(self::MIN_KWP, min(self::MAX_KWP, $value));
    }

    public function updatedManualPrice(): void
    {
        if ($this->manualPrice !== null) {
            $this->manualPrice = max(0.0, min(self::MAX_PRICE_CENTS, (float) $this->manualPrice));
        }
    }

    public function updatedShadingLevel(): void
    {
        if ($this->selectedLat !== null && $this->selectedLon !== null) {
            $this->calculateEstimate();
        } else {
            $this->exampleResult = $this->buildExampleEstimate();
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
                system_kwp: $this->normalizedSystemKwp(),
                shading_level: $this->shadingLevel,
            );

            $result = $service->calculate($request);
            $this->calculationResult = $result->toArray();

            // Track successful calculation
            $this->dispatch('track',
                eventName: 'Solar Calculation Completed',
                props: [
                    'system_kwp' => $this->normalizedSystemKwp(),
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
    public function selfConsumptionPercent(): int
    {
        return $this->selfConsumptionScenarios[$this->selfConsumptionScenario]['percent'] ?? 70;
    }

    #[Computed]
    public function selfConsumptionLabel(): string
    {
        return $this->selfConsumptionScenarios[$this->selfConsumptionScenario]['label'] ?? 'Akun kanssa';
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

    #[Computed]
    public function hasExample(): bool
    {
        return !empty($this->exampleResult) && isset($this->exampleResult['annual_kwh']);
    }

    #[Computed]
    public function exampleAnnualKwh(): float
    {
        return $this->exampleResult['annual_kwh'] ?? 0;
    }

    #[Computed]
    public function exampleMonthlyKwh(): array
    {
        return $this->exampleResult['monthly_kwh'] ?? [];
    }

    #[Computed]
    public function exampleAnnualSavings(): float
    {
        if (!$this->hasExample || $this->effectivePrice === null) {
            return 0;
        }

        return $this->exampleAnnualKwh * ($this->selfConsumptionPercent / 100) * $this->effectivePrice / 100;
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
