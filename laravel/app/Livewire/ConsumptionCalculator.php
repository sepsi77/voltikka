<?php

namespace App\Livewire;

use App\Enums\BuildingEnergyRating;
use App\Enums\BuildingRegion;
use App\Enums\BuildingType;
use App\Enums\HeatingMethod;
use App\Enums\SupplementaryHeatingMethod;
use App\Models\ContractPriceDailyStatistic;
use App\Services\DTO\EnergyCalculatorRequest;
use App\Services\DTO\EnergyCalculatorResult;
use App\Services\EnergyCalculator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class ConsumptionCalculator extends Component
{
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
            externalHeating: !$this->includeHeating,
            externalHeatingWater: !$this->includeHeating,
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
        $latestDate = ContractPriceDailyStatistic::query()->max('stat_date');

        if (! $latestDate || $this->totalConsumption <= 0) {
            return [
                'date' => null,
                'rows' => [],
            ];
        }

        $segments = [
            'spot' => [
                'label' => 'Pörssisähkö',
                'description' => 'Toteutuneeseen pörssihintaan ja tyypilliseen marginaaliin perustuva arvio.',
                'energy_metric' => 'spot_total_energy_price',
                'use_annual_cost' => true,
            ],
            'fixed_term_12' => [
                'label' => 'Määräaikainen 12 kk',
                'description' => 'Vuodeksi lukittu kiinteä energiahinta.',
                'energy_metric' => 'energy_price',
                'use_annual_cost' => false,
            ],
            'fixed_term_24' => [
                'label' => 'Määräaikainen 24 kk',
                'description' => 'Kahdeksi vuodeksi lukittu kiinteä energiahinta.',
                'energy_metric' => 'energy_price',
                'use_annual_cost' => false,
            ],
            'open_ended' => [
                'label' => 'Toistaiseksi voimassa oleva',
                'description' => 'Jatkuva sopimus, jonka hintaa voidaan muuttaa ennakkoilmoituksella.',
                'energy_metric' => 'energy_price',
                'use_annual_cost' => false,
            ],
            'hybrid' => [
                'label' => 'Joustosähkö',
                'description' => 'Kiinteä energiahinta, johon voi tulla kulutusvaikutus.',
                'energy_metric' => 'energy_price',
                'use_annual_cost' => false,
            ],
        ];

        $segmentKeys = array_keys($segments);
        $statDate = substr((string) $latestDate, 0, 10);

        $stats = ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', $statDate)
            ->whereIn('segment_key', $segmentKeys)
            ->whereIn('metric_key', ['energy_price', 'spot_total_energy_price', 'monthly_fee', 'annual_cost'])
            ->get()
            ->groupBy(fn (ContractPriceDailyStatistic $row): string => $row->segment_key . ':' . $row->metric_key . ':' . ($row->consumption_kwh ?? ''));

        $rows = [];
        foreach ($segments as $segmentKey => $config) {
            $energy = $this->statValues($stats->get($segmentKey . ':' . $config['energy_metric'] . ':')?->first());
            $monthlyFee = $this->statValues($stats->get($segmentKey . ':monthly_fee:')?->first());

            if ($energy === null || $monthlyFee === null) {
                continue;
            }

            $costs = [];
            foreach (['p20', 'median', 'p80'] as $quantile) {
                $annual = null;

                if ($config['use_annual_cost']) {
                    $annual = $this->interpolatedAnnualCost($stats, $segmentKey, $quantile, $this->totalConsumption);
                } else {
                    $annual = $this->annualCostFromEnergyAndMonthlyFee(
                        $this->totalConsumption,
                        $energy[$quantile] ?? null,
                        $monthlyFee[$quantile] ?? null,
                    );
                }

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
                'energy' => $energy,
                'monthly_fee' => $monthlyFee,
                'costs' => $costs,
                'contract_count' => $stats->get($segmentKey . ':' . $config['energy_metric'] . ':')?->first()?->contract_count,
            ];
        }

        usort($rows, fn (array $a, array $b): int => ($a['costs']['median']['annual'] ?? PHP_FLOAT_MAX) <=> ($b['costs']['median']['annual'] ?? PHP_FLOAT_MAX));

        return [
            'date' => $statDate,
            'rows' => $rows,
        ];
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

    protected function annualCostFromEnergyAndMonthlyFee(int $consumption, ?float $energyCentsPerKwh, ?float $monthlyFee): ?float
    {
        if ($energyCentsPerKwh === null || $monthlyFee === null) {
            return null;
        }

        return ($consumption * $energyCentsPerKwh / 100) + ($monthlyFee * 12);
    }

    protected function interpolatedAnnualCost(Collection $stats, string $segmentKey, string $quantile, int $consumption): ?float
    {
        $points = [];
        foreach ([2000, 5000, 18000] as $level) {
            $row = $stats->get($segmentKey . ':annual_cost:' . $level)?->first();
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
            . 'Syötä asunnon koko, asukasmäärä ja lämmitystapa – laskuri laskee perussähkön, lämmityksen ja muut kulutuskohteet (sauna, sähköauto, lattialämmitys) erikseen. '
            . 'Kun tiedät vuosikulutuksesi kilowattitunteina, sähkön hinta laskuri arvioi vuosikustannuksen pörssisähköllä, määräaikaisilla ja toistaiseksi voimassa olevilla sopimuksilla Voltikan hintatilastojen perusteella.';
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
                'question' => 'Miten sähkön hinta lasketaan vuosikulutuksesta?',
                'answer' => 'Sähkön vuosikustannus lasketaan kertomalla vuosikulutus kilowattitunteina energian hinnalla ja lisäämällä sopimuksen perusmaksut: kulutus kWh × snt/kWh / 100 + kuukausimaksu × 12. Voltikan laskuri käyttää tähän hintatilastojen p20-, mediaani- ja p80-tasoja eri sopimustyypeille.',
            ],
            [
                'question' => 'Mikä sopimustyyppi on halvin omalla kulutuksella?',
                'answer' => 'Halvin sopimustyyppi riippuu kulutuksesta, kuukausimaksusta ja energian hinnasta. Pienellä kulutuksella perusmaksu korostuu, kun taas suurella kulutuksella pienikin ero senttiä per kilowattitunti -hinnassa vaikuttaa paljon vuosikustannukseen.',
            ],
        ];
    }

    protected function generateSeoTitle(): string
    {
        return 'Sähkönkulutuslaskuri – laske kulutus ja sähkön hinta | Voltikka';
    }

    protected function generateMetaDescription(): string
    {
        return 'Laske kotisi sähkönkulutus ja arvioi sähkön hinta vuodessa eri sopimustyypeillä. Sähkönkulutuslaskuri näyttää kulutuksen, vuosikustannuksen ja hintaerot.';
    }

    protected function generateCanonicalUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/sahkosopimus/laskuri';
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
                    '@id' => $canonical . '#webapp',
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
                    '@id' => $canonical . '#breadcrumbs',
                    'itemListElement' => [
                        [
                            '@type' => 'ListItem',
                            'position' => 1,
                            'name' => 'Etusivu',
                            'item' => rtrim((string) config('app.url'), '/') . '/',
                        ],
                        [
                            '@type' => 'ListItem',
                            'position' => 2,
                            'name' => 'Sähkösopimus',
                            'item' => rtrim((string) config('app.url'), '/') . '/sahkosopimus',
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
                    '@id' => $canonical . '#faq',
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
