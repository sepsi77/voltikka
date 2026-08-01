@php
    /**
     * Shared solar result + savings cards.
     *
     * Rendered both for the live calculation (after the visitor picks an address)
     * and for the default-location example shown before any address is entered, so
     * a first-time visitor sees the payoff shape immediately instead of an empty box.
     *
     * The result is the page's dark "focus moment" (mirrors the hero): slate-950
     * surface, coral as the single accent, sanctioned glass stat cards, and savings
     * as the one coral-highlighted stat. No second accent colour.
     *
     * Expected variables:
     *   $annualKwh, $monthlyKwh (array, 12 values), $systemKwp, $monthNamesFull
     *   $assumptions (array), $showSavings (bool)
     *   $selfConsumptionPercent, $selfConsumptionLabel, $effectivePrice, $annualSavings
     *   $live (bool)       render wire:loading overlay for the interactive calculation
     *   $isExample (bool)  label the cards as an illustrative example
     */
    $live = $live ?? false;
    $isExample = $isExample ?? false;
    $assumptions = $assumptions ?? [];
    $maxMonthly = count($monthlyKwh) > 0 ? max($monthlyKwh) : 0;
@endphp

<section class="bg-slate-950 rounded-2xl shadow-lg p-6 mb-8 relative">{{-- no overflow-hidden: edge-month tooltips need to extend past the card --}}
    @if ($live)
        {{-- Loading overlay while a new PVGIS estimate is fetched --}}
        <div
            wire:loading
            wire:target="systemKwp, shadingLevel, selectAddress"
            class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm rounded-2xl flex items-center justify-center z-10"
        >
            <div class="flex items-center gap-3">
                <svg class="animate-spin h-6 w-6 text-coral-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-white font-medium">Lasketaan...</span>
            </div>
        </div>
    @endif

    @if ($isExample)
        <div class="mb-5 flex flex-wrap items-baseline gap-x-2 gap-y-1 border-b border-white/10 pb-4">
            <span class="inline-block bg-coral-500/20 text-coral-200 border border-coral-500/30 text-xs font-semibold uppercase tracking-wide rounded-md px-2 py-1">Esimerkki</span>
            <p class="text-slate-300 text-sm">
                {{ number_format($systemKwp, 1, ',', ' ') }} kWp:n järjestelmä Helsingissä. <span class="font-semibold text-coral-400">Syötä osoitteesi yllä</span> nähdäksesi arvion omalle katollesi.
            </p>
        </div>
    @endif

    <!-- Annual production -->
    <div class="text-center mb-6">
        <p class="text-slate-300 text-sm mb-1">Arvioitu vuosituotto</p>
        <p class="text-5xl font-extrabold text-white tabular-nums tracking-tight">
            {{ number_format($annualKwh, 0, ',', ' ') }}
            <span class="text-2xl font-normal text-slate-400">kWh</span>
        </p>
    </div>

    <!-- Monthly Breakdown Chart -->
    @if (count($monthlyKwh) === 12)
        <div class="mb-6">
            <p class="text-slate-300 text-sm mb-3 text-center">Kuukausituotto</p>
            {{-- Screen-reader summary; the visual bars below are decorative (values are in hover/tap tooltips) --}}
            <p class="sr-only">
                Kuukausituotto kilowattitunteina: @foreach ($monthlyKwh as $index => $kwh){{ $monthNamesFull[$index] }} {{ number_format($kwh, 0, ',', ' ') }}@if (!$loop->last), @endif @endforeach.
            </p>
            {{-- Inline bar chart with fixed pixel heights and Alpine.js tooltips --}}
            <div
                x-data="{ activeBar: null }"
                x-on:click.outside="activeBar = null"
                aria-hidden="true"
                class="relative"
                style="display: flex; align-items: flex-end; gap: 4px; height: 80px;"
            >
                @foreach ($monthlyKwh as $index => $kwh)
                    @php
                        $barHeight = $maxMonthly > 0
                            ? max(4, round(($kwh / $maxMonthly) * 76))
                            : 4;
                    @endphp
                    <div
                        class="relative bg-coral-500/70 hover:bg-coral-400 transition-colors"
                        style="flex: 1; height: {{ $barHeight }}px; border-radius: 2px 2px 0 0; cursor: pointer;"
                        x-on:mouseenter="activeBar = {{ $index }}"
                        x-on:mouseleave="activeBar = null"
                        x-on:click="activeBar = activeBar === {{ $index }} ? null : {{ $index }}"
                    >
                        {{-- Tooltip - positioned above the chart --}}
                        <div
                            x-show="activeBar === {{ $index }}"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0"
                            x-transition:enter-end="opacity-100"
                            class="absolute left-1/2 -translate-x-1/2 px-3 py-2 text-sm font-medium rounded-lg shadow-lg whitespace-nowrap pointer-events-none z-10 bg-slate-800 text-white tabular-nums"
                            style="bottom: 90px;"
                        >
                            {{ $monthNamesFull[$index] }}: {{ number_format($kwh, 0, ',', ' ') }} kWh
                        </div>
                    </div>
                @endforeach
            </div>
            {{-- Month labels (values are in the tooltip on hover/tap) --}}
            <div aria-hidden="true" style="display: flex; gap: 4px; margin-top: 6px;">
                @foreach ($monthlyKwh as $index => $kwh)
                    <div style="flex: 1; text-align: center;">
                        <span class="text-[10px] text-slate-400 font-medium tabular-nums">{{ $index + 1 }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Summary Stats (sanctioned glass on dark) -->
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 {{ $showSavings ? 'mb-3' : 'mb-0' }}">
        <div class="bg-white/5 border border-white/10 backdrop-blur-sm rounded-xl p-3">
            <p class="text-slate-300 text-xs">Järjestelmän koko</p>
            <p class="text-lg font-semibold text-white tabular-nums">{{ number_format($systemKwp, 1, ',', ' ') }} kWp</p>
        </div>

        <div class="bg-white/5 border border-white/10 backdrop-blur-sm rounded-xl p-3">
            <p class="text-slate-300 text-xs">Keskituotto/kk</p>
            <p class="text-lg font-semibold text-white tabular-nums">{{ number_format($annualKwh / 12, 0, ',', ' ') }} kWh</p>
        </div>

        <div class="bg-white/5 border border-white/10 backdrop-blur-sm rounded-xl p-3">
            <p class="text-slate-300 text-xs">Tuotto/kWp</p>
            <p class="text-lg font-semibold text-white tabular-nums">{{ number_format($annualKwh / max(0.1, $systemKwp), 0, ',', ' ') }} kWh</p>
        </div>
    </div>

    <!-- Savings (the one coral-highlighted stat) -->
    @if ($showSavings)
        <div class="bg-coral-500/15 border border-coral-500/30 backdrop-blur-sm rounded-xl p-4 mb-3">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <p class="text-coral-200 text-sm font-medium">Arvioitu säästö sähkölaskussa</p>
                <p class="text-3xl font-extrabold text-white tabular-nums">
                    {{ number_format($annualSavings, 0, ',', ' ') }}
                    <span class="text-lg font-normal text-coral-200">€/vuosi</span>
                </p>
            </div>
            <p class="text-slate-300 text-xs mt-2 tabular-nums">
                {{ number_format($annualKwh, 0, ',', ' ') }} kWh × {{ $selfConsumptionPercent }}% oma käyttö ({{ $selfConsumptionLabel }}) × {{ number_format($effectivePrice, 2, ',', ' ') }} c/kWh
            </p>
        </div>
    @endif

    <!-- Assumptions (keys come from PvgisService::fetchFromApi) -->
    @if (!empty($assumptions))
        <div class="bg-white/5 border border-white/10 backdrop-blur-sm rounded-xl p-3 text-sm">
            <p class="text-slate-300 text-xs mb-2">Laskenta-arvot</p>
            <ul class="text-slate-200 space-y-1 tabular-nums">
                @if (!empty($assumptions['optimal_angles']))
                    <li>Kaltevuus ja suuntaus: optimaalinen, etelään</li>
                @else
                    @isset($assumptions['roof_tilt_deg'])
                        <li>Kaltevuus: {{ $assumptions['roof_tilt_deg'] }}°</li>
                    @endisset
                    @isset($assumptions['roof_aspect_deg'])
                        <li>Suuntaus: {{ $assumptions['roof_aspect_deg'] }}° (0° = etelä)</li>
                    @endisset
                @endif
                @isset($assumptions['losses_percent'])
                    <li>Häviöt: {{ $assumptions['losses_percent'] }}%</li>
                @endisset
            </ul>
        </div>
    @endif

    <p class="text-slate-400 text-xs mt-3 leading-relaxed">
        Luvut ovat suuntaa-antavia arvioita, eivät tae toteutuvasta tuotosta tai säästöstä eivätkä sijoitusneuvontaa. Todellinen tuotto vaihtelee sään, varjostuksen, lumen, paneelien suuntauksen ja oman kulutuksen mukaan. Tuottoarvio perustuu EU:n PVGIS-aineistoon.
    </p>
</section>
