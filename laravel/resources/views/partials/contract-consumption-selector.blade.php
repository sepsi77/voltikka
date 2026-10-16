{{--
    Consumption choice row (design spec §5 "Consumption choice row", Paper board 2J3-0
    "Controls · compact", first row). One source for ContractsList, SeoContractsList and
    CheapestContracts.

    Radio + 44px home art + name + kWh per preset (the selected radio is fox with a white
    check and its kWh turns fox-deep), then the underlined own-consumption field and the
    "Arvioi laskurilla →" calculator toggle. Behaviour is unchanged: `selectPreset`,
    `directConsumption` (wire:model.live.blur, min 0), `setActiveTab` and the inline calculator.
    The presets stay <button aria-pressed> controls; the radio mark is drawn.

    The calculator opens below the row with the same design language: underlined fields,
    radio and checkbox marks, the result as a big number on the page. No cards.

    On a phone the choices collapse behind "Vaihda" (the current value stays visible).

    Options:
      $isBusinessPage          business labels and presets (no home art)
      $showCalculatorTab       calculator toggle (default true)
      $showCurrentConsumption  "Vertailu kulutuksella" line (default true). The listing
                               page passes false and shows it at the right of its
                               disclosure row instead.
--}}
@php
    use App\Support\Season;

    // Home art for the four household presets, in the board order.
    $presetArt = [
        'small_apartment' => 'home-studio',
        'large_apartment' => 'home-apartment',
        'row_house' => 'home-rowhouse',
        'large_house_electric' => 'home-house',
    ];
    $showCalculator = $showCalculatorTab ?? true;
    $calculatorOpen = $showCalculator && $activeTab === 'calculator';
    $showCurrentConsumption = $showCurrentConsumption ?? true;
    $consumptionLabel = number_format($consumption, 0, ',', ' ').' kWh/v';
