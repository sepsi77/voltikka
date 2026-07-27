<?php

namespace App\Livewire;

use App\Enums\BuildingEnergyRating;
use App\Enums\BuildingRegion;
use App\Enums\BuildingType;
use App\Enums\HeatingMethod;
use App\Enums\SupplementaryHeatingMethod;
use App\Models\ContractPriceDailyStatistic;
use App\Services\ContractStatistics\ContractPriceBasis;
use App\Services\DTO\EnergyCalculatorRequest;
use App\Services\EnergyCalculator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class ConsumptionCalculator extends Component
{
    /** Consumption levels that own their own SEO landing page under /sahkosopimus/kulutus. */
    private const CONSUMPTION_PAGE_LEVELS = [2000, 5000, 10000, 18000, 20000];

    /**
     * Request-scoped memo for `priceStatisticsRows()`: `[statDate, groupedRows]`, or
     * `false` once a lookup has confirmed there are no statistics. Deliberately
     * protected, never public: it holds a full Eloquent collection and would bloat the
     * Livewire snapshot for no gain, because it is recomputed from the database anyway.
     *
     * @var array{0: string, 1: Collection}|false|null
     */
    protected array|false|null $priceStatisticsRows = null;

    // Basic form fields
    public int|string|null $livingArea = 80;

    public int|string|null $numPeople = 2;

    public ?string $buildingType = 'apartment';

    // Heating settings
    public bool $includeHeating = false;

    public ?string $heatingMethod = 'electricity';

    public ?string $buildingRegion = 'central';

    public ?string $buildingEnergyEfficiency = '2000';

    public ?string $supplementaryHeating = null;

    // Extras
    public int|string|null $bathroomHeatingArea = 0;

    public int|string|null $saunaUsagePerWeek = 0;

    public bool $saunaIsAlwaysOnType = false;

    public int|string|null $electricVehicleKmsPerMonth = 0;

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
        $this->includeHeating = ! $this->includeHeating;
        $this->selectedPreset = null;
        $this->calculate();
    }

    public function toggleCooling(): void
    {
        $this->cooling = ! $this->cooling;
        $this->selectedPreset = null;
        $this->calculate();
    }

    public function updated($property): void
    {
        // Clear preset when user modifies any form value
        if ($property !== 'selectedPreset') {
            $this->selectedPreset = null;
        }

        $this->calculate();
    }

    public function calculate(): void
    {
        $calculator = app(EnergyCalculator::class);

        $buildingType = BuildingType::tryFrom($this->safeStringValue('buildingType', BuildingType::Apartment->value))
            ?? BuildingType::Apartment;
        $heatingMethod = HeatingMethod::tryFrom($this->safeStringValue('heatingMethod', HeatingMethod::Electricity->value))
            ?? HeatingMethod::Electricity;
        $supplementaryHeating = SupplementaryHeatingMethod::tryFrom($this->safeStringValue('supplementaryHeating', ''));
        $buildingEnergyEfficiency = BuildingEnergyRating::tryFrom($this->safeStringValue('buildingEnergyEfficiency', BuildingEnergyRating::Year2000->value))
            ?? BuildingEnergyRating::Year2000;
        $buildingRegion = BuildingRegion::tryFrom($this->safeStringValue('buildingRegion', BuildingRegion::Central->value))
            ?? BuildingRegion::Central;

        $livingArea = $this->normalizeIntProperty('livingArea', 20);
        $numPeople = $this->normalizeIntProperty('numPeople', 1);
        $electricVehicleKmsPerMonth = $this->normalizeIntProperty('electricVehicleKmsPerMonth', 0);
        $bathroomHeatingArea = $this->normalizeIntProperty('bathroomHeatingArea', 0);
        $saunaUsagePerWeek = $this->normalizeIntProperty('saunaUsagePerWeek', 0);

        $request = new EnergyCalculatorRequest(
            livingArea: $livingArea,
            numPeople: $numPeople,
            buildingType: $buildingType,
            heatingMethod: $this->includeHeating ? $heatingMethod : null,
            supplementaryHeating: $this->includeHeating ? $supplementaryHeating : null,
            buildingEnergyEfficiency: $this->includeHeating ? $buildingEnergyEfficiency : null,
            buildingRegion: $this->includeHeating ? $buildingRegion : null,
            electricVehicleKmsPerMonth: $electricVehicleKmsPerMonth,
            bathroomHeatingArea: $bathroomHeatingArea,
            saunaUsagePerWeek: $saunaUsagePerWeek,
            saunaIsAlwaysOnType: $this->saunaIsAlwaysOnType,
            externalHeating: ! $this->includeHeating,
            externalHeatingWater: ! $this->includeHeating,
            cooling: $this->cooling,
        );

        $result = $calculator->estimate($request);
        $this->calculationResult = $result->toArray();
    }

    protected function safeRawValue(string $property, mixed $default): mixed
    {
        $publicProperties = get_object_vars($this);

        if (! array_key_exists($property, $publicProperties)) {
            return $default;
        }

        return $publicProperties[$property] ?? $default;
    }

    protected function safeIntValue(string $property, int $default): int
    {
        $value = $this->safeRawValue($property, $default);

        if ($value === '' || $value === null || $value === false) {
            return $default;
        }

        return (int) $value;
    }

    protected function safeStringValue(string $property, string $default): string
    {
        $value = $this->safeRawValue($property, $default);

        if ($value === '' || $value === null) {
            return $default;
        }

        return (string) $value;
    }

    protected function normalizeIntProperty(string $property, int $minimum): int
    {
        $value = max($minimum, $this->safeIntValue($property, $minimum));
        $this->{$property} = $value;

        return $value;
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

    #[Computed]
    public function contractTypePriceEstimates(): array
    {
        return $this->priceEstimatesFor($this->totalConsumption);
    }

    /**
     * The contract types the price table and the FAQ quote, keyed by statistics segment.
     *
     * Single source of truth: `priceStatisticsRows()` loads exactly these segment keys, so
     * adding a type here is enough for its rows to be fetched. Do not reintroduce a
     * separate key list — a segment missing from the query is silently dropped from the
     * table rather than failing.
     */
    protected function priceSegments(): array
    {
        return [
            'spot' => [
                'label' => 'Pörssisähkö',
                'description' => 'Toteutuneeseen pörssihintaan ja tyypilliseen marginaaliin perustuva arvio.',
            ],
            'fixed_term_12' => [
                'label' => 'Määräaikainen 12 kk',
                'description' => 'Vuodeksi lukittu kiinteä energiahinta.',
            ],
            'fixed_term_24' => [
                'label' => 'Määräaikainen 24 kk',
                'description' => 'Kahdeksi vuodeksi lukittu kiinteä energiahinta.',
            ],
            'open_ended' => [
                'label' => 'Toistaiseksi voimassa oleva',
                'description' => 'Jatkuva sopimus, jonka hintaa voidaan muuttaa ennakkoilmoituksella.',
            ],
            'hybrid' => [
                'label' => 'Joustosähkö',
                'description' => 'Kiinteä energiahinta, johon voi tulla kulutusvaikutus.',
            ],
        ];
    }

    /**
     * Annual-cost estimates per contract type at an arbitrary consumption level.
     *
     * Split out from `contractTypePriceEstimates()` so the FAQ can quote fixed kWh
     * levels (10 000 / 20 000 kWh) that answer the "Paljonko maksaa N kWh?" queries
     * without hardcoding cent figures that go stale. The statistics rows are loaded
     * once per request by `priceStatisticsRows()`, so extra levels cost no extra query.
     */
    protected function priceEstimatesFor(int $consumption): array
    {
        $snapshot = $this->priceStatisticsRows();

        if ($snapshot === null || $consumption <= 0) {
            return [
                'date' => null,
                'rows' => [],
            ];
        }

        [$statDate, $stats] = $snapshot;

        $rows = [];
        foreach ($this->priceSegments() as $segmentKey => $config) {
            $costs = [];
            foreach (['p20', 'median', 'p80'] as $quantile) {
                // Every public annual estimate comes from the stored annual-cost
                // metric. A canonical package or canonical-only contract can have a
                // valid annual total while its all-in unit rate is intentionally null.
                $annual = $this->interpolatedAnnualCost($stats, $segmentKey, $quantile, $consumption);

                $costs[$quantile] = $annual !== null ? [
                    'annual' => $annual,
                    'monthly' => $annual / 12,
                ] : null;
            }

            if ($costs['median'] === null) {
                continue;
            }

            $rows[] = [
                'key' => $segmentKey,
                'label' => $config['label'],
                'description' => $config['description'],
                'costs' => $costs,
                'contract_count' => $this->nearestAnnualCostRow($stats, $segmentKey, $consumption)?->contract_count,
            ];
        }

        usort($rows, fn (array $a, array $b): int => ($a['costs']['median']['annual'] ?? PHP_FLOAT_MAX) <=> ($b['costs']['median']['annual'] ?? PHP_FLOAT_MAX));

        return [
            'date' => $statDate,
            'rows' => $rows,
        ];
    }

    /**
     * Latest statistics date plus its grouped rows, loaded at most once per request.
     *
     * Returns `null` when no statistics exist at all. The page calls
     * `priceEstimatesFor()` for the visitor's own consumption and again for the two
     * fixed FAQ levels, so this must not run one query per call.
     *
     * @return array{0: string, 1: Collection}|null
     */
    protected function priceStatisticsRows(): ?array
    {
        if ($this->priceStatisticsRows !== null) {
            return $this->priceStatisticsRows === false ? null : $this->priceStatisticsRows;
        }

        $pricingBasis = ContractPriceBasis::expectedCurrent()->value;
        $latestDate = ContractPriceDailyStatistic::query()
            ->where('pricing_basis', $pricingBasis)
            ->where('metric_key', 'annual_cost')
            ->max('stat_date');

        if (! $latestDate) {
            $this->priceStatisticsRows = false;

            return null;
        }

        $statDate = Carbon::parse($latestDate)
            ->setTimezone((string) config('app.timezone'))
            ->toDateString();

        $stats = ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', $statDate)
            ->whereIn('segment_key', array_keys($this->priceSegments()))
            ->where('metric_key', 'annual_cost')
            ->where('pricing_basis', $pricingBasis)
            ->get()
            ->groupBy(fn (ContractPriceDailyStatistic $row): string => $row->segment_key.':'.$row->metric_key.':'.($row->consumption_kwh ?? ''));

        return $this->priceStatisticsRows = [$statDate, $stats];
    }

    protected function statValues(?ContractPriceDailyStatistic $row): ?array
    {
        if (! $row || $row->median_value === null) {
            return null;
        }

        return [
            'p20' => $row->p20_value !== null ? (float) $row->p20_value : null,
            'median' => (float) $row->median_value,
            'p80' => $row->p80_value !== null ? (float) $row->p80_value : null,
        ];
    }

    protected function interpolatedAnnualCost(Collection $stats, string $segmentKey, string $quantile, int $consumption): ?float
    {
        $points = [];
        foreach ([2000, 5000, 18000] as $level) {
            $row = $stats->get($segmentKey.':annual_cost:'.$level)?->first();
            $values = $this->statValues($row);

            if (($values[$quantile] ?? null) !== null) {
                $points[$level] = (float) $values[$quantile];
            }
        }

        if ($points === []) {
            return null;
        }

        ksort($points);

        if (array_key_exists($consumption, $points)) {
            return $points[$consumption];
        }

        $levels = array_keys($points);
        $lower = $levels[0];
        $upper = $levels[count($levels) - 1];

        foreach ($levels as $level) {
            if ($level <= $consumption) {
                $lower = $level;
            }

            if ($level >= $consumption) {
                $upper = $level;
                break;
            }
        }

        if ($lower === $upper) {
            return $points[$lower];
        }

        $ratio = ($consumption - $lower) / ($upper - $lower);

        return $points[$lower] + (($points[$upper] - $points[$lower]) * $ratio);
    }

    private function nearestAnnualCostRow(Collection $stats, string $segmentKey, int $consumption): ?ContractPriceDailyStatistic
    {
        $nearest = collect([2000, 5000, 18000])
            ->sortBy(fn (int $level) => abs($level - $consumption))
            ->first(fn (int $level) => $stats->has($segmentKey.':annual_cost:'.$level));

        return $nearest === null
            ? null
            : $stats->get($segmentKey.':annual_cost:'.$nearest)?->first();
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

        $this->redirect('/sahkosopimus?consumption='.$this->totalConsumption);
    }

    public function getPageHeadingProperty(): string
    {
        return 'Sähkönkulutuslaskuri';
    }

    public function getPageTaglineProperty(): string
    {
        return 'Arvioi kotitaloutesi vuotuinen sähkönkulutus ja sähkön hinta eri sopimustyypeillä muutamassa sekunnissa.';
    }

    public function getSeoIntroTextProperty(): string
    {
        return 'Sähkönkulutuslaskurilla arvioit nopeasti, paljonko kotitaloutesi käyttää sähköä vuodessa ja mitä sähkö maksaisi eri sopimustyypeillä. '
            .'Syötä asunnon koko, asukasmäärä ja lämmitystapa – laskuri laskee perussähkön, lämmityksen ja muut kulutuskohteet (sauna, sähköauto, lattialämmitys) erikseen. '
            .'Kun tiedät vuosikulutuksesi kilowattitunteina, sähkön hinta laskuri arvioi vuosikustannuksen pörssisähköllä, määräaikaisilla ja toistaiseksi voimassa olevilla sopimuksilla Voltikan hintatilastojen perusteella.';
    }

    /**
     * The consumption levels that have their own SEO landing page, for cross-linking.
     *
     * @return list<array{kwh: int, label: string, url: string}>
     */
    #[Computed]
    public function consumptionPageLinks(): array
    {
        return array_map(fn (int $level): array => [
            'kwh' => $level,
            'label' => number_format($level, 0, ',', ' ').' kWh',
            'url' => '/sahkosopimus/kulutus/'.$level.'-kwh',
        ], self::CONSUMPTION_PAGE_LEVELS);
    }

    /**
     * The consumption-level page closest to the visitor's calculated result, so the
     * calculator hands its answer to a page that already ranks for that kWh amount.
     */
    #[Computed]
    public function nearestConsumptionPage(): ?array
    {
        if ($this->totalConsumption <= 0) {
            return null;
        }

        $closest = null;
        $smallestGap = null;

        foreach ($this->consumptionPageLinks as $link) {
            $gap = abs($link['kwh'] - $this->totalConsumption);

            if ($smallestGap === null || $gap < $smallestGap) {
                $smallestGap = $gap;
                $closest = $link;
            }
        }

        return $closest;
    }

    /**
     * Cheapest-to-priciest median annual cost across contract types at one consumption
     * level, or null when statistics are missing. Used to answer "Paljonko maksaa
     * N kWh?" with current figures instead of a cent price that rots in the source.
     *
     * @return array{min: float, max: float}|null
     */
    protected function annualCostRangeFor(int $consumption): ?array
    {
        $medians = [];

        foreach ($this->priceEstimatesFor($consumption)['rows'] as $row) {
            $annual = $row['costs']['median']['annual'] ?? null;

            if ($annual !== null) {
                $medians[] = $annual;
            }
        }

        if ($medians === []) {
            return null;
        }

        return ['min' => min($medians), 'max' => max($medians)];
    }

    /** Round to the nearest 10 € so a FAQ sentence does not imply false precision. */
    protected function formatRoundedEur(float $value): string
    {
        return number_format(round($value / 10) * 10, 0, ',', ' ');
    }

    /**
     * Answers "Paljonko maksaa N kWh sähköä?" for a level that owns a landing page.
     *
     * The figure is energy only, excluding siirto. Competing results answer the same
     * question with a transfer-inclusive total, so the exclusion must stay explicit in
     * the sentence — otherwise our number reads as a wrong, too-cheap version of theirs.
     */
    protected function consumptionCostFaqAnswer(int $consumption): string
    {
        $label = number_format($consumption, 0, ',', ' ');
        $range = $this->annualCostRangeFor($consumption);

        if ($range === null) {
            return $label.' kWh vuosikulutuksen hinta lasketaan kaavalla kulutus × energian hinta snt/kWh / 100 '
                .'+ perusmaksu × 12. Hinta riippuu sopimustyypistä, joten vertaa se omalla kulutuksellasi '
                .'Voltikan sähkösopimusvertailussa. Luku kattaa sähköenergian, ei sähkön siirtoa.';
        }

        $cheapest = $this->formatRoundedEur($range['min']);
        $priciest = $this->formatRoundedEur($range['max']);

        // Both ends can round to the same figure when only one contract type has
        // statistics for the day; "1 750–1 750 €" reads as a bug, so collapse it.
        $amount = $cheapest === $priciest
            ? 'noin '.$cheapest.' € vuodessa'
            : 'noin '.$cheapest.'–'.$priciest.' € vuodessa sopimustyypistä riippuen';

        return $label.' kWh sähköä maksaa tällä hetkellä '.$amount.'. '
            .'Luku on pelkkää sähköenergiaa sisältäen arvonlisäveron, eikä siihen kuulu sähkön siirtoa, '
            .'joka laskutetaan erikseen verkkoyhtiön laskulla. Arvio perustuu Voltikan päivittäin '
            .'päivittyviin sopimushintatilastoihin.';
    }

    public function getFaqItemsProperty(): array
    {
        return [
            [
                'question' => 'Kuinka paljon kerrostaloasunto kuluttaa sähköä vuodessa?',
                'answer' => 'Tyypillinen kerrostaloasunto kuluttaa ilman sähkölämmitystä noin 2 000–5 000 kWh vuodessa asukasmäärästä ja asunnon koosta riippuen. Yksin asuvan pienen yksiön kulutus jää usein alle 2 000 kWh, kun taas neljän hengen perheen kerrostalokodissa kulutus voi nousta 4 000–5 000 kilowattituntiin vuodessa.',
            ],
            [
                'question' => 'Kuinka paljon omakotitalo kuluttaa sähköä?',
                'answer' => 'Omakotitalon vuotuinen sähkönkulutus on tyypillisesti 5 000–10 000 kWh ilman sähkölämmitystä ja 15 000–25 000 kWh, jos talo lämmitetään suoralla sähkölämmityksellä. Ilma-vesilämpöpumppu tai maalämpö pienentää lämmityksen sähkönkulutuksen noin kolmas- tai puoliosaan.',
            ],
            [
                'question' => 'Onko Voltikan sähkönkulutuslaskuri ilmainen?',
                'answer' => 'Kyllä, sähkönkulutuslaskuri on täysin ilmainen ja sen käyttö ei vaadi rekisteröitymistä. Saat arvion vuosikulutuksesta heti ja voit jatkaa sähkösopimusten vertailuun yhdellä klikkauksella.',
            ],
            [
                'question' => 'Miten arvioin sähköauton vaikutuksen kulutukseen?',
                'answer' => 'Sähköauto kuluttaa keskimäärin noin 0,2 kWh kilometriä kohden. 1 500 kilometrin kuukausiajot kasvattavat vuosikulutusta noin 3 600 kWh:lla. Voltikan laskurissa voit syöttää kuukausittaiset ajokilometrit, jolloin sähköauton osuus erotellaan tuloksessa omaksi eräkseen.',
            ],
            [
                'question' => 'Miten saunan käyttö vaikuttaa sähkönkulutukseen?',
                'answer' => 'Tavallinen sähkökiuas kuluttaa noin 7,5 kWh yhtä lämmityskertaa kohti. Kerran viikossa lämmitettävä sauna lisää vuosikulutusta noin 390 kWh, ja jatkuvalämmitteinen kiuas voi nostaa kulutusta jopa 2 500–3 000 kWh vuodessa.',
            ],
            [
                // Google quotes this answer as the search snippet for "sähkön hinta laskuri"
                // instead of the meta description. It must therefore state the formula (that
                // match is why the page ranks) AND give a reason to click, because a snippet
                // that only prints the formula answers the searcher inside the SERP.
                'question' => 'Miten sähkön hinta lasketaan vuosikulutuksesta?',
                'answer' => 'Sähkön vuosikustannus on kulutus kWh × snt/kWh / 100 + kuukausimaksu × 12. Laskuri täyttää nykyisen snt/kWh-hinnan puolestasi ja näyttää vuosihinnan erikseen pörssisähkölle, määräaikaisille ja toistaiseksi voimassa oleville sopimuksille. Hinnat tulevat Voltikan päivittäin päivittyvistä sopimushintatilastoista, joten kaavaan ei tarvitse arvata hintaa itse.',
            ],
            [
                'question' => 'Mikä sopimustyyppi on halvin omalla kulutuksella?',
                'answer' => 'Halvin sopimustyyppi riippuu kulutuksesta, kuukausimaksusta ja energian hinnasta. Pienellä kulutuksella perusmaksu korostuu, kun taas suurella kulutuksella pienikin ero senttiä per kilowattitunti -hinnassa vaikuttaa paljon vuosikustannukseen.',
            ],
            [
                'question' => 'Paljonko 20 000 kWh sähköä maksaa vuodessa?',
                'answer' => $this->consumptionCostFaqAnswer(20000),
            ],
            [
                'question' => 'Paljonko 10 000 kWh sähköä maksaa vuodessa?',
                'answer' => $this->consumptionCostFaqAnswer(10000),
            ],
            [
                'question' => 'Mikä kodin laite kuluttaa eniten sähköä?',
                'answer' => 'Sähkölämmitteisessä kodissa selvästi eniten kuluttaa lämmitys, joka vie tyypillisesti 50–70 % koko vuosikulutuksesta. Yksittäisistä laitteista suurimmat ovat käyttöveden lämmitys (noin 1 000 kWh asukasta kohti vuodessa), sähkökiuas (noin 7,5 kWh lämmityskerralta) ja sähköauton lataus (noin 0,2 kWh kilometriltä). Kylmälaitteet kuluttavat vähemmän kerralla mutta ovat päällä jatkuvasti, joten vanha pakastin voi silti viedä 300–500 kWh vuodessa.',
            ],
            [
                'question' => 'Paljonko on normaali sähkölasku kuukaudessa?',
                'answer' => 'Sähkölaskun suuruus seuraa kulutusta: kerrostaloasunnossa energiaosuus on tyypillisesti noin 15–35 € kuukaudessa, rivitaloasunnossa noin 35–70 € ja sähkölämmitteisessä omakotitalossa noin 100–200 € kuukaudessa. Lämmityskuukausina lasku on selvästi vuosikeskiarvoa suurempi. Näiden päälle tulee sähkön siirto, joka laskutetaan erikseen verkkoyhtiön laskulla eikä muutu sopimusta vaihtamalla.',
            ],
        ];
    }

    /**
     * Title and description are tuned for CTR on the price-intent queries
     * ("sähkön hinta laskuri", "laske sähkön hinta", "kwh hinta laskuri"), where the
     * competing results are thin single-purpose calculators, rather than for the
     * consumption queries, where the SERP is held by utility brands. The brand suffix
     * is deliberately omitted: Google prints the site name beside the title anyway and
     * truncated the old "| Voltikka" off. Keep the year dynamic, never hardcoded.
     */
    protected function generateSeoTitle(): string
    {
        return 'Sähkön hinta laskuri '.now()->year.' – laske kulutus ja vuosihinta';
    }

    protected function generateMetaDescription(): string
    {
        return 'Paljonko sähkö maksaa vuodessa? Laske kulutuksesi kWh ja vuosihinta: '
            .'asunto, lämmitys, sauna, sähköauto. Vertaa hintaa eri sopimustyypeillä. Ilmainen.';
    }

    protected function generateCanonicalUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/sahkosopimus/laskuri';
    }

    public function generateJsonLd(): array
    {
        $canonical = $this->generateCanonicalUrl();
        $heading = $this->pageHeading;

        $faqEntities = array_map(fn (array $faq): array => [
            '@type' => 'Question',
            'name' => $faq['question'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $faq['answer'],
            ],
        ], $this->faqItems);

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'WebApplication',
                    '@id' => $canonical.'#webapp',
                    'name' => $heading,
                    'url' => $canonical,
                    'description' => $this->generateMetaDescription(),
                    'applicationCategory' => 'UtilitiesApplication',
                    'operatingSystem' => 'Any',
                    'inLanguage' => 'fi-FI',
                    'isAccessibleForFree' => true,
                    'offers' => [
                        '@type' => 'Offer',
                        'price' => '0',
                        'priceCurrency' => 'EUR',
                    ],
                    'provider' => [
                        '@type' => 'Organization',
                        'name' => 'Voltikka',
                        'url' => rtrim((string) config('app.url'), '/'),
                    ],
                ],
                [
                    '@type' => 'BreadcrumbList',
                    '@id' => $canonical.'#breadcrumbs',
                    'itemListElement' => [
                        [
                            '@type' => 'ListItem',
                            'position' => 1,
                            'name' => 'Etusivu',
                            'item' => rtrim((string) config('app.url'), '/').'/',
                        ],
                        [
                            '@type' => 'ListItem',
                            'position' => 2,
                            'name' => 'Sähkösopimus',
                            'item' => rtrim((string) config('app.url'), '/').'/sahkosopimus',
                        ],
                        [
                            '@type' => 'ListItem',
                            'position' => 3,
                            'name' => $heading,
                            'item' => $canonical,
                        ],
                    ],
                ],
                [
                    '@type' => 'FAQPage',
                    '@id' => $canonical.'#faq',
                    'mainEntity' => $faqEntities,
                ],
            ],
        ];
    }

    public function render()
    {
        return view('livewire.consumption-calculator')
            ->layout('layouts.app', [
                'title' => $this->generateSeoTitle(),
                'metaDescription' => $this->generateMetaDescription(),
                'canonical' => $this->generateCanonicalUrl(),
            ]);
    }
}
