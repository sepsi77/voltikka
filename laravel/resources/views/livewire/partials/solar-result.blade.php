@php
    /**
     * Shared solar result (Paper board 3WH-0 "Result").
     *
     * Rendered both for the live calculation (after the visitor picks an address)
     * and for the default-location example shown before any address is entered, so
     * a first-time visitor sees the payoff shape immediately instead of an empty box.
     *
     * No card: the annual production is the page's big number (Bricolage 112 with a
     * sun-gold underline) and the savings is the one fox-deep number inside a hand-drawn
     * fox loop. The monthly chart is a plain bar chart with an inked baseline and a value
     * on every bar; bar colour follows the sun (gold summer months, pale forecast-blue
     * dark months), so it reads without a legend.
     *
     * Expected variables:
     *   $annualKwh, $monthlyKwh (array, 12 values), $systemKwp, $monthNamesFull
     *   $assumptions (array), $showSavings (bool)
     *   $selfConsumptionPercent, $selfConsumptionLabel, $effectivePrice, $annualSavings
     *   $live (bool)       render wire:loading overlay for the interactive calculation
     *   $isExample (bool)  label the result as an illustrative example
     */
    $live = $live ?? false;
    $isExample = $isExample ?? false;
    $assumptions = $assumptions ?? [];
    $maxMonthly = count($monthlyKwh) > 0 ? max($monthlyKwh) : 0;
    $fmt = fn ($value, int $decimals = 0) => number_format((float) $value, $decimals, ',', ' ');

    // Chart scale: the tallest bar fills about 80% of the plot, so its value label fits above it.
    $chartTop = max(1, $maxMonthly * 1.24);
    $gridStep = collect([50, 100, 200, 250, 500, 1000, 2000, 5000])
        ->first(fn ($step) => $maxMonthly / $step <= 4) ?? 10000;
    $gridLines = [];
    for ($line = $gridStep; $line < $chartTop; $line += $gridStep) {
        $gridLines[] = $line;
    }
    $maxIndex = $maxMonthly > 0 ? array_search($maxMonthly, $monthlyKwh) : null;
    $shortMonths = ['tammi', 'helmi', 'maalis', 'huhti', 'touko', 'kesä', 'heinä', 'elo', 'syys', 'loka', 'marras', 'joulu'];
    $barTone = fn ($kwh) => match (true) {
        $maxMonthly <= 0 => 'low',
        $kwh / $maxMonthly >= 0.75 => 'high',
        $kwh / $maxMonthly >= 0.3 => 'mid',
        default => 'low',
    };
@endphp