@endphp
<section x-data="{ panelOpen: false }" class="vk-ctl-section" aria-label="{{ $isBusinessPage ? 'Yrityksen vuosikulutus' : 'Vuosikulutus' }}">
    <div class="vk-ctl-row vk-ctl-row--consumption">
        <div class="flex w-full items-center justify-between gap-3 lg:w-auto">
            <p class="vk-ctl-label">{{ $isBusinessPage ? 'Yrityksen vuosikulutus' : 'Vuosikulutus' }}</p>
            {{-- Phone: the current value and the collapse toggle. --}}
            <button
                type="button"
                @click="panelOpen = !panelOpen"
                class="vk-disclosure-trigger lg:hidden"
                :aria-expanded="panelOpen ? 'true' : 'false'"
            >
                <span class="font-bold text-ink-body tabular-nums">{{ $consumptionLabel }}</span>
                <span class="vk-disclosure-title" x-text="panelOpen ? 'Piilota' : 'Vaihda'">Vaihda</span>
                <svg class="vk-chevron" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
            </button>
        </div>

        <div class="w-full flex-wrap items-center gap-y-2.5 lg:flex lg:w-auto lg:flex-1" :class="panelOpen ? 'flex' : 'hidden lg:flex'">
            @if (! $calculatorOpen)
                <div class="vk-presets" role="group" aria-label="Kulutusprofiilit">
                    @foreach ($presets as $key => $preset)
                        @php $isSel = $selectedPreset === $key; @endphp
                        <button
                            type="button"
                            wire:click="selectPreset('{{ $key }}')"
                            aria-pressed="{{ $isSel ? 'true' : 'false' }}"
                            class="vk-preset"
                        >
                            @if ($isSel)
                                <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9.5" style="fill: var(--color-fox)" /><path d="M6.5 11.5 L9.6 14.3 L15.6 7.8" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            @else
                                <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2" /></svg>
                            @endif
                            @isset($presetArt[$key])
                                <img src="{{ Season::art($presetArt[$key]) }}" alt="" width="44" height="44" class="vk-preset-art" loading="lazy" decoding="async">
                            @endisset
                            <span class="min-w-0">
                                <span class="vk-preset-name">{{ $preset['label'] }}</span>
                                <span class="vk-preset-desc">{{ $preset['description'] }}</span>
                                <span class="vk-preset-kwh">{{ number_format($preset['consumption'], 0, ',', ' ') }} kWh/v</span>
                            </span>
                        </button>
                    @endforeach
                </div>

                {{-- Own consumption: an underlined field (fox-deep underline while it holds the value). --}}
                @php $isDirect = $selectedPreset === null; @endphp
                <div class="vk-own">
                    <div class="flex flex-col gap-1">
                        <label for="direct-consumption" class="sr-only">Tiedän kulutukseni (kWh vuodessa)</label>
                        <div @class(['vk-own-field', 'vk-own-field--active' => $isDirect])>
                            <input
                                id="direct-consumption"
                                type="number"
                                min="0"
                                step="100"
                                inputmode="numeric"
                                wire:model.live.blur="directConsumption"
                                placeholder="esim. 7 000"
                                @if ($directConsumptionNotice) aria-invalid="true" aria-describedby="direct-consumption-notice" @endif
                            >
                            <span class="vk-unit">kWh/v</span>
                        </div>
                        @if ($directConsumptionNotice)
                            <p id="direct-consumption-notice" role="alert" class="vk-notice max-w-[240px]">{{ $directConsumptionNotice }}</p>
                        @endif
                    </div>
                    @if ($showCalculator)
                        <button type="button" wire:click="setActiveTab('calculator')" class="vk-inked-link vk-focus">
                            Arvioi laskurilla →
                        </button>
                    @endif
                </div>
            @else
                <div class="flex w-full flex-wrap items-center justify-between gap-3">
                    <p class="text-[15px] leading-5 text-ink-body">Vastaa muutamaan kysymykseen. Vertailu käyttää laskurin arviota.</p>
                    <button type="button" wire:click="setActiveTab('presets')" class="vk-inked-link vk-focus">
                        ← Sulje laskuri ja valitse profiili
                    </button>
                </div>
            @endif
        </div>
    </div>

    {{-- Inline consumption calculator (open state). --}}
    @if ($calculatorOpen)
        <div class="vk-calculator border-b border-[color:var(--listing-row-rule)] pb-6 pt-4 lg:pl-[128px]">
            @if ($calculatorInputNotice)
                <p role="alert" class="vk-notice mb-4">{{ $calculatorInputNotice }}</p>
            @endif

            <div class="grid gap-x-10 gap-y-6 lg:grid-cols-[minmax(0,1fr)_300px]">
                <div class="flex flex-col gap-6">
                    {{-- Living area and people --}}
                    <div class="grid max-w-[460px] grid-cols-2 gap-6">
                        <div>
                            <label for="calc-living-area" class="vk-form-label">Asuinpinta-ala (m²)</label>
                            <input
                                type="number"
                                id="calc-living-area"
                                wire:model.live.blur="calcLivingArea"
                                min="10"
                                max="500"
                                class="vk-input"
                            >
                        </div>
                        <div>
                            <label for="calc-num-people" class="vk-form-label">Asukkaiden määrä</label>
                            <input
                                type="number"
                                id="calc-num-people"
                                wire:model.live.blur="calcNumPeople"
                                min="1"
                                max="10"
                                class="vk-input"
                            >
                        </div>
                    </div>

                    {{-- Housing type --}}
                    <div>
                        <p class="vk-form-label" id="calc-building-type-label">Asuntotyyppi</p>
                        <div class="vk-choices mt-1.5" role="group" aria-labelledby="calc-building-type-label">
                            @foreach (['detached_house' => 'Omakotitalo', 'row_house' => 'Rivitalo', 'apartment' => 'Kerrostalo'] as $buildingType => $buildingLabel)
                                @php $isBuilding = $calcBuildingType === $buildingType; @endphp
                                <button type="button" wire:click="selectBuildingType('{{ $buildingType }}')" aria-pressed="{{ $isBuilding ? 'true' : 'false' }}" class="vk-choice">
                                    @if ($isBuilding)
                                        <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9.5" style="fill: var(--color-fox)" /><path d="M6.5 11.5 L9.6 14.3 L15.6 7.8" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                    @else
                                        <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2" /></svg>
                                    @endif
                                    {{ $buildingLabel }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Heating --}}
                    <div>
                        <label class="vk-choice">
                            <input type="checkbox" wire:model.live="calcIncludeHeating" class="vk-checkbox">
                            Sisällytä lämmitys
                        </label>
                        <p class="vk-form-hint mt-0.5 pl-7">Ota käyttöön, jos asuntosi lämmitetään sähköllä tai lämpöpumpulla.</p>
                    </div>

                    @if ($calcIncludeHeating)
                        <div class="grid gap-x-8 gap-y-5 sm:grid-cols-2">
                            <div>
                                <label for="calc-heating-method" class="vk-form-label">Lämmitysmuoto</label>
                                <select id="calc-heating-method" wire:model.live="calcHeatingMethod" class="vk-select">
                                    @foreach ($heatingMethods as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="calc-building-region" class="vk-form-label">Sijainti</label>
                                <select id="calc-building-region" wire:model.live="calcBuildingRegion" class="vk-select">
                                    @foreach ($buildingRegions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="calc-energy-efficiency" class="vk-form-label">Energiatehokkuus</label>
                                <select id="calc-energy-efficiency" wire:model.live="calcBuildingEnergyEfficiency" class="vk-select">
                                    @foreach ($energyRatings as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="calc-supplementary-heating" class="vk-form-label">Lisälämmitys</label>
                                <select id="calc-supplementary-heating" wire:model.live="calcSupplementaryHeating" class="vk-select">
                                    <option value="">Ei lisälämmitystä</option>
                                    @foreach ($supplementaryHeatingMethods as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @endif

                    {{-- Extras --}}
                    <div>
                        <p class="vk-form-label" id="calc-extras-label">Lisävarusteet</p>
                        <div class="vk-choices mt-1.5" role="group" aria-labelledby="calc-extras-label">
                            @foreach ([
                                'underfloor' => ['Lattialämmitys', $calcUnderfloorHeatingEnabled],
                                'sauna' => ['Sauna', $calcSaunaEnabled],
                                'ev' => ['Sähköauto', $calcElectricVehicleEnabled],
                                'cooling' => ['Jäähdytys', $calcCooling],
                            ] as $extra => [$extraLabel, $extraOn])
                                <button type="button" wire:click="toggleExtra('{{ $extra }}')" aria-pressed="{{ $extraOn ? 'true' : 'false' }}" class="vk-choice">
                                    @if ($extraOn)
                                        <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><rect x="1" y="1" width="20" height="20" rx="5" style="fill: var(--color-fox)" /><path d="M6.5 11.5 L9.6 14.3 L15.6 7.8" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                    @else
                                        <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><rect x="2" y="2" width="18" height="18" rx="5" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2" /></svg>
                                    @endif
                                    {{ $extraLabel }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    @if ($calcUnderfloorHeatingEnabled || $calcSaunaEnabled || $calcElectricVehicleEnabled)
                        <div class="grid gap-x-8 gap-y-5 sm:grid-cols-3">
                            @if ($calcUnderfloorHeatingEnabled)
                                <div>
                                    <label for="calc-bathroom-heating" class="vk-form-label">Lämmitetty lattia-ala (m²)</label>
                                    <input type="number" id="calc-bathroom-heating" wire:model.live.blur="calcBathroomHeatingArea" min="0" max="100" placeholder="esim. 10" class="vk-input">
                                </div>
                            @endif
                            @if ($calcSaunaEnabled)
                                <div>
                                    <label for="calc-sauna-usage" class="vk-form-label">Saunakertoja viikossa</label>
                                    <input type="number" id="calc-sauna-usage" wire:model.live.blur="calcSaunaUsagePerWeek" min="0" max="14" placeholder="esim. 2" class="vk-input">
                                </div>
                            @endif
                            @if ($calcElectricVehicleEnabled)
                                <div>
                                    <label for="calc-ev-kms" class="vk-form-label">Ajokilometrit viikossa</label>
                                    <input type="number" id="calc-ev-kms" wire:model.live.blur="calcElectricVehicleKmsPerWeek" min="0" max="2000" placeholder="esim. 200" class="vk-input">
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Result: the big number on the page, then the inked action. --}}
                <div class="flex flex-col gap-3 lg:border-l lg:border-[color:var(--color-edge)] lg:pl-8">
                    <p class="vk-kicker"><x-ink.sparkle size="12" color="var(--color-fox)" />Arvioitu vuosikulutus</p>
                    <p class="vk-big-figure">{{ number_format($consumption, 0, ',', ' ') }} <span class="vk-big-unit">kWh/v</span></p>
                    <p class="text-sm leading-5 text-ink-body">
                        @if ($calcIncludeHeating)
                            Sisältää peruskulutuksen ja lämmityksen
                        @else
                            Peruskulutus (ilman lämmitystä)
                        @endif
                        @if ($calcSaunaEnabled || $calcElectricVehicleEnabled || $calcUnderfloorHeatingEnabled || $calcCooling)
                            + lisävarusteet
                        @endif
                    </p>
                    <button type="button" wire:click="setActiveTab('presets')" class="vk-action vk-focus mt-2">
                        <span>Vertaa sähkösopimuksia →</span>
                        <x-ink.wave width="230" stroke="2.6" />
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Current selection. Shown on the pages that do not put it in their disclosure row:
         on a phone (the choices collapse) and in calculator mode. --}}
    @if ($showCurrentConsumption)
        <p class="vk-current-consumption {{ $calculatorOpen ? '' : 'lg:hidden' }}">
            Vertailu kulutuksella: <strong class="font-extrabold text-ink tabular-nums">{{ $consumptionLabel }}</strong>
        </p>
    @endif
</section>
