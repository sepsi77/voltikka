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
    /**
     * Systems that can replace the current heating entirely or almost entirely.
     * The lead recommendation is only ever drawn from these; supplementary
     * options (e.g. a pure air-source pump) are never recommended as the answer.
     */
    public const PRIMARY_SYSTEMS = ['ground_source_hp', 'air_to_water_hp', 'pellets'];

    private const DEFAULT_INVESTMENTS = [
        'ground_source_hp' => 20000,
        'air_to_water_hp' => 12000,
        'air_to_air_hp' => 2800,
        'exhaust_air_hp' => 12000,
        'ilp_fireplace' => 5800,
        'exhaust_air_hp_fireplace' => 15000,
        'pellets' => 15000,
    ];

    // Building info. Numeric inputs accept temporary string/null states because
    // Livewire/mobile browsers can send an empty string while a number field is cleared.
    public int|string|null $livingArea = 150;

    public float|string|null $roomHeight = 2.5;

    public string $buildingRegion = 'central';

    public string $buildingEnergyEfficiency = '2000';

    public int|string|null $numPeople = 4;

    // Input mode: 'model_based' or 'bill_based'
    public string $inputMode = 'model_based';

    // Current heating method
    public string $currentHeatingMethod = 'electricity';

    // Bill-based inputs (used when inputMode = 'bill_based')
    public float|string|null $oilLitersPerYear = null;

    public float|string|null $electricityKwhPerYear = null;

    public float|string|null $districtHeatingEurosPerYear = null;

    // Prices
    public float|string|null $electricityPrice = 15.0; // c/kWh including VAT and transfer

    public float|string|null $oilPrice = 1.40; // €/liter

    public float|string|null $districtHeatingPrice = 10.8; // c/kWh

    public float|string|null $pelletPrice = 480.0; // €/ton

    // Investment costs (user-adjustable)
    public array $investments = self::DEFAULT_INVESTMENTS;

    // Financial parameters
    public float|string|null $interestRate = 2.0; // percent

    public int|string|null $calculationPeriod = 15; // years

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

    public function updatedInvestments(): void
    {
        $this->calculate();
    }

    public function updateInvestment(string $key, $value): void
    {
        $this->investments[$key] = $this->floatOrDefault(
            $value,
            (float) (self::DEFAULT_INVESTMENTS[$key] ?? 0)
        );
        $this->calculate();
    }

    public function toggleAdvancedSettings(): void
    {
        $this->showAdvancedSettings = ! $this->showAdvancedSettings;
    }

    private function normalizeNumericInputs(): void
    {
        // Blank number inputs use safe defaults. Values below the HTML minimum are
        // also corrected here because browser constraints do not protect Livewire
        // requests. Living area and active bill quantities keep their specific
        // validation errors below.
        $correctedMinimum = false;

        $this->livingArea = $this->intOrDefault($this->livingArea, 20);
        $this->roomHeight = $this->floatAtLeast($this->roomHeight, 2.0, 2.0, $correctedMinimum);
        $this->numPeople = $this->intAtLeast($this->numPeople, 1, 1, $correctedMinimum);

        $this->oilLitersPerYear = $this->nullableFloat($this->oilLitersPerYear);
        $this->electricityKwhPerYear = $this->nullableFloat($this->electricityKwhPerYear);
        $this->districtHeatingEurosPerYear = $this->nullableFloat($this->districtHeatingEurosPerYear);

        $this->electricityPrice = $this->floatAtLeast($this->electricityPrice, 1.0, 1.0, $correctedMinimum);
        $this->oilPrice = $this->floatAtLeast($this->oilPrice, 0.5, 0.5, $correctedMinimum);
        $this->districtHeatingPrice = $this->floatAtLeast($this->districtHeatingPrice, 1.0, 1.0, $correctedMinimum);
        $this->pelletPrice = $this->floatAtLeast($this->pelletPrice, 100.0, 100.0, $correctedMinimum);
        $this->interestRate = $this->floatAtLeast($this->interestRate, 0.0, 0.0, $correctedMinimum);
        $this->calculationPeriod = $this->intAtLeast($this->calculationPeriod, 5, 5, $correctedMinimum);

        foreach (self::DEFAULT_INVESTMENTS as $key => $default) {
            $this->investments[$key] = $this->floatAtLeast(
                $this->investments[$key] ?? null,
                (float) $default,
                0.0,
                $correctedMinimum,
            );
        }

        if ($correctedMinimum) {
            $this->errorMessage = 'Korjasimme liian pienen arvon kentän sallittuun vähimmäisarvoon.';
        }
    }

    private function intOrDefault(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private function floatOrDefault(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function intAtLeast(mixed $value, int $default, int $minimum, bool &$corrected): int
    {
        $normalized = $this->intOrDefault($value, $default);

        if (is_numeric($value) && $normalized < $minimum) {
            $corrected = true;
        }

        return max($minimum, $normalized);
    }

    private function floatAtLeast(mixed $value, float $default, float $minimum, bool &$corrected): float
    {
        $normalized = $this->floatOrDefault($value, $default);

        if (is_numeric($value) && $normalized < $minimum) {
            $corrected = true;
        }

        return max($minimum, $normalized);
    }

    public function calculate(): void
    {
        $this->errorMessage = null;
        $this->normalizeNumericInputs();

        try {
            // Validate inputs
            if ($this->livingArea < 20 || $this->livingArea > 500) {
                $this->errorMessage = 'Pinta-ala pitää olla välillä 20-500 m².';

                return;
            }

            if ($this->inputMode === 'bill_based') {
                if ($this->currentHeatingMethod === 'oil' && (! $this->oilLitersPerYear || $this->oilLitersPerYear <= 0)) {
                    $this->errorMessage = 'Syötä öljynkulutus litroina.';

                    return;
                }
                if ($this->currentHeatingMethod === 'electricity' && (! $this->electricityKwhPerYear || $this->electricityKwhPerYear <= 0)) {
                    $this->errorMessage = 'Syötä sähkönkulutus kWh.';

                    return;
                }
                if ($this->currentHeatingMethod === 'district_heating' && (! $this->districtHeatingEurosPerYear || $this->districtHeatingEurosPerYear <= 0)) {
                    $this->errorMessage = 'Syötä kaukolämpölasku euroina.';

                    return;
                }
            }

            $service = app(HeatPumpComparisonService::class);

            $request = new HeatPumpComparisonRequest(
                livingArea: $this->livingArea,
                roomHeight: $this->roomHeight,
                region: BuildingRegion::tryFrom($this->buildingRegion) ?? BuildingRegion::Central,
                energyRating: BuildingEnergyRating::tryFrom($this->buildingEnergyEfficiency) ?? BuildingEnergyRating::Year2000,
                numPeople: $this->numPeople,
                inputMode: $this->inputMode,
                currentHeatingMethod: CurrentHeatingMethod::tryFrom($this->currentHeatingMethod) ?? CurrentHeatingMethod::Electricity,
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
        return ! empty($this->calculationResult) && isset($this->calculationResult['currentSystem']);
    }

    #[Computed]
    public function currentSystem(): array
    {
        return $this->calculationResult['currentSystem'] ?? [];
    }

    /**
     * Only full-replacement primary systems are surfaced. Supplementary options
     * (air-to-air, exhaust-air, "+ tulisija") are intentionally excluded: the
     * comparison service costs their uncovered load as cheap direct electricity
     * regardless of the household's actual heating, which understates their real
     * cost. Until that is modelled against the current primary heating, they are
     * not shown.
     */
    #[Computed]
    public function alternatives(): array
    {
        $all = $this->calculationResult['alternatives'] ?? [];

        return array_values(array_filter(
            $all,
            fn ($alt) => in_array($alt['key'] ?? null, self::PRIMARY_SYSTEMS, true)
        ));
    }

    /**
     * The single option we lead with: the money-saving PRIMARY system with the
     * lowest annualized total cost. Supplementary options are excluded so the
     * answer is always a full-replacement solution. Returns null when nothing
     * pays off, so the page never invents a recommendation the numbers do not support.
     */
    #[Computed]
    public function recommendedAlternative(): ?array
    {
        $saving = array_values(array_filter(
            $this->alternatives,
            fn ($alt) => in_array($alt['key'] ?? null, self::PRIMARY_SYSTEMS, true)
                && ($alt['annualSavings'] ?? 0) > 0
        ));

        if (empty($saving)) {
            return null;
        }

        usort(
            $saving,
            fn ($a, $b) => ($a['annualizedTotalCost'] ?? PHP_INT_MAX) <=> ($b['annualizedTotalCost'] ?? PHP_INT_MAX)
        );

        return $saving[0];
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

    /**
     * Single source of truth for the FAQ: drives both the visible <details>
     * block and the FAQPage JSON-LD, so the two can never drift. Ordered to
     * lead with the "kannattaako" target queries.
     *
     * @return array<int, array{question: string, answer: string}>
     */
    public function getFaqItemsProperty(): array
    {
        return [
            [
                'question' => 'Kannattaako lämpöpumppu?',
                'answer' => 'Useimmiten kyllä, jos korvaat suoran sähkölämmityksen tai öljylämmityksen. Lämpöpumppu tuottaa yhdellä sähkökilowattitunnilla kaksi tai kolme kilowattituntia lämpöä, joten lämmityksen energiakustannus laskee selvästi. Kannattavuus riippuu kuitenkin talosi koosta, nykyisestä lämmitystavasta, investoinnin hinnasta ja energian hinnoista. Tämän sivun laskuri arvioi säästön ja takaisinmaksuajan juuri sinun tiedoillasi.',
            ],
            [
                'question' => 'Kannattaako maalämpö?',
                'answer' => 'Maalämpö on energiatehokkain vaihtoehto: vuotuinen hyötysuhde (SPF) on Suomen olosuhteissa tyypillisesti noin 2,9, eli se tuottaa lähes kolme kilowattituntia lämpöä jokaista käytettyä sähkökilowattituntia kohden. Investointi on suurin, usein noin 20 000 euroa, joten maalämpö kannattaa parhaiten suuremmissa taloissa ja silloin kun lämmitystarve on iso. Pienemmässä talossa edullisempi ilma-vesilämpöpumppu voi tulla kokonaiskustannukseltaan halvemmaksi.',
            ],
            [
                'question' => 'Kannattaako ilma-vesilämpöpumppu?',
                'answer' => 'Ilma-vesilämpöpumppu sopii vesikiertoiseen lämmitykseen ja maksaa selvästi vähemmän kuin maalämpö, tyypillisesti noin 12 000 euroa. Sen vuotuinen hyötysuhde (SPF) on noin 2,3, ja se kattaa lämmöntarpeesta noin 80 prosenttia. Loput noin 20 prosenttia, etenkin kovilla pakkasilla, tuotetaan yleensä suoralla sähkövastuksella. Pienemmässä tai kohtalaisesti lämmitettävässä talossa se on usein kokonaiskustannukseltaan kannattavin ratkaisu.',
            ],
            [
                'question' => 'Milloin lämpöpumppu ei kannata?',
                'answer' => 'Kaukolämmön korvaaminen lämpöpumpulla on harvoin taloudellisesti kannattavaa, koska kaukolämpö on jo valmiiksi edullista energiaa. Myös hyvin pienen lämmitystarpeen taloissa, kuten matalaenergia- ja passiivitaloissa, suuri investointi voi maksaa itsensä takaisin hyvin hitaasti. Laskuri kertoo suoraan, jos täysi lämmitysvaihto ei tuota säästöä sinun tiedoillasi, eikä keksi suositusta, jota luvut eivät tue.',
            ],
            [
                'question' => 'Mikä on paras lämpöpumppu omakotitaloon?',
                'answer' => 'Paras lämpöpumppu riippuu talon koosta, nykyisestä lämmitystavasta ja budjetista. Maalämpö on tehokkain (SPF noin 2,9) ja sopii suuriin taloihin, ilma-vesilämpöpumppu on edullisempi investointi ja sopii vesikiertoiseen lämmitykseen, ja ilmalämpöpumppu täydentää olemassa olevaa lämmitystä. Tällä sivulla laskuri vertailee täysimittaiset vaihtoehdot ja suosittelee kokonaiskustannukseltaan edullisinta.',
            ],
            [
                'question' => 'Mikä on lämpöpumpun takaisinmaksuaika?',
                'answer' => 'Takaisinmaksuaika riippuu investoinnin hinnasta, nykyisestä lämmitystavasta ja energian hinnoista. Öljylämmityksen korvaamisessa takaisinmaksuaika on tyypillisesti 5–10 vuotta ja suoran sähkölämmityksen korvaamisessa 8–15 vuotta. Kaukolämmön korvaaminen on harvoin kannattavaa. Laskuri näyttää arvion juuri sinun talollesi.',
            ],
            [
                'question' => 'Paljonko maalämpöpumppu säästää vuodessa?',
                'answer' => 'Maalämpö voi pienentää lämmityksen energiakustannusta noin 50–70 prosenttia verrattuna suoraan sähkölämmitykseen. 150 neliön talossa, joka kuluttaa noin 20 000 kWh lämmitysenergiaa vuodessa, säästö voi olla joitakin tuhansia euroja vuodessa sähkön hinnasta riippuen. Todellinen säästö riippuu käyttötottumuksista ja energian hintojen kehityksestä.',
            ],
            [
                'question' => 'Toimiiko ilmalämpöpumppu kovilla pakkasilla?',
                'answer' => 'Nykyaikaiset ilmalämpöpumput toimivat jopa noin -25 asteen pakkasilla, mutta hyötysuhde heikkenee lämpötilan laskiessa. Kovimmilla pakkasilla pumppu tarvitsee usein tuekseen muuta lämmitystä. Maalämpö toimii tehokkaasti ympäri vuoden, koska maaperän lämpötila pysyy tasaisena.',
            ],
        ];
    }

    /**
     * FAQPage structured data built from the single FAQ source above.
     */
    public function buildFaqJsonLd(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $faq): array => [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['answer'],
                ],
            ], $this->faqItems),
        ];
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

        return view('livewire.heat-pump-calculator', [
            // Rendered in the view via <x-schema-markup>; the shared layout does not output $jsonLd.
            'jsonLd' => $this->getJsonLdSchema(),
            'faqJsonLd' => $this->buildFaqJsonLd(),
        ])
            ->layout('layouts.app', [
                'title' => "Kannattaako lämpöpumppu? Vertailu ja laskuri {$year} | Voltikka",
                'metaDescription' => "Kannattaako lämpöpumppu juuri sinun talossasi? Ilmainen laskuri vertailee, kannattaako maalämpö vai ilma-vesilämpöpumppu, ja arvioi säästöt ja takaisinmaksuajan {$year}.",
                'canonical' => route('heatpump.calculator'),
            ]);
    }
}