<section class="energy-result" aria-label="Aurinkopaneelien tuottoarvio">
    @if ($live)
        {{-- Loading overlay while a new PVGIS estimate is fetched --}}
        <div
            wire:loading
            wire:target="systemKwp, shadingLevel, selectAddress"
            class="energy-result-loading"
        >
            <div class="flex h-full items-center justify-center gap-3">
                <svg class="energy-spinner" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M21 12a9 9 0 0 0-9-9" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                <span>Lasketaan...</span>
            </div>
        </div>
    @endif

    @if ($isExample)
        <div class="energy-example">
            <svg class="shrink-0" width="30" height="30" viewBox="0 0 30 30" aria-hidden="true" focusable="false"><path d="M15 27 C 14 19, 15 11, 15 4 M8.5 10 L15 3.5 L21.5 10" fill="none" style="stroke: var(--color-fox)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
            <p class="energy-example-kicker">Esimerkki</p>
            <p class="energy-example-text">
                {{ number_format($systemKwp, 1, ',', ' ') }} kWp:n järjestelmä Helsingissä. <strong>Syötä osoitteesi yllä</strong> nähdäksesi arvion omalle katollesi.
            </p>
        </div>
    @endif

    <!-- Annual production and savings -->
    <div class="energy-keyfigures">
        <div class="energy-annual">
            <p class="energy-figure-label">Arvioitu vuosituotto</p>
            <p class="energy-annual-value">
                <span class="energy-annual-number">{{ $fmt($annualKwh) }}</span>
                <span class="energy-annual-unit">kWh</span>
            </p>
            <svg class="energy-annual-wave" viewBox="0 0 330 14" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path d="M3 9 C 60 3, 120 12, 180 7 S 290 3, 327 8" fill="none" style="stroke: var(--color-star)" stroke-width="5" stroke-linecap="round" /></svg>
        </div>

        @if ($showSavings)
            <div class="energy-savings">
                <svg class="energy-savings-loop" viewBox="0 0 300 132" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path d="M246 14 C 196 0, 70 2, 28 22 C 2 36, 8 100, 66 116 C 128 132, 252 126, 282 96 C 302 74, 292 34, 226 12" fill="none" style="stroke: var(--color-fox)" stroke-width="2.6" stroke-linecap="round" vector-effect="non-scaling-stroke" /></svg>
                <p class="energy-figure-label relative">Arvioitu säästö sähkölaskussa</p>
                <p class="energy-savings-value relative">
                    <span class="energy-savings-number">{{ $fmt($annualSavings) }}</span>
                    <span class="energy-savings-unit">€/vuosi</span>
                </p>
            </div>
        @endif
    </div>

    <!-- Monthly Breakdown Chart -->
    @if (count($monthlyKwh) === 12)
        <div class="energy-monthly">
            <div class="energy-monthly-head">
                <h3 class="energy-monthly-title">Kuukausituotto</h3>
                <p class="energy-monthly-unit">kWh kuukaudessa</p>
            </div>
            {{-- Screen-reader summary; the drawn bars below repeat the same values. --}}
            <p class="sr-only">
                Kuukausituotto kilowattitunteina: @foreach ($monthlyKwh as $index => $kwh){{ $monthNamesFull[$index] }} {{ number_format($kwh, 0, ',', ' ') }}@if (!$loop->last), @endif @endforeach.
            </p>

            {{-- Tablet and up: twelve columns with a value on every bar. --}}
            <div class="energy-bars" aria-hidden="true">
                <div class="energy-bars-plot">
                    @foreach ($gridLines as $line)
                        <div class="energy-bars-grid" style="bottom: {{ round($line / $chartTop * 100, 2) }}%">
                            <span>{{ $fmt($line) }} kWh</span>
                        </div>
                    @endforeach
                    @foreach ($monthlyKwh as $index => $kwh)
                        @php $height = $maxMonthly > 0 ? max(1.5, round($kwh / $chartTop * 100, 2)) : 1.5; @endphp
                        <div class="energy-bars-col">
                            <div class="energy-bar energy-bar--{{ $barTone($kwh) }}" style="height: {{ $height }}%">
                                <span class="energy-bar-value">{{ $fmt($kwh) }}</span>
                                @if ($index === $maxIndex && $index <= 7)
                                    <span class="energy-bars-note">
                                        <svg width="132" height="30" viewBox="0 0 132 30" aria-hidden="true" focusable="false"><path d="M126 9 C 62 0, 14 6, 2 22 M-4 14 L2 23 L11 17" fill="none" style="stroke: var(--color-fox)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                        kesällä jopa 10× enemmän kuin talvella
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                    <svg class="energy-bars-baseline" viewBox="0 0 1232 6" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path d="M2 3.5 C 300 1.5, 700 4.5, 1230 2.5" fill="none" style="stroke: var(--color-ink)" stroke-width="2" stroke-linecap="round" vector-effect="non-scaling-stroke" /></svg>
                </div>
                <div class="energy-bars-months">
                    @foreach ($shortMonths as $month)
                        <span>{{ $month }}</span>
                    @endforeach
                </div>
            </div>

            {{-- Phone: one row per month, so every label stays readable. --}}
            <ul class="energy-rows" aria-hidden="true">
                @foreach ($monthlyKwh as $index => $kwh)
                    <li>
                        <span class="energy-rows-month">{{ $shortMonths[$index] }}</span>
                        <span class="energy-rows-track">
                            <span class="energy-bar energy-bar--{{ $barTone($kwh) }}" style="width: {{ $maxMonthly > 0 ? max(1, round($kwh / $maxMonthly * 100, 2)) : 1 }}%"></span>
                        </span>
                        <span class="energy-rows-value">{{ $fmt($kwh) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Summary figures and the savings formula -->
    <div class="energy-figures">
        <div class="energy-figure">
            <p class="energy-figure-label">Järjestelmän koko</p>
            <p class="energy-figure-value">{{ number_format($systemKwp, 1, ',', ' ') }} kWp</p>
        </div>
        <div class="energy-figure">
            <p class="energy-figure-label">Keskituotto/kk</p>
            <p class="energy-figure-value">{{ $fmt($annualKwh / 12) }} kWh</p>
        </div>
        <div class="energy-figure">
            <p class="energy-figure-label">Tuotto/kWp</p>
            <p class="energy-figure-value">{{ $fmt($annualKwh / max(0.1, $systemKwp)) }} kWh</p>
        </div>
        @if ($showSavings)
            <div class="energy-formula">
                <p class="energy-figure-label">Näin säästö lasketaan</p>
                <p class="energy-formula-text">
                    {{ $fmt($annualKwh) }} kWh × {{ $selfConsumptionPercent }}% oma käyttö ({{ $selfConsumptionLabel }}) × {{ number_format($effectivePrice, 2, ',', ' ') }} c/kWh
                </p>
            </div>
        @endif
    </div>

    <!-- Assumptions (keys come from PvgisService::fetchFromApi) and the disclaimer -->
    <div class="energy-assumptions">
        @if (!empty($assumptions))
            <div class="energy-assumptions-list">
                <p class="energy-assumptions-title">Laskenta-arvot</p>
                <ul>
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

        <p class="energy-disclaimer">
            Luvut ovat suuntaa-antavia arvioita, eivät tae toteutuvasta tuotosta tai säästöstä eivätkä sijoitusneuvontaa. Todellinen tuotto vaihtelee sään, varjostuksen, lumen, paneelien suuntauksen ja oman kulutuksen mukaan. Tuottoarvio perustuu EU:n PVGIS-aineistoon.
        </p>
    </div>
</section>
