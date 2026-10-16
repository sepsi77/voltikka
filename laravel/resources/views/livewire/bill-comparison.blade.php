<div>
    {{-- Structured data: WebApplication + FAQPage (FAQPage from getFaqItemsProperty) --}}
    <x-schema-markup :schemas="[$jsonLd, $faqJsonLd]" />

    @php
        use App\Support\Season;

        $radioOn = '<svg class="vk-mark tool-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9.5" style="fill: var(--color-fox)" /><path d="M6.5 11.5 L9.6 14.3 L15.6 7.8" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>';
        $radioOff = '<svg class="vk-mark tool-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2" /></svg>';
        // The torn edges of the paper bill (Paper "Paper bill (form)"): a 10px zigzag.
        $zigTop = 'M0 8';
        $zigBottom = 'M0 6';
        for ($x = 10; $x <= 620; $x += 10) {
            $zigTop .= ' L'.$x.' '.(($x / 10) % 2 === 1 ? 1 : 8);
            $zigBottom .= ' L'.$x.' '.(($x / 10) % 2 === 1 ? 13 : 6);
        }
    @endphp

    {{-- Picture-book hero (Paper board 8X7-0 "Bill hero"): the fox carries a long bill. --}}
    <x-site.tool-hero art="maksatko-hero" :tone="Season::heroTone('maksatko-hero')">
        <h1 class="tool-hero-h1">Maksatko sähköstä liikaa?</h1>
        <p class="tool-hero-lead">
            Syötä sähkölaskusi tiedot, niin laskuri näyttää heti, mitä olisit maksanut muilla sopimuksilla ja kuinka paljon säästäisit vaihtamalla.
        </p>
    </x-site.tool-hero>

    <x-page-action-strip />

    <div class="vk-wrap tool-page">

        <nav class="vk-crumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/" class="vk-focus">Etusivu</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="/sahkosopimus" class="vk-focus">Sähkösopimukset</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page">Maksatko liikaa?</li>
            </ol>
        </nav>

        <p class="tool-intro">
            Vertaa sähkölaskuasi markkinoiden sopimuksiin. Laskuri käyttää samaa jaksoa ja kulutusta jokaiselle sopimukselle, joten näet tarkalleen, mitä olisit maksanut toisella sopimuksella. Säästöarvio huomioi kausivaihtelun, kun kerrot, onko sähkölämmitys mukana.
        </p>

        <div class="tool-two-col">
            {{-- The form drawn as a torn paper bill: the real object behind the inputs (spec §5
                 "Tool pages"). Paper with torn edges and inked sides, no shadow. --}}
            <section class="bill-paper lg:w-[620px] lg:shrink-0" aria-labelledby="bill-form-title">
                <svg class="bill-paper-edge" viewBox="0 0 620 14" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <path d="{{ $zigTop }} L620 14 L0 14 Z" style="fill: var(--color-snow-white)" />
                    <path d="{{ $zigTop }}" fill="none" style="stroke: var(--color-ink)" stroke-width="1.4" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                </svg>
                <div class="bill-paper-body">
                    <div class="flex flex-col gap-1">
                        <p class="vk-kicker">Laskun tiedot</p>
                        <h2 id="bill-form-title" class="bill-paper-title">Syötä sähkölaskusi tiedot</h2>
                        <p class="text-[15px] leading-[22px] text-ink-body">Kaikki löytyy sähkölaskulta, et tarvitse vuosikulutusta.</p>
                    </div>

                    {{-- Period --}}
                    <div class="flex flex-col gap-2.5">
                        <p class="text-sm font-extrabold leading-[18px] text-ink" id="bill-period-label">Laskutusjakso</p>
                        <div class="tool-radios bill-periods" role="group" aria-labelledby="bill-period-label">
                            @foreach ($presetLabels as $key => $label)
                                @php $isPeriod = $periodPreset === $key; @endphp
                                <button
                                    type="button"
                                    wire:click="setPeriodPreset('{{ $key }}')"
                                    aria-pressed="{{ $isPeriod ? 'true' : 'false' }}"
                                    class="tool-radio tool-period"
                                >
                                    {!! $isPeriod ? $radioOn : $radioOff !!}
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                        <div class="grid grid-cols-2 gap-5 sm:gap-7">
                            <div class="tool-field-wrap">
                                <label for="bill-start" class="text-[13px] font-bold leading-4 text-ink-muted">Alkaa</label>
                                <input
                                    type="date"
                                    id="bill-start"
                                    wire:model.live.blur="startDate"
                                    class="bill-date"
                                >
                            </div>
                            <div class="tool-field-wrap">
                                <label for="bill-end" class="text-[13px] font-bold leading-4 text-ink-muted">Päättyy</label>
                                <input
                                    type="date"
                                    id="bill-end"
                                    wire:model.live.blur="endDate"
                                    class="bill-date"
                                >
                            </div>
                        </div>
                    </div>

                    {{-- Required numbers: bill lines with a dotted leader to the value field. --}}
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col gap-1">
                            <div class="bill-line">
                                <label for="bill-kwh" class="bill-line-label">Kulutus (kWh)</label>
                                <span class="vk-leader" aria-hidden="true"></span>
                                <span class="tool-field bill-line-field">
                                    <input
                                        id="bill-kwh"
                                        type="number"
                                        wire:model.live.blur="kwh"
                                        min="1"
                                        step="1"
                                        inputmode="numeric"
                                        placeholder="esim. 400"
                                        aria-invalid="{{ $errors->has('kwh') ? 'true' : 'false' }}"
                                        aria-describedby="bill-kwh-help{{ $errors->has('kwh') ? ' bill-kwh-error' : '' }}"
                                    >
                                    <span class="tool-unit" aria-hidden="true">kWh</span>
                                </span>
                            </div>
                            <p id="bill-kwh-help" class="text-[13px] leading-[18px] text-ink-muted">Kulutus laskun ajalta.</p>
                            @error('kwh')
                                <p id="bill-kwh-error" role="alert" class="vk-notice">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="flex flex-col gap-1">
                            <div class="bill-line">
                                <label for="bill-total-eur" class="bill-line-label">Sähkösopimuksen hinta (€)</label>
                                <span class="vk-leader" aria-hidden="true"></span>
                                <span class="tool-field bill-line-field">
                                    <input
                                        id="bill-total-eur"
                                        type="number"
                                        wire:model.live.blur="totalEur"
                                        min="0.01"
                                        step="0.01"
                                        inputmode="decimal"
                                        placeholder="esim. 35,00"
                                        aria-invalid="{{ $errors->has('totalEur') ? 'true' : 'false' }}"
                                        aria-describedby="bill-total-eur-help{{ $errors->has('totalEur') ? ' bill-total-eur-error' : '' }}"
                                    >
                                    <span class="tool-unit" aria-hidden="true">€</span>
                                </span>
                            </div>
                            <p id="bill-total-eur-help" class="text-[13px] leading-[18px] text-ink-muted">Syötä sähköenergian osuus ilman sähkön siirtoa.</p>
                            @error('totalEur')
                                <p id="bill-total-eur-error" role="alert" class="vk-notice">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Real checkboxes drawn as the design checkbox. --}}
                        <label class="bill-check bill-check--first">
                            <input type="checkbox" wire:model.live="includesVat" class="vk-checkbox bill-checkbox">
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="text-[15px] font-extrabold leading-5 text-ink">Hinta sisältää ALV:n</span>
                                <span class="text-[13px] leading-[19px] text-ink-muted">Useimmat syöttävät hinnan verollisena. Poista valinta, jos laskussa näkyy veroton hinta.</span>
                            </span>
                        </label>
                        <label class="bill-check">
                            <input type="checkbox" wire:model.live="includesHeating" class="vk-checkbox bill-checkbox">
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="text-[15px] font-extrabold leading-5 text-ink">Lämmitän kotini sähköllä</span>
                                <span class="text-[13px] leading-[19px] text-ink-muted">Valitse, jos kotisi pää- tai tukilämmitys toimii sähköllä, esimerkiksi suoralla sähköllä, ilma- tai maalämpöpumpulla. Tarkentaa vuosisäästön arviota, ei vaikuta jakson vertailuun.</span>
                            </span>
                        </label>
                    </div>
                </div>
                <svg class="bill-paper-edge" viewBox="0 0 620 14" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <path d="{{ $zigBottom }} L620 0 L0 0 Z" style="fill: var(--color-snow-white)" />
                    <path d="{{ $zigBottom }}" fill="none" style="stroke: var(--color-ink)" stroke-width="1.4" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                </svg>
            </section>

            {{-- Help at the side of the bill (Paper "Bill help side"). --}}
            <div class="flex flex-col gap-[26px] lg:pt-[30px]">
                <div class="flex gap-3.5">
                    <svg width="40" height="40" viewBox="0 0 40 40" class="shrink-0" aria-hidden="true" focusable="false">
                        <path d="M20 4 C 29 4.3, 36 11, 35.7 20.2 C 35.4 29.4, 28.6 36, 19.6 35.8 C 10.6 35.5, 4.2 28.6, 4.4 19.6 C 4.6 10.8, 11.4 3.8, 20 4 Z" fill="none" style="stroke: var(--color-fox)" stroke-width="2.2" />
                        <path d="M20 17.5 V27.5" fill="none" style="stroke: var(--color-ink)" stroke-width="2.6" stroke-linecap="round" />
                        <circle cx="20" cy="12.4" r="1.8" style="fill: var(--color-ink)" />
                    </svg>
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <h2 class="tool-h3 tool-h3--lg">Mikä hinta syötetään?</h2>
                        <p class="text-base leading-[26px] text-ink-body">Syötä <strong class="font-extrabold text-ink">vain sähkösopimuksen hinta</strong>, ei sähkön siirtoa. Siirto laskutetaan verkkoyhtiöltä erikseen, eikä sitä voi säästää vaihtamalla sopimusta. Jos saat yhdistelmälaskun, etsi "sähkö"- tai "energia"-rivi. Hinnan voi syöttää verollisena; merkitse valinnalla, sisältyykö hintaan ALV.</p>
                    </div>
                </div>

                {{-- Optional input that sharpens the annual estimate (the page's hero number).
                     Hold the native <details> open state in Alpine so it survives the Livewire
                     morph after the input blurs. --}}
                <details x-data="{ open: false }" x-bind:open="open" x-on:toggle="open = $event.target.open" class="bill-annual">
                    <summary class="vk-focus">
                        <span>Tiedän vuosikulutukseni (tarkentaa vuosiarviota)</span>
                        <svg width="16" height="16" viewBox="0 0 16 16" class="tool-faq-chevron" aria-hidden="true" focusable="false"><path d="M3.5 6l4.5 4.5L12.5 6" fill="none" style="stroke: var(--color-ink)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    </summary>
                    <div class="flex max-w-[280px] flex-col gap-1 pb-1 pt-3">
                        <label for="bill-annual-kwh" class="tool-label">Vuosikulutus (kWh)</label>
                        <span class="tool-field">
                            <input
                                id="bill-annual-kwh"
                                type="number"
                                wire:model.live.blur="annualKwh"
                                min="0"
                                step="1"
                                inputmode="numeric"
                                placeholder="esim. 18000"
                                @error('annualKwh') aria-invalid="true" aria-describedby="annual-kwh-error" @enderror
                            >
                            <span class="tool-unit" aria-hidden="true">kWh</span>
                        </span>
                        @error('annualKwh')
                            <p id="annual-kwh-error" role="alert" class="vk-notice">{{ $message }}</p>
                        @enderror
                    </div>
                    <p class="text-[13px] leading-[19px] text-ink-muted">Jos tiedät vuosikulutuksesi, vuosisäästö arvioidaan sen perusteella kausivaihtelun sijaan.</p>
                </details>
            </div>
        </div>

        @if ($errorMessage)
            <p class="bill-error" role="alert">{{ $errorMessage }}</p>
        @endif

        {{-- Quiet, non-blocking recalculation status --}}
        <div wire:loading.delay class="vk-toast">
            <x-spinner size="h-4 w-4" color="text-white" label="Lasketaan vertailua" />
            Lasketaan vertailua…
        </div>

        {{-- Results --}}
        @if (! $this->hasResults && ! $errorMessage)
            <p class="text-[15px] leading-6 text-ink-body">Muokkaa yllä olevia tietoja sähkölaskusi mukaan, niin näet vertailun.</p>
        @endif
        @if ($this->hasResults && $resultArray)
        <div wire:loading.delay.class="opacity-50" class="flex flex-col gap-7 transition-opacity duration-200 motion-reduce:transition-none">
            @php
                $res = $resultArray;
                $userRow = null;
                $userRowIndex = null;
                $cheapestRow = null;
                foreach ($res['rows'] as $i => $row) {
                    if ($row['is_user']) { $userRow = $row; $userRowIndex = $i; }
                    elseif ($cheapestRow === null) { $cheapestRow = $row; }
                }
                $topRows = [];
                foreach ($res['rows'] as $i => $row) {
                    if (count($topRows) < 10) { $topRows[] = ['row' => $row, 'rank' => $i + 1]; }
                }
                // If the user ranks outside the top 10, append their row so they
                // can always see where they landed.
                $showUserTail = $userRow !== null && $userRowIndex >= 10;
                $userRank = $res['user_rank'];
                $total = $res['total_contracts'];
                // Share of the market the user already beats on price. Phrased
                // positively ("edullisempi kuin X %") so the verdict reads as
                // empowerment, not blame: rank 38/326 means cheaper than ~89 %,
                // which the old "kalliimpi kuin 11 %" wording contradicted.
                $betterThanPct = $total > 1 ? round(($total - $userRank) / max(1, $total - 1) * 100) : 0;

                // Bill vs cheapest bars (Paper "Bill vs cheapest"): both on one euro scale.
                $userCost = $userRow['period_cost_eur'] ?? null;
                $cheapestCost = $cheapestRow['period_cost_eur'] ?? null;
                $barMax = max((float) $userCost, (float) $cheapestCost, 0.01);
                $periodLabel = $periodPreset !== 'custom' && isset($presetLabels[$periodPreset])
                    ? $presetLabels[$periodPreset]
                    : \Carbon\Carbon::parse($startDate)->format('j.n.').'–'.\Carbon\Carbon::parse($endDate)->format('j.n.Y');
                // Rank line: 1 (cheapest) at the left, the last rank at the right.
                $rankPos = $total > 1 ? round(($userRank - 1) / ($total - 1) * 100, 2) : 0;
            @endphp

            <x-site.spot-paw-divider />

            {{-- Verdict: the annual saving as the page's big number, the bill and the cheapest
                 contract of the same period as bars, and the rank on one line. --}}
            <section class="tool-two-col" aria-label="Tulos">
                <div class="flex flex-col gap-2.5 lg:w-[560px] lg:shrink-0">
                    @if ($res['is_overpaying'])
                        <p class="vk-kicker">Voisit säästää vaihtamalla</p>
                        <div class="bill-saving">
                            <p class="bill-saving-number">≈ {{ number_format(abs($res['annual_saving_eur']), 0, ',', ' ') }} €<span class="bill-saving-unit">/vuosi</span></p>
                            <svg viewBox="0 0 420 20" class="bill-saving-loop" aria-hidden="true" focusable="false">
                                <path d="M4 12 C 80 4, 160 16, 250 9 S 380 5, 416 11" fill="none" style="stroke: var(--color-fox)" stroke-width="4" stroke-linecap="round" />
                                <path d="M30 17 C 120 11, 220 18, 330 13" fill="none" style="stroke: var(--color-fox); opacity: .7" stroke-width="2.2" stroke-linecap="round" />
                            </svg>
                        </div>
                        <p class="bill-estimate tabular-nums">Arvio · noin {{ number_format(abs($res['monthly_saving_eur']), 0, ',', ' ') }} €/kk</p>
                        <p class="text-[17px] leading-[27px] text-ink-body">Sopimuksesi on jo edullisempi kuin <strong class="font-extrabold text-ink tabular-nums">{{ $betterThanPct }} %</strong> markkinoista, mutta halvimpaan vaihtamalla säästäisit silti tämän verran.</p>
                        <p class="text-[15px] leading-[23px] text-ink-muted tabular-nums">Tällä laskutusjaksolla säästö olisi ollut {{ number_format(abs($res['period_saving_eur']), 0, ',', ' ') }} €. Vuosiarvio huomioi kausivaihtelun{{ $includesHeating ? ' ja sähkölämmityksen' : '' }}.</p>
                    @else
                        <p class="vk-kicker bill-kicker--good"><x-ink.sparkle size="12" color="var(--color-saving)" />Sopimuksesi on kilpailukykyinen</p>
                        <p class="text-[17px] leading-[27px] text-ink-body">Sopimuksesi on edullisempi kuin <strong class="font-extrabold text-ink tabular-nums">{{ $betterThanPct }} %</strong> markkinoista. Vaihtaminen ei tällä kulutuksella tuota merkittävää säästöä.</p>
                    @endif
                </div>

                <div class="flex min-w-0 flex-col gap-3.5 lg:pt-1">
                    <p class="font-display text-[20px] font-extrabold leading-[26px] text-ink tabular-nums">{{ $periodLabel }} · {{ number_format($acceptedKwh, 0, ',', ' ') }} kWh</p>
                    @if ($userCost !== null)
                        <div class="flex flex-col gap-1">
                            <div class="bill-bar-head" style="width: {{ round($userCost / $barMax * 100, 2) }}%">
                                <span>Sinun laskusi</span>
                                <span class="bill-bar-value" style="color: var(--color-price-high)">{{ number_format($userCost, 2, ',', ' ') }} €</span>
                            </div>
                            <span class="bill-bar" style="width: {{ round($userCost / $barMax * 100, 2) }}%" aria-hidden="true"><span style="background-color: var(--color-price-high)"></span></span>
                        </div>
                    @endif
                    @if ($cheapestCost !== null)
                        <div class="flex flex-col gap-1">
                            <div class="bill-bar-head" style="min-width: min(100%, 300px); width: {{ round($cheapestCost / $barMax * 100, 2) }}%">
                                <span>Halvin tällä jaksolla</span>
                                <span class="bill-bar-value" style="color: var(--color-saving)">{{ number_format($cheapestCost, 2, ',', ' ') }} €</span>
                            </div>
                            <div class="flex items-center gap-2" aria-hidden="true">
                                <span class="bill-bar" style="width: {{ round($cheapestCost / $barMax * 100, 2) }}%"><span style="background-color: var(--color-aurora)"></span></span>
                                @if ($res['is_overpaying'] && $userCost !== null && $userCost > $cheapestCost)
                                    <svg class="bill-gap-arrow" viewBox="0 0 300 26" preserveAspectRatio="none" focusable="false"><path d="M4 13 C 80 11, 200 14, 290 12" fill="none" style="stroke: var(--color-fox)" stroke-width="2.4" stroke-linecap="round" stroke-dasharray="5 5" vector-effect="non-scaling-stroke" /></svg>
                                @endif
                            </div>
                            @if ($res['is_overpaying'] && $userCost !== null && $userCost > $cheapestCost)
                                <p class="self-end text-sm font-extrabold leading-[18px] text-fox-deep tabular-nums">{{ number_format($userCost - $cheapestCost, 0, ',', ' ') }} € jaksolta</p>
                            @endif
                        </div>
                    @endif

                    <div class="flex flex-col gap-1.5 pt-2.5">
                        <div class="flex justify-between gap-3">
                            <span class="text-[15px] font-extrabold leading-[18px] text-ink tabular-nums">Sijasi {{ $userRank }} / {{ $total }}</span>
                            <span class="text-[13px] leading-4 text-ink-muted">halvin vasemmalla</span>
                        </div>
                        <div class="bill-rank" aria-hidden="true">
                            <span class="bill-rank-line"></span>
                            <span class="bill-rank-first"></span>
                            <span class="bill-rank-you" style="left: {{ $rankPos }}%"></span>
                        </div>
                        <div class="relative h-4 text-[13px] font-extrabold leading-4" aria-hidden="true">
                            <span class="absolute left-0 text-aurora">1 · halvin</span>
                            @if ($userRank > 1)
                                <span class="bill-rank-you-label" style="left: {{ $rankPos }}%">{{ $userRank }} · sinä</span>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            {{-- Spot caveat --}}
            @if ($res['has_spot_in_top'] && $res['spot_avg_cents_per_kwh'] !== null)
                <div class="bill-note bill-note--star">
                    <p class="text-[15px] font-extrabold leading-5 text-warning-ink">Huomio pörssisähköstä</p>
                    <p class="tabular-nums">Pörssisähkö on kolmen halvimman joukossa. Tämän jakson pörssihinta oli keskimäärin {{ number_format($res['spot_avg_cents_per_kwh'], 2, ',', ' ') }} c/kWh (sis. ALV). Jakson säästö perustuu toteutuneisiin hintoihin, mutta pörssisähkön vuosisäästö on <em>arvio</em>, sillä hinta vaihtelee jatkuvasti.</p>
                </div>
            @endif

            {{-- Implied price sanity warning --}}
            @if (in_array('implied_out_of_range', $res['warnings'] ?? []))
                <div class="bill-note tabular-nums">
                    <p>Syöttämäsi hinta ja kulutus antavat epätavallisen keskihinnan ({{ number_format($res['user_implied_cents_per_kwh'], 2, ',', ' ') }} c/kWh). Tarkista, että syötit sähkösopimuksen hinnan ilman siirtoa.</p>
                </div>
            @endif

            {{-- Ranking (Paper "Ranking"): ledger rows on the same period basis, the visitor's
                 own bill as a fox-ruled row. --}}
            <section class="flex flex-col gap-2" aria-labelledby="bill-ranking-title">
                <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-1">
                    <x-site.section-heading id="bill-ranking-title" :wave="420">Markkinoiden halvimmat tällä kulutuksella</x-site.section-heading>
                    <p class="pb-3 font-display text-[22px] font-extrabold leading-7 text-fox-deep tabular-nums">Sijasi {{ $userRank }} / {{ $total }}</p>
                </div>

                <table class="bill-table">
                    <thead>
                        <tr>
                            <th scope="col" class="bill-col-rank">#</th>
                            <th scope="col">Sopimus</th>
                            <th scope="col" class="bill-col-num">Jakson hinta</th>
                            <th scope="col" class="bill-col-num bill-col-cents">c/kWh</th>
                            <th scope="col" class="bill-col-num">Säästö jaksolta</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($topRows as $entry)
                            @php
                                $row = $entry['row'];
                                $rank = $entry['rank'];
                                $isUser = $row['is_user'];
                            @endphp
                            <tr wire:key="rank-row-{{ $isUser ? 'user' : $row['contract_id'] }}" @class(['bill-row-user' => $isUser])>
                                <td class="bill-col-rank bill-rank-no">{{ $rank }}</td>
                                <td>
                                    <div class="bill-name">
                                        @if ($isUser)
                                            <span class="bill-glyph-slot" aria-hidden="true"></span>
                                            <span class="flex min-w-0 flex-col gap-0.5">
                                                <span class="font-display text-[18px] font-extrabold leading-[22px] text-ink">Sinun sopimuksesi</span>
                                                <span class="text-[13px] leading-4 text-ink-muted">laskusi perusteella</span>
                                            </span>
                                        @else
                                            <span class="bill-glyph-slot" aria-hidden="true">
                                                @if ($row['is_spot'])
                                                    <x-ink.glyph bucket="porssisahko" size="30" />
                                                @endif
                                            </span>
                                            <span class="flex min-w-0 flex-col gap-0.5">
                                                @if ($row['detail_url'])
                                                    <a href="{{ $row['detail_url'] }}" class="bill-contract vk-focus">{{ $row['name'] }}</a>
                                                @else
                                                    <span class="bill-contract">{{ $row['name'] }}</span>
                                                @endif
                                                <span class="flex flex-wrap gap-x-3.5 gap-y-0.5 text-[13px] leading-4">
                                                    <span class="text-ink-muted">{{ $row['company_name'] }}</span>
                                                    @if ($row['is_spot'])
                                                        <span class="inline-flex items-center gap-1 font-extrabold text-aurora"><x-ink.sparkle size="10" color="var(--color-aurora)" />pörssi</span>
                                                    @endif
                                                    @if ($row['has_promo'])
                                                        <span class="inline-flex items-center gap-1 font-extrabold text-fox-deep"><x-ink.sparkle size="10" color="var(--color-fox-deep)" />tarjous</span>
                                                    @endif
                                                </span>
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="bill-col-num bill-cost" @if ($isUser) style="color: var(--color-price-high)" @endif>
                                    {{ number_format($row['period_cost_eur'], 2, ',', ' ') }} €
                                </td>
                                <td class="bill-col-num bill-col-cents">
                                    @if ($isUser)
                                        –
                                    @else
                                        {{ number_format($row['implied_cents_per_kwh'], 2, ',', ' ') }}
                                    @endif
                                </td>
                                <td class="bill-col-num">
                                    @if ($row['period_saving_eur'] !== null && $row['period_saving_eur'] > 0)
                                        <span class="bill-saving-cell"><span class="bill-phone-label">säästö </span>{{ number_format($row['period_saving_eur'], 2, ',', ' ') }} €</span>
                                    @else
                                        <span class="text-ink-muted">–</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        @if ($showUserTail)
                            <tr class="bill-row-gap">
                                <td colspan="5"><span aria-hidden="true">⋮</span></td>
                            </tr>
                            <tr class="bill-row-user">
                                <td class="bill-col-rank bill-rank-no">{{ $userRank }}</td>
                                <td>
                                    <div class="bill-name">
                                        <span class="bill-glyph-slot" aria-hidden="true"></span>
                                        <span class="flex min-w-0 flex-col gap-0.5">
                                            <span class="font-display text-[18px] font-extrabold leading-[22px] text-ink">Sinun sopimuksesi</span>
                                            <span class="text-[13px] leading-4 text-ink-muted">laskusi perusteella</span>
                                        </span>
                                    </div>
                                </td>
                                <td class="bill-col-num bill-cost" style="color: var(--color-price-high)">{{ number_format($userRow['period_cost_eur'], 2, ',', ' ') }} €</td>
                                <td class="bill-col-num bill-col-cents text-ink-muted">–</td>
                                <td class="bill-col-num text-ink-muted">–</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
                <p class="vk-fine-print pt-3 tabular-nums">
                    Hinnat sisältävät ALV:n 25,5 %, eivätkä sisällä sähkön siirtoa. Jakson hinta ja säästö perustuvat samaan laskutusjaksoon ja kulutukseen kuin laskusi. Markkinasopimusten c/kWh on jakson hinnasta ja kulutuksesta laskettu keskiarvo (sis. perusmaksun).
                </p>
            </section>
        </div>
        @endif

        <x-site.interlude art="maksatko-interlude" />

        {{-- SEO content / FAQ (Paper "SEO text + FAQ"). --}}
        <div class="tool-two-col">
            <section class="flex flex-col gap-3.5 lg:w-[520px] lg:shrink-0" aria-labelledby="bill-about-title">
                <x-site.section-heading id="bill-about-title" :wave="300">Maksatko sähköstä liikaa?</x-site.section-heading>
                <p class="tool-intro">Sähkösopimuksen hinta vaihtelee merkittävästi eri yhtiöiden välillä, ja sama kulutus voi maksaa satoja euroja vuodessa enemmän kalliimmalla sopimuksella. Voltikan laskuri vertaa sähkölaskuasi suoraan markkinoiden voimassa oleviin sopimuksiin: tarvitset vain yhden laskun tiedot.</p>
                <p class="tool-intro">Laskuri käyttää samaa laskutusjaksoa ja kulutusta jokaiselle markkinoiden sopimukselle, joten vertailu on reilu: näet, mitä olisit maksanut toisella sopimuksella juuri samalla jaksolla. Vuosisäästö arvioidaan kausivaihtelun huomioiden, kun kerrot, onko sähkölämmitys mukana kulutuksessa.</p>
            </section>

            <section class="flex flex-col gap-2.5" aria-labelledby="bill-faq-title">
                <x-site.section-heading id="bill-faq-title" :wave="300">Usein kysytyt kysymykset</x-site.section-heading>
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

                <div class="bill-note bill-note--quiet mt-3.5">
                    <p class="text-sm font-extrabold leading-[18px] text-ink">Huomio</p>
                    <p class="text-sm leading-[21px] text-ink-muted">Laskuri antaa suuntaa-antavan arvion markkinoiden hinnoista antamasi jakson ja kulutuksen perusteella. Pörssisähkön hinta vaihtelee, ja tarjoukset voivat päättyä. Todellinen säästö riippuu kulutustottumuksista ja sopimusehdoista.</p>
                </div>
            </section>
        </div>
    </div>
</div>
