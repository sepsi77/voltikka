<div>
    <script type="application/ld+json">{!! json_encode($this->generateJsonLd(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

    @php
        use App\Support\Season;

        // Profile homes in the board order (Paper 4UL-0 "Presets"). Decorative.
        $presetKeys = ['small_apartment', 'medium_apartment', 'medium_house_heat_pump', 'large_house_electric'];
        $presetArt = [
            'small_apartment' => 'tool-home-studio',
            'medium_apartment' => 'tool-home-apartment',
            'medium_house_heat_pump' => 'tool-home-house',
            'large_house_electric' => 'tool-home-rowhouse',
        ];

        $total = $this->totalConsumption;
        // Meter counter: at least five wheels; the wheels before the first digit are idle.
        $meterDigits = str_split(str_pad((string) max(0, $total), 5, '0', STR_PAD_LEFT));
        $meterLead = strlen(str_pad((string) max(0, $total), 5, '0', STR_PAD_LEFT)) - strlen((string) max(0, $total));

        // Result breakdown (same parts and labels as before the redesign).
        $breakdown = [];
        if (! empty($calculationResult)) {
            $breakdown[] = ['Perussähkö', (int) ($calculationResult['basic_living'] ?? 0)];
            foreach ([
                'heating_total' => 'Lämmitys',
                'sauna' => 'Sauna',
                'electricity_vehicle' => 'Sähköauto',
                'bathroom_underfloor_heating' => 'Lattialämmitys',
                'cooling' => 'Ilmastointi',
            ] as $key => $label) {
                if (! empty($calculationResult[$key])) {
                    $breakdown[] = [$label, (int) $calculationResult[$key]];
                }
            }
        }
        $breakdownSum = array_sum(array_column($breakdown, 1));
        // Fill of each stacked-bar part: fox and lake (a chart with no pricing categories,
        // spec §5 "Series colours"), in falling strength, with inked dividers.
        $partFill = [
            'background-color: var(--color-fox); opacity: .8',
            'background-color: var(--color-lake); opacity: .55',
            'background-color: var(--color-fox); opacity: .4',
            'background-color: var(--color-lake); opacity: .3',
            'background-color: var(--color-fox); opacity: .2',
            'background-color: var(--color-lake); opacity: .15',
        ];

        $radioOn = '<svg class="vk-mark tool-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9.5" style="fill: var(--color-fox)" /><path d="M6.5 11.5 L9.6 14.3 L15.6 7.8" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>';
        $radioOff = '<svg class="vk-mark tool-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2" /></svg>';
    @endphp

    {{-- Picture-book hero (Paper board 4UL-0 "Meter hero"): the fox reads the electricity meter. --}}
    <x-site.tool-hero art="laskuri-hero" :tone="Season::heroTone('laskuri-hero')">
        <nav class="tool-hero-crumbs" aria-label="Murupolku">
            <ol>
                <li><a href="/" class="vk-focus">Etusivu</a></li>
                <li aria-hidden="true">›</li>
                <li><a href="/sahkosopimus" class="vk-focus">Sähkösopimus</a></li>
                <li aria-hidden="true">›</li>
                <li aria-current="page">{{ $this->pageHeading }}</li>
            </ol>
        </nav>
        {{-- Soft hyphen: the long compound word must break on a phone. --}}
        <h1 class="tool-hero-h1">{!! str_replace('kulutuslaskuri', 'kulutus&shy;laskuri', e($this->pageHeading)) !!}</h1>
        <p class="tool-hero-lead">{{ $this->pageTagline }}</p>
    </x-site.tool-hero>

    <x-page-action-strip />

    <div class="vk-wrap tool-page">

    <p class="tool-intro">{{ $this->seoIntroText }}</p>

    {{-- Calculator (Paper "Calculator"): inputs at the left, the meter readout at the right. --}}
    <section class="tool-calc" aria-label="Sähkönkulutuslaskuri">
        <div class="tool-calc-inputs">
            {{-- Preset profiles --}}
            <div class="flex flex-col gap-3">
                <h2 class="tool-h3" id="calc-presets-label">Valitse valmis profiili</h2>
                <div class="tool-presets" role="group" aria-labelledby="calc-presets-label">
                    @foreach ($presetKeys as $key)
                        @php $isSel = $selectedPreset === $key; @endphp
                        <button
                            type="button"
                            wire:click="selectPreset('{{ $key }}')"
                            aria-pressed="{{ $isSel ? 'true' : 'false' }}"
                            class="tool-preset"
                        >
                            <img src="{{ Season::art($presetArt[$key]) }}" alt="" width="84" height="84" class="tool-preset-art" loading="lazy" decoding="async">
                            <span class="tool-preset-name">
                                {!! $isSel ? $radioOn : $radioOff !!}
                                {{ $presets[$key]['label'] }}
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Building type, size and residents --}}
            <div class="flex flex-col gap-[18px]">
                <h2 class="tool-h3" id="calc-building-type-label">Asuntotyyppi</h2>
                <div class="tool-radios" role="group" aria-labelledby="calc-building-type-label">
                    @foreach ($buildingTypeLabels as $type => $label)
                        @php $isType = $buildingType === $type; @endphp
                        <button
                            type="button"
                            wire:click="selectBuildingType('{{ $type }}')"
                            aria-pressed="{{ $isType ? 'true' : 'false' }}"
                            class="tool-radio"
                        >
                            {!! $isType ? $radioOn : $radioOff !!}
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <div class="tool-field-pair">
                    <div class="tool-field-wrap">
                        <label for="living-area" class="tool-label">Asuinpinta-ala (m²)</label>
                        <div class="tool-field tool-field--big">
                            <input
                                type="number"
                                id="living-area"
                                wire:model.live.blur="livingArea"
                                min="20"
                                max="500"
                                inputmode="numeric"
                                @if (isset($numericNotices['livingArea'])) aria-invalid="true" aria-describedby="living-area-notice" @endif
                            >
                            <span class="tool-unit" aria-hidden="true">m²</span>
                        </div>
                        @if (isset($numericNotices['livingArea']))
                            <p id="living-area-notice" role="alert" class="vk-notice mt-1">{{ $numericNotices['livingArea'] }}</p>
                        @endif
                    </div>
                    <div class="tool-field-wrap">
                        <label for="num-people" class="tool-label">Asukkaiden lukumäärä</label>
                        <div class="tool-field tool-field--big">
                            <input
                                type="number"
                                id="num-people"
                                wire:model.live.blur="numPeople"
                                min="1"
                                max="10"
                                inputmode="numeric"
                                @if (isset($numericNotices['numPeople'])) aria-invalid="true" aria-describedby="num-people-notice" @endif
                            >
                            <span class="tool-unit" aria-hidden="true">hlö</span>
                        </div>
                        @if (isset($numericNotices['numPeople']))
                            <p id="num-people-notice" role="alert" class="vk-notice mt-1">{{ $numericNotices['numPeople'] }}</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Heating switch --}}
            <div class="tool-switch-row">
                <div class="flex min-w-0 flex-col gap-0.5">
                    <h2 class="tool-h3" id="calc-heating-label">Sisällytä lämmitys</h2>
                    <p class="text-[15px] leading-5 text-ink-body" id="calc-heating-desc">Laske myös sähkölämmityksen kulutus</p>
                </div>
                <button
                    type="button"
                    role="switch"
                    wire:click="toggleIncludeHeating"
                    aria-checked="{{ $includeHeating ? 'true' : 'false' }}"
                    aria-labelledby="calc-heating-label"
                    aria-describedby="calc-heating-desc"
                    class="tool-switch"
                >
                    <span class="tool-switch-state" aria-hidden="true">{{ $includeHeating ? 'Päällä' : 'Pois' }}</span>
                    <svg width="54" height="30" viewBox="0 0 54 30" aria-hidden="true" focusable="false" class="shrink-0">
                        <path d="M15 3.5 C 24 2.8, 32 3.2, 39 3.4 C 47 3.8, 51.5 9, 51 15.2 C 50.6 22, 46 26.6, 39 26.4 C 30 26.1, 22 26.6, 15 26.5 C 7.5 26.3, 2.6 21.4, 3 14.6 C 3.4 8.2, 8 3.8, 15 3.5 Z" style="fill: {{ $includeHeating ? 'var(--color-fox)' : 'var(--tool-switch-off)' }}; stroke: var(--color-ink)" stroke-width="1.6" />
                        <circle cx="{{ $includeHeating ? '38.5' : '15.5' }}" cy="15" r="8.5" style="fill: var(--color-snow-white); stroke: var(--color-ink)" stroke-width="1.6" />
                    </svg>
                </button>
            </div>

            {{-- Heating options (shown when includeHeating is true) --}}
            @if ($includeHeating)
                <div class="flex flex-col gap-4">
                    <h2 class="tool-h3">Lämmitysasetukset</h2>
                    <div class="grid grid-cols-1 gap-x-10 gap-y-5 sm:grid-cols-2">
                        <div>
                            <label for="heating-method" class="tool-label">Päälämmitysmuoto</label>
                            <select id="heating-method" wire:model.live.blur="heatingMethod" class="vk-select tool-select">
                                @foreach ($heatingMethodLabels as $method => $label)
                                    <option value="{{ $method }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="building-region" class="tool-label">Sijainti</label>
                            <select id="building-region" wire:model.live.blur="buildingRegion" class="vk-select tool-select">
                                @foreach ($buildingRegionLabels as $region => $label)
                                    <option value="{{ $region }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="energy-efficiency" class="tool-label">Rakennusvuosi / energiatehokkuus</label>
                            <select id="energy-efficiency" wire:model.live.blur="buildingEnergyEfficiency" class="vk-select tool-select">
                                @foreach ($buildingEnergyEfficiencyLabels as $rating => $label)
                                    <option value="{{ $rating }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="supplementary-heating" class="tool-label">Lisälämmitys (valinnainen)</label>
                            <select id="supplementary-heating" wire:model.live.blur="supplementaryHeating" class="vk-select tool-select">
                                <option value="">Ei lisälämmitystä</option>
                                @foreach ($supplementaryHeatingLabels as $method => $label)
                                    <option value="{{ $method }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Extras --}}
            <div class="flex flex-col gap-4">
                <h2 class="tool-h3">Lisävarusteet</h2>
                <div class="tool-extras">
                    <div class="tool-field-wrap">
                        <label for="bathroom-area" class="tool-label">Lattialämmitys</label>
                        <div class="tool-field">
                            <input
                                type="number"
                                id="bathroom-area"
                                wire:model.live.blur="bathroomHeatingArea"
                                min="0"
                                max="50"
                                placeholder="0"
                                inputmode="numeric"
                                @if (isset($numericNotices['bathroomHeatingArea'])) aria-invalid="true" aria-describedby="bathroom-area-notice" @endif
                            >
                            <span class="tool-unit">m²</span>
                        </div>
                        @if (isset($numericNotices['bathroomHeatingArea']))
                            <p id="bathroom-area-notice" role="alert" class="vk-notice mt-1">{{ $numericNotices['bathroomHeatingArea'] }}</p>
                        @endif
                    </div>
                    <div class="tool-field-wrap">
                        <label for="sauna-usage" class="tool-label">Sauna</label>
                        <div class="tool-field">
                            <input
                                type="number"
                                id="sauna-usage"
                                wire:model.live.blur="saunaUsagePerWeek"
                                min="0"
                                max="14"
                                placeholder="0"
                                inputmode="numeric"
                                @if (isset($numericNotices['saunaUsagePerWeek'])) aria-invalid="true" aria-describedby="sauna-usage-notice" @endif
                            >
                            <span class="tool-unit">krt/viikko</span>
                        </div>
                        @if (isset($numericNotices['saunaUsagePerWeek']))
                            <p id="sauna-usage-notice" role="alert" class="vk-notice mt-1">{{ $numericNotices['saunaUsagePerWeek'] }}</p>
                        @endif
                    </div>
                    <div class="tool-field-wrap">
                        <label for="ev-kms" class="tool-label">Sähköauto</label>
                        <div class="tool-field">
                            <input
                                type="number"
                                id="ev-kms"
                                wire:model.live.blur="electricVehicleKmsPerMonth"
                                min="0"
                                max="5000"
                                placeholder="0"
                                inputmode="numeric"
                                @if (isset($numericNotices['electricVehicleKmsPerMonth'])) aria-invalid="true" aria-describedby="ev-kms-notice" @endif
                            >
                            <span class="tool-unit">km/kk</span>
                        </div>
                        @if (isset($numericNotices['electricVehicleKmsPerMonth']))
                            <p id="ev-kms-notice" role="alert" class="vk-notice mt-1">{{ $numericNotices['electricVehicleKmsPerMonth'] }}</p>
                        @endif
                    </div>
                </div>

                {{-- Cooling: a checkbox look on the existing toggle action. --}}
                <button
                    type="button"
                    id="cooling-toggle"
                    role="checkbox"
                    wire:click="toggleCooling"
                    aria-checked="{{ $cooling ? 'true' : 'false' }}"
                    class="tool-check"
                >
                    @if ($cooling)
                        <svg class="vk-mark tool-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><rect x="2" y="2" width="18" height="18" rx="5" style="fill: var(--color-fox)" /><path d="M6.5 11.2l3 3 6-6.4" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    @else
                        <svg class="vk-mark tool-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><rect x="2" y="2" width="18" height="18" rx="5" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2" /></svg>
                    @endif
                    <span class="font-extrabold text-ink">Ilmastointi</span>
                    <span class="font-normal text-ink-muted">+240 kWh/vuosi</span>
                </button>
            </div>
        </div>

        {{-- Meter readout (Paper "Meter readout"): the result as a meter counter on the page. --}}
        <div class="tool-readout">
            <div class="flex flex-col gap-3">
                <p class="vk-kicker">Arvioitu vuosikulutus</p>
                <div class="flex items-end gap-3">
                    <p class="sr-only">{{ number_format($total, 0, ',', ' ') }} kWh</p>
                    <div class="tool-meter" aria-hidden="true">
                        @foreach ($meterDigits as $i => $digit)
                            <span @class([
                                'tool-meter-wheel',
                                'tool-meter-wheel--idle' => $i < $meterLead,
                                'tool-meter-wheel--last' => $loop->last,
                            ])>{{ $digit }}</span>
                        @endforeach
                    </div>
                    <span class="tool-meter-unit" aria-hidden="true">kWh</span>
                </div>
            </div>

            @if (! empty($breakdown))
                <div class="flex flex-col gap-2">
                    <dl class="flex flex-col gap-2">
                        @foreach ($breakdown as $i => [$label, $kwh])
                            <div class="flex items-baseline gap-1.5">
                                <dt class="flex items-baseline gap-2 text-[15px] leading-[18px] text-ink-body">
                                    @if (count($breakdown) > 1)
                                        <span class="tool-swatch" style="{{ $partFill[$i] }}" aria-hidden="true"></span>
                                    @endif
                                    {{ $label }}
                                </dt>
                                <span class="vk-leader" aria-hidden="true"></span>
                                <dd class="text-[15px] font-extrabold leading-[18px] text-ink tabular-nums">{{ number_format($kwh, 0, ',', ' ') }} kWh</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if ($breakdownSum > 0)
                        <div class="tool-stack" aria-hidden="true">
                            @foreach ($breakdown as $i => [$label, $kwh])
                                @if ($kwh > 0)
                                    <span class="tool-stack-part" style="width: {{ round($kwh / $breakdownSum * 100, 2) }}%"><span style="{{ $partFill[$i] }}"></span></span>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <p class="text-[13px] leading-5 text-ink-muted">
                Arvio perustuu tilastollisiin keskiarvoihin (mm. 400 kWh/asukas + 30 kWh/m² vuodessa sekä lämmitystavan ja rakennusvuoden mukainen kerroin), eikä se ole toteutunut sähkölasku. Todellinen kulutus vaihtelee asumistottumusten, kodinkoneiden ja sään mukaan.
            </p>

            <div class="flex flex-col gap-3.5 pt-1">
                @error('comparisonConsumption')
                    <p role="alert" class="vk-notice">{{ $message }}</p>
                @enderror

                <button type="button" wire:click="compareContracts" class="vk-action tool-action vk-focus">
                    <span>Vertaile sähkösopimuksia →</span>
                    <x-ink.wave width="290" stroke="3" />
                </button>

                @if ($this->nearestConsumptionPage)
                    <p class="text-[15px] leading-[22px] text-ink-body">
                        Lähin vakiokulutustaso:
                        <a href="{{ $this->nearestConsumptionPage['url'] }}" class="vk-text-link vk-focus">{{ $this->nearestConsumptionPage['label'] }} sähkön hinta</a>
                    </p>
                @endif
            </div>
        </div>
    </section>

    {{-- "Löysitkö etsimäsi?" under the calculator result. --}}
    @if (! empty($calculationResult))
        <x-page-feedback page-type="consumption_calculator" />
    @endif

    <x-site.spot-paw-divider />

    @php
        $priceEstimates = $this->contractTypePriceEstimates;
    @endphp
    @if (!empty($priceEstimates['rows']))
        @php
            // Contract type → pricing category glyph and colour (spec §2 "Map colours").
            $segmentBucket = [
                'spot' => ['porssisahko', 'var(--color-cat-spot)'],
                'market_reset' => ['paivittyva', 'var(--color-cat-periodic)'],
                'hybrid' => ['kulutusvaikutus', 'var(--color-cat-usage)'],
            ];
            $fixedBucket = ['kiintea', 'var(--color-cat-fixed)'];

            // Shared axis of the p20–p80 range bars: two to four "nice" steps.
            $values = [];
            foreach ($priceEstimates['rows'] as $row) {
                foreach (['p20', 'median', 'p80'] as $q) {
                    if (! empty($row['costs'][$q])) {
                        $values[] = $row['costs'][$q]['annual'];
                    }
                }
            }
            $axisMin = min($values);
            $axisMax = max($values);
            $axisStep = 10;
            foreach ([10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 2500, 5000, 10000] as $step) {
                $axisStep = $step;
                if (ceil($axisMax / $step) - floor($axisMin / $step) <= 3) {
                    break;
                }
            }
            $axisLo = floor($axisMin / $axisStep) * $axisStep;
            $axisHi = max($axisLo + $axisStep, ceil($axisMax / $axisStep) * $axisStep);
            $axisTicks = range($axisLo, $axisHi, $axisStep);
            $axisPos = fn (float $v): float => round(($v - $axisLo) / ($axisHi - $axisLo) * 100, 2);
        @endphp
        <section class="tool-section" aria-labelledby="price-estimates-title">
            <div class="flex flex-col gap-1">
                <p class="vk-kicker">Sähkön hinta laskuri</p>
                <x-site.section-heading id="price-estimates-title" :wave="380">Sähkön hinta eri sopimustyypeillä</x-site.section-heading>
            </div>
            <p class="tool-intro">
                Kun tiedät vuosikulutuksesi, voit arvioida sähkön hinnan eri sopimustyypeillä.
                Alla oleva laskuri käyttää Voltikan hintatilastoja ja näyttää, mitä
                {{ number_format($this->totalConsumption, 0, ',', ' ') }} kWh vuosikulutus maksaisi
                tyypillisellä edullisella, mediaanihintaisella ja kalliimmalla sopimuksella.
            </p>

            <div class="tool-price-table">
                <div class="tool-price-head" aria-hidden="true">
                    <span class="tool-price-head-name">Sopimustyyppi</span>
                    <span class="tool-price-axis">
                        @foreach ($axisTicks as $tick)
                            <span class="tool-price-tick" style="left: {{ $axisPos($tick) }}%">{{ number_format($tick, 0, ',', ' ') }} €</span>
                        @endforeach
                    </span>
                    <span class="tool-price-num tool-price-q tool-price-q--low">Edullinen p20</span>
                    <span class="tool-price-num tool-price-q">Mediaani</span>
                    <span class="tool-price-num tool-price-q tool-price-q--high">Kalliimpi p80</span>
                </div>
                <ul class="tool-price-rows">
                    @foreach ($priceEstimates['rows'] as $row)
                        @php
                            [$glyph, $colour] = $segmentBucket[$row['key']] ?? $fixedBucket;
                            $lo = $row['costs']['p20']['annual'] ?? null;
                            $mid = $row['costs']['median']['annual'];
                            $hi = $row['costs']['p80']['annual'] ?? null;
                        @endphp
                        <li class="tool-price-row">
                            <div class="tool-price-name">
                                <x-ink.glyph :bucket="$glyph" size="44" />
                                <div class="flex min-w-0 flex-col gap-0.5">
                                    <span class="text-[17px] font-extrabold leading-[22px]" style="color: {{ $colour }}">{{ $row['label'] }}</span>
                                    <span class="text-sm leading-[19px] text-ink-muted">{{ $row['description'] }}</span>
                                </div>
                            </div>
                            <div class="tool-price-axis tool-price-bar" aria-hidden="true">
                                @foreach ($axisTicks as $tick)
                                    <span class="tool-price-gridline" style="left: {{ $axisPos($tick) }}%"></span>
                                @endforeach
                                @if ($lo !== null && $hi !== null)
                                    <span class="tool-range" style="left: {{ $axisPos($lo) }}%; width: {{ max(0.5, $axisPos($hi) - $axisPos($lo)) }}%">
                                        <span class="tool-range-fill" style="background-color: {{ $colour }}"></span>
                                    </span>
                                @endif
                                <span class="tool-range-median" style="left: {{ $axisPos($mid) }}%"></span>
                            </div>
                            @foreach (['p20' => 'Edullinen p20', 'median' => 'Mediaani', 'p80' => 'Kalliimpi p80'] as $quantile => $quantileLabel)
                                <div class="tool-price-num">
                                    <span class="tool-price-num-label">{{ $quantileLabel }}</span>
                                    @if (!empty($row['costs'][$quantile]))
                                        <span class="tool-price-annual">{{ number_format($row['costs'][$quantile]['annual'], 0, ',', ' ') }} €/v</span>
                                        <span class="text-[13px] leading-4 text-ink-muted">{{ number_format($row['costs'][$quantile]['monthly'], 0, ',', ' ') }} €/kk</span>
                                    @else
                                        <span class="text-ink-muted">–</span>
                                    @endif
                                </div>
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="tool-two-col pt-2.5">
                <div class="flex flex-col gap-1.5 lg:w-[560px] lg:shrink-0">
                    <h3 class="text-base font-extrabold leading-[22px] text-ink">Miten hinta lasketaan?</h3>
                    <p class="text-[15px] leading-6 text-ink-body">
                        Kaikki taulukon luvut tulevat tilastojen valmiista vuosikustannuksista. Nykyiset luvut
                        käyttävät samaa kanonista 12 kuukauden laskelmaa kuin sopimusvertailu, joten tarjoukset,
                        hintavaiheet ja kuukausittaiset energiapaketit säilyvät mukana. Puuttuvaa vuosihintaa
                        ei rakenneta energiahinnasta ja perusmaksusta.
                    </p>
                </div>
                <div class="flex flex-col gap-2.5">
                    <h3 class="font-display text-[22px] font-extrabold leading-7 text-ink">Tiedä sähkön hinta omalla kulutuksella</h3>
                    <p class="text-[15px] leading-6 text-ink-body">
                        Arviot perustuvat Voltikan keräämiin sähkösopimusten hintatilastoihin
                        @if (!empty($priceEstimates['date']))
                            (päivitetty {{ \Carbon\Carbon::parse($priceEstimates['date'])->format('d.m.Y') }})
                        @endif
                        . Todellinen hinta riippuu valitusta sopimuksesta, kampanjoista ja pörssisähkön toteutuneesta hinnasta.
                    </p>
                    <div class="flex flex-wrap items-end gap-x-8 gap-y-3 pt-1">
                        <button type="button" wire:click="compareContracts" class="vk-action tool-action tool-action--md vk-focus">
                            <span>Vertaa sopimukset omalla kulutuksella →</span>
                            <x-ink.wave width="full" stroke="3" />
                        </button>
                        <a href="/sahkosopimus/tilastot" class="tool-plain-link vk-focus pb-3">Katso sähkön hintatilastot</a>
                    </div>
                </div>
            </div>
        </section>
    @endif

    <x-site.interlude art="laskuri-interlude" />

    {{-- How the calculator works (Paper "Miten laskuri toimii"). --}}
    <section class="tool-two-col" aria-labelledby="calc-how-title">
        <div class="flex flex-col gap-4 lg:w-[680px] lg:shrink-0">
            <x-site.section-heading id="calc-how-title" :wave="360">Miten sähkönkulutuslaskuri toimii?</x-site.section-heading>
            <p class="tool-intro">
                Voltikan sähkönkulutuslaskuri laskee arvion vuosikulutuksestasi neljästä osasta: perussähköstä,
                mahdollisesta sähkölämmityksestä, käyttöveden lämmityksestä sekä muista kulutuskohteista.
                Pohjana on tilastollinen kaava, jota tarkennetaan syöttämiesi tietojen mukaan.
            </p>
        </div>
        <ol class="tool-steps">
            <li><span class="tool-step-no" aria-hidden="true">1</span><p><strong>Perussähkö:</strong> 400 kWh asukasta kohti vuodessa + 30 kWh asuinneliötä kohti.
                Tämä kattaa valaistuksen, kodinkoneet, viihde-elektroniikan ja muun jokapäiväisen kulutuksen.</p></li>
            <li><span class="tool-step-no" aria-hidden="true">2</span><p><strong>Lämmityksen sähkönkulutus:</strong> riippuu asunnon koosta, sijainnista
                (etelä, keski- vai pohjois-Suomi), rakennusvuodesta ja lämmitystavasta. Suora sähkölämmitys
                käyttää sähköä 1:1, ilma-vesilämpöpumppu noin 2,2× tehokkaammin ja maalämpö noin 2,9× tehokkaammin.</p></li>
            <li><span class="tool-step-no" aria-hidden="true">3</span><p><strong>Käyttöveden lämmitys:</strong> noin 120 litraa vettä asukasta kohden päivässä, josta
                40 % on lämmintä. Sähkölämmitteisessä talossa tämä on osa kokonaiskulutusta.</p></li>
            <li><span class="tool-step-no" aria-hidden="true">4</span><p><strong>Muut kulutuskohteet:</strong> sauna noin 7,5 kWh/lämmityskerta, sähköauto noin 0,2 kWh/km,
                kylpyhuoneen lattialämmitys noin 200 kWh/m²/vuosi ja ilmastointi noin 240 kWh/vuosi.</p></li>
        </ol>
    </section>

    {{-- Manual calculation (Paper "Laskeminen itse"). --}}
    <section class="flex flex-col gap-[26px]" aria-labelledby="calc-manual-title">
        <div class="flex max-w-[820px] flex-col gap-3.5">
            <x-site.section-heading id="calc-manual-title" :wave="360">Sähkön kulutuksen laskeminen itse</x-site.section-heading>
            <p class="tool-intro">
                Voit toistaa saman laskelman myös käsin – sähkönkulutuslaskuri käyttää tilastollista kaavaa,
                joka jakautuu neljään osaan: <strong>perussähkö</strong>, <strong>lämmitys</strong>,
                <strong>käyttöveden lämmitys</strong> ja <strong>muut kulutuskohteet</strong>. Käytämme
                esimerkkinä 120 m² omakotitaloa Keski-Suomessa: 4 asukasta, rakennettu 2000-luvulla,
                suora sähkölämmitys.
            </p>
        </div>

        <div class="flex max-w-[820px] flex-col gap-2.5 pt-1.5">
            <h3 class="tool-h3 tool-h3--lg">1. Perussähkö (valaistus, kodinkoneet, elektroniikka)</h3>
            <div class="tool-formula">
                <p class="tool-formula-text">Perussähkö = asukkaat × 400 kWh + asuinpinta-ala × 30 kWh</p>
                <p>Esimerkkitaloutemme: 4 × 400 + 120 × 30 = 1 600 + 3 600 = <strong>5 200 kWh/v</strong>.</p>
            </div>
        </div>

        <div class="tool-two-col pt-1.5">
            <div class="tool-prose lg:w-[600px] lg:shrink-0">
                <h3 class="tool-h3 tool-h3--lg">2. Lämmityksen sähkönkulutus</h3>
                <p>
                    Lämmityksen energiantarve riippuu lämmitettävästä tilavuudesta sekä sijainnin ja
                    rakennusvuoden mukaan määräytyvästä lämmityskertoimesta. Kaava:
                </p>
                <div class="tool-formula">
                    <p class="tool-formula-text">Lämmitysenergia = asuinpinta-ala × 2,6 m × lämmityskerroin (kWh/m³)</p>
                </div>
                <p>
                    Esimerkki: lämmitystilavuus 120 × 2,6 = 312 m³. Keski-Suomi + 2000-luku → kerroin
                    51 kWh/m³. Lämmitysenergian tarve = 312 × 51 ≈ <strong>15 900 kWh</strong>.
                </p>
                <p>
                    Lopullinen sähkönkulutus riippuu lämmitystavan hyötysuhteesta: jaa lämmitysenergia
                    hyötysuhteella.
                </p>
                <ul class="flex flex-col">
                    @foreach ([
                        ['Suora sähkölämmitys (η = 1,0): 15 900 / 1,0', '15 900 kWh'],
                        ['Ilma-vesilämpöpumppu (η = 2,2): 15 900 / 2,2', '≈ 7 230 kWh'],
                        ['Maalämpö (η = 2,9): 15 900 / 2,9', '≈ 5 480 kWh'],
                    ] as [$label, $value])
                        <li class="flex items-baseline gap-1.5 py-2">
                            <span class="text-[15px] leading-[18px] text-ink-body">{{ $label }}</span>
                            <span class="vk-leader" aria-hidden="true"></span>
                            <strong class="whitespace-nowrap text-[15px] font-extrabold leading-[18px] text-ink">{{ $value }}</strong>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="min-w-0 lg:w-[576px] lg:shrink-0">
                <p class="pb-2 text-sm font-bold leading-5 text-ink-body">Käytä alla olevaa taulukkoa lämmityskertoimen valintaan (kWh/m³/vuosi):</p>
                <div class="overflow-x-auto">
                    <table class="tool-table">
                        <thead>
                            <tr>
                                <th scope="col">Rakennuskausi</th>
                                <th scope="col" class="text-right">Etelä-Suomi</th>
                                <th scope="col" class="text-right">Keski-Suomi</th>
                                <th scope="col" class="text-right">Pohjois-Suomi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ([
                                ['Passiivitalo', 8, 10, 12],
                                ['Matalaenergiatalo', 19, 24, 31],
                                ['2010-luku', 32, 39, 49],
                                ['2000-luku', 42, 51, 65],
                                ['1990-luku', 47, 57, 73],
                                ['1980-luku', 55, 66, 84],
                                ['1970-luku', 56, 68, 86],
                                ['1960-luku', 61, 74, 93],
                                ['Vanhempi', 65, 78, 99],
                            ] as [$period, $south, $central, $north])
                                @php $isExample = $period === '2000-luku'; @endphp
                                <tr @class(['tool-table-example' => $isExample])>
                                    <th scope="row">{{ $period }}</th>
                                    <td>{{ $south }}</td>
                                    <td>
                                        @if ($isExample)
                                            {{-- The example's coefficient, circled in fox ink. --}}
                                            <span class="tool-loop">{{ $central }}<svg width="46" height="30" viewBox="0 0 46 30" aria-hidden="true" focusable="false"><path d="M36 5 C 26 0, 10 1, 4 9 C -1 17, 6 27, 22 27 C 36 27, 45 20, 43 12 C 41 6, 34 3, 27 4" fill="none" style="stroke: var(--color-fox)" stroke-width="2" stroke-linecap="round" /></svg></span>
                                        @else
                                            {{ $central }}
                                        @endif
                                    </td>
                                    <td>{{ $north }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="pt-2 text-[13px] leading-5 text-ink-muted">Lähde: lammitysvertailu.eneuvonta.fi</p>
            </div>
        </div>

        <div class="tool-two-col pt-1.5">
            <div class="tool-prose lg:w-[600px] lg:shrink-0">
                <h3 class="tool-h3 tool-h3--lg">3. Käyttöveden lämmitys</h3>
                <p>
                    Sähkölämmitteisessä talossa myös käyttöveden lämmitys kuluttaa sähköä. Oletuksena
                    henkilö käyttää 120 litraa vettä päivässä, josta 40 % on lämmintä, ja 1 °C → 58 °C
                    lämmitykseen tarvitaan 58 °C × 1 kWh/(°C·m³).
                </p>
                <div class="tool-formula">
                    <p class="tool-formula-text">Käyttövesi = asukkaat × 120 l × 0,4 × 365 × 58 / 1000</p>
                    <p>
                        Esimerkki: 4 × 120 × 0,4 × 365 × 58 / 1000 ≈ <strong>4 070 kWh/v</strong>
                        (lisäksi varaajan energiahäviö noin 600–1 000 kWh vuodessa).
                    </p>
                </div>
            </div>
            <div class="tool-prose min-w-0 lg:w-[576px] lg:shrink-0">
                <h3 class="tool-h3 tool-h3--lg">4. Muut kulutuskohteet</h3>
                <p>Laske mukaan jokainen lisäkulutus erikseen:</p>
                <ul class="tool-rule-list">
                    <li><strong>Sauna:</strong> käyttökerrat/viikko × 7,5 kWh × 52 viikkoa.
                        <span>Esim. 2 kertaa viikossa: 2 × 7,5 × 52 = <strong>780 kWh/v</strong>.
                        Jatkuvalämmitteinen kiuas n. 2 750 kWh/v.</span></li>
                    <li><strong>Sähköauto:</strong> km/kuukausi × 0,199 kWh × 12 kuukautta.
                        <span>Esim. 1 500 km/kk: 1 500 × 0,199 × 12 ≈ <strong>3 580 kWh/v</strong>.</span></li>
                    <li><strong>Kylpyhuoneen lattialämmitys:</strong> lämmitetyt neliöt × 200 kWh.
                        <span>Esim. 5 m²: 5 × 200 = <strong>1 000 kWh/v</strong>.</span></li>
                    <li><strong>Ilmastointi:</strong> kiinteä noin <strong>240 kWh/v</strong>.</li>
                </ul>
            </div>
        </div>

        <div class="tool-two-col pt-2.5">
            {{-- Example total as a receipt: dotted leaders and a double rule above the sum. --}}
            <div class="flex flex-col gap-2.5 lg:w-[600px] lg:shrink-0">
                <h3 class="tool-h3 tool-h3--lg">Esimerkin yhteenlasku</h3>
                <table class="tool-receipt">
                    <thead>
                        <tr><th scope="col">Erä</th><th scope="col">Sähkönkulutus</th></tr>
                    </thead>
                    <tbody>
                        @foreach ([
                            ['Perussähkö (4 hlö, 120 m²)', '5 200 kWh'],
                            ['Lämmitys (suora sähkö, 2000-luku, Keski-Suomi)', '15 900 kWh'],
                            ['Käyttövesi (4 hlö)', '4 700 kWh'],
                            ['Sauna (2 × viikossa)', '780 kWh'],
                        ] as [$item, $kwh])
                            <tr>
                                <th scope="row"><span class="tool-receipt-item"><span>{{ $item }}</span><span class="vk-leader" aria-hidden="true"></span></span></th>
                                <td>{{ $kwh }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr><th scope="row">Yhteensä</th><td>≈ 26 600 kWh/v</td></tr>
                    </tfoot>
                </table>
                <p class="pt-1.5 text-[15px] leading-6 text-ink-body">
                    Jos sama talo lämmitettäisiin ilma-vesilämpöpumpulla, lämmityserä putoaisi noin
                    7 230 kWh:iin ja kokonaiskulutus noin 18 000 kWh:iin vuodessa. Maalämmöllä
                    kokonaiskulutus jäisi noin 16 200 kWh:iin.
                </p>
            </div>

            {{-- Typical consumption ranges as range bars on one kWh axis (0–28 700). --}}
            <div class="flex min-w-0 flex-col gap-2.5 lg:w-[576px] lg:shrink-0">
                <h3 class="tool-h3 tool-h3--lg">Vertaa tulosta tilastollisiin keskiarvoihin</h3>
                <p class="text-[15px] leading-6 text-ink-body">
                    Kun olet saanut oman lukusi, vertaa sitä saman kokoluokan kotitalouksien tyypilliseen
                    kulutukseen. Jos arviosi poikkeaa selvästi alla olevasta haarukasta, suurin selittäjä
                    on yleensä lämmitystapa tai rakennuksen eristystaso – tarkista ne ensimmäisenä.
                </p>
                <table class="tool-ranges">
                    <thead>
                        <tr>
                            <th scope="col">Asuntotyyppi</th>
                            <th scope="col" class="text-right">Tyypillinen vuosikulutus</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([
                            ['Kerrostaloasunto', '2 000–5 000 kWh', 2000, 5000, false],
                            ['Rivitaloasunto', '5 000–9 000 kWh', 5000, 9000, false],
                            ['Omakotitalo ilman sähkölämmitystä', '5 000–10 000 kWh', 5000, 10000, false],
                            ['Sähkölämmitteinen omakotitalo', '15 000–25 000 kWh', 15000, 25000, true],
                        ] as [$homeType, $range, $from, $to, $star])
                            @php
                                $x1 = round(8 + $from * 0.01073, 1);
                                $x2 = round(8 + $to * 0.01073, 1);
                            @endphp
                            <tr>
                                <th scope="row">
                                    <span class="block text-[15px] font-bold leading-[18px] text-ink">{{ $homeType }}</span>
                                    <span class="block text-sm leading-[18px] text-ink-muted">{{ $range }}</span>
                                </th>
                                <td>
                                    <svg viewBox="0 0 316 26" class="tool-ranges-bar" aria-hidden="true" focusable="false">
                                        <path d="M115.1 3 V23 M222.3 3 V23" fill="none" style="stroke: var(--color-edge)" stroke-dasharray="2 3" />
                                        <path d="M{{ $x1 }} 13 L{{ $x2 }} 13" fill="none" style="stroke: var(--color-lake); opacity: .45" stroke-width="10" stroke-linecap="round" />
                                        <path d="M{{ $x1 + 0.6 }} 11.6 L{{ $x2 - 0.6 }} 11.4 M{{ $x1 }} 7 V19 M{{ $x2 }} 7 V19" fill="none" style="stroke: var(--color-ink)" stroke-width="1.4" stroke-linecap="round" />
                                        @if ($star)
                                            <path d="M293 3 L294.8 8.4 L300.4 8.6 L296 12 L297.6 17.4 L293 14.2 L288.4 17.4 L290 12 L285.6 8.6 L291.2 8.4 Z" style="fill: var(--color-fox)" />
                                        @endif
                                    </svg>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="flex text-xs font-bold leading-4 text-ink-muted" aria-hidden="true">
                    <span class="w-[45%] shrink-0 text-[13px] text-fox-deep">★ esimerkkitalo ≈ 26 600 kWh</span>
                    <span class="tool-ranges-axis"><span>0</span><span>10 000</span><span>20 000 kWh</span></span>
                </div>
            </div>
        </div>
    </section>

    {{-- What affects consumption + annual cost (Paper "Vaikuttaa + vuosikustannus"). --}}
    <section class="tool-two-col pt-3.5">
        <div class="flex flex-col gap-3.5 lg:w-[560px] lg:shrink-0">
            <x-site.section-heading size="compact" :wave="300">Mikä vaikuttaa sähkönkulutukseen?</x-site.section-heading>
            <p class="text-base leading-[26px] text-ink-body">
                Sähkönkulutus vaihtelee kotitalouksien välillä merkittävästi. Suurimmat tekijät ovat:
            </p>
            <ul class="tool-sparkle-list">
                @foreach ([
                    'asunnon koko ja huoneiden määrä',
                    'asukkaiden lukumäärä',
                    'lämmitysmuoto (suora sähkö, lämpöpumppu, kaukolämpö, öljy, puu)',
                    'rakennusvuosi ja eristyksen taso',
                    'maantieteellinen sijainti (etelä-, keski- vai pohjois-Suomi)',
                    'elämäntavat: kotona vietetty aika, saunominen, sähköauton lataus',
                    'kodinkoneiden energiatehokkuus ja käyttöaste',
                ] as $factor)
                    <li><x-ink.sparkle size="12" color="var(--color-fox)" />{{ $factor }}</li>
                @endforeach
            </ul>
        </div>
        <div class="tool-prose min-w-0">
            <x-site.section-heading size="compact" :wave="300">Sähkön hinta ja vuosikustannus</x-site.section-heading>
            <p>Kun tiedät vuotuisen kulutuksesi, voit laskea sähkölaskun suuruuden yksinkertaisella kaavalla:</p>
            <div class="tool-formula">
                <p class="tool-formula-text">kulutus (kWh) × sähkön hinta (snt/kWh) + perusmaksu (€/kk) × 12</p>
            </div>
            <p>
                Sähkön hinta vaihtelee sopimustyypin mukaan – pörssisähkössä hinta seuraa tuntikohtaista markkinahintaa,
                kun taas kiinteähintaisessa sopimuksessa hinta pysyy samana koko sopimuskauden.
                Voltikan
                <a href="/sahkosopimus" class="vk-text-link vk-focus">sähkösopimusten vertailussa</a>
                näet eri sopimusten arvioidut vuosikustannukset suoraan sinun kulutuksellesi laskettuna.
                Voit myös katsoa erikseen
                <a href="/sahkosopimus/halvin-sahkosopimus" class="vk-text-link vk-focus">halvimmat sähkösopimukset</a>
                tai vertailla
                <a href="/sahkosopimus/porssisahko" class="vk-text-link vk-focus">pörssisähkösopimuksia</a>.
            </p>
            <p class="pt-1 text-[15px] font-bold leading-[22px]">Katso sähkön hinta valmiiksi lasketulla vuosikulutuksella:</p>
            <ul class="flex flex-wrap gap-5">
                @foreach ($this->consumptionPageLinks as $link)
                    <li><a href="{{ $link['url'] }}" class="tool-level-link vk-focus">{{ $link['label'] }}</a></li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- FAQ (Paper "Usein kysyttyä"): hairline rows; every answer stays in the page. --}}
    <section class="flex max-w-[860px] flex-col gap-2.5 pt-[18px]" aria-labelledby="calc-faq-title">
        <x-site.section-heading id="calc-faq-title" :wave="360">Usein kysyttyä sähkönkulutuksesta</x-site.section-heading>
        <div class="tool-faq">
            @foreach ($this->faqItems as $faq)
                <details class="group tool-faq-row" @if ($loop->first) open @endif>
                    <summary class="vk-focus">
                        <h3>{{ $faq['question'] }}</h3>
                        <svg width="18" height="18" viewBox="0 0 18 18" class="tool-faq-chevron" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" style="stroke: var(--color-ink)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    </summary>
                    <p class="tool-faq-answer">{{ $faq['answer'] }}</p>
                </details>
            @endforeach
        </div>
    </section>
    </div>
</div>
