{{--
    One day of hourly prices on /spot-price (Paper board 11H-0 "Tänään", "Huomenna", "Ennustepäivät").
    Only /spot-price uses this partial; it runs inside the #tunnit Alpine scope (selectHour, closeDetail).

    $strip  one entry of SpotPrice::getDayStripsForChart(); the server owns the signed geometry
            (barBottomPercent, barHeightPercent, barEndPercent, zeroPercent, avgBaselinePercent)
            on one shared domain, so negative bars extend below the inked zero baseline.
    $mini   true for a third-party forecast day: a small chart with no value labels, in the
            forecast colour, with the "not an official price" line under it.
--}}
@php
    $isToday = $strip['key'] === 'today';
    $scaleMin = $strip['scaleMin'] ?? 0;
    $scaleMax = $strip['scaleMax'] ?? 20;
    $zeroPercent = $strip['zeroPercent'] ?? 0;
    $axisMagnitude = max(abs($scaleMin), abs($scaleMax));
    $axisDecimals = $axisMagnitude >= 10 ? 0 : 1;
    $scaleMinLabel = number_format($scaleMin, $axisDecimals, ',', ' ');
    $scaleMaxLabel = number_format($scaleMax, $axisDecimals, ',', ' ');
    $baselinePercent = $strip['avgBaselinePercent'] ?? null;
    $showBaseline = $baselinePercent !== null && abs($baselinePercent - $zeroPercent) > 0.5;
    $forecastStartHour = $strip['forecastStartHour'] ?? null;
    $forecastStartLabel = $forecastStartHour !== null
        ? str_pad((string) $forecastStartHour, 2, '0', STR_PAD_LEFT) . ':00'
        : null;
    $stripStats = $strip['stats'];
    $hasNegative = $scaleMin < 0;

    $hourMeta = function (array $price) use ($strip): array {
        $hourNum = $price['hour'] ?? $price['helsinki_hour'] ?? 0;
        $hourStart = str_pad($hourNum, 2, '0', STR_PAD_LEFT);
        $hourEnd = str_pad(($hourNum + 1) % 24, 2, '0', STR_PAD_LEFT);
        $label = "{$strip['label']} · {$hourStart}:00–{$hourEnd}:00";

        return [
            'label' => $label,
            'json' => json_encode([
                'timestamp' => $price['timestamp'] ?? 0,
                'label' => $label,
                'price' => $price['price_with_vat'] ?? $price['price_with_tax'] ?? 0,
                'isForecast' => $price['isForecast'] ?? $strip['isForecast'],
                'badge' => $price['badge']['label'] ?? null,
                'badgeType' => $price['badge']['type'] ?? null,
                'isCurrent' => !empty($price['isCurrentHour']),
            ]),
        ];
    };

    // "15 minuutin hinnat ›" opens the quarter-hour detail of the current hour (today) or of the
    // first official hour that has quarter prices (tomorrow). Forecast hours have none.
    $quarterTarget = null;
    if (! $mini) {
        $candidates = collect($strip['prices'])->filter(fn ($p) => empty($p['isPlaceholder']) && empty($p['isForecast'])
            && ! empty($quarterPricesByHour[$p['timestamp'] ?? 0] ?? []));
        $quarterTarget = $candidates->first(fn ($p) => ! empty($p['isCurrentHour'])) ?? $candidates->first();
    }
@endphp
<div class="spot-day {{ $mini ? 'spot-day--mini' : '' }}">
    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
            <h3 class="font-display text-[22px] font-extrabold leading-7 text-ink">{{ $strip['label'] }}</h3>
            @if ($strip['provenance'] === 'uudet')
                <span class="spot-mark text-aurora"><x-ink.sparkle size="11" color="var(--color-aurora)" /> Uudet</span>
            @elseif ($strip['provenance'] === 'ennuste')
                <span class="spot-mark text-fox-deep"><x-ink.sparkle size="11" color="var(--color-fox-deep)" /> Ennuste</span>
            @elseif ($strip['provenance'] === 'hybrid' && $forecastStartLabel)
                <span class="spot-mark text-fox-deep"><x-ink.sparkle size="11" color="var(--color-fox-deep)" /> Ennuste klo {{ $forecastStartLabel }} alkaen</span>
            @endif
            @if ($quarterTarget)
                @php $qMeta = $hourMeta($quarterTarget); @endphp
                <button
                    type="button"
                    @click="selectHour({{ $quarterTarget['timestamp'] }}, '{{ $strip['key'] }}', {{ $qMeta['json'] }})"
                    class="vk-focus text-[14px] font-extrabold leading-[18px] text-fox-deep underline decoration-1 underline-offset-2"
                >15 minuutin hinnat ›</button>
            @endif
        </div>
        @if ($stripStats['min'] !== null)
            <p class="whitespace-nowrap text-[13px] leading-4 text-ink-body">
                min {{ number_format($stripStats['min'], 1, ',', ' ') }}
                · ka {{ number_format($stripStats['avg'], 1, ',', ' ') }}
                · max {{ number_format($stripStats['max'], 1, ',', ' ') }} c
            </p>
        @endif
    </div>

    <div class="spot-chart {{ $hasNegative ? 'spot-chart--signed' : '' }}">
        <div class="spot-chart-plot">
            {{-- Signed Y-axis labels on the shared domain. --}}
            <span class="spot-axis-label" style="top: 0">{{ $scaleMaxLabel }} c</span>
            @if ($zeroPercent > 7 && $zeroPercent < 93)
                <span class="spot-axis-label -translate-y-1/2" style="bottom: {{ $zeroPercent }}%">0</span>
            @endif
            <span class="spot-axis-label" style="bottom: 0">{{ $scaleMinLabel }}{{ $scaleMin != 0 ? ' c' : '' }}</span>

            @if ($showBaseline)
                <span class="spot-avg-line" style="bottom: {{ $baselinePercent }}%;" aria-hidden="true"></span>
                <span class="spot-avg-label" style="bottom: calc({{ $baselinePercent }}% + 3px);" aria-hidden="true">30 pv ka</span>
            @endif

            <div class="spot-columns">
                @foreach ($strip['prices'] as $price)
                    @php
                        $isPlaceholder = !empty($price['isPlaceholder']);
                        $priceWithVat = $price['price_with_vat'] ?? $price['price_with_tax'] ?? 0;
                        $barBottomPercent = $price['barBottomPercent'] ?? $zeroPercent;
                        $barHeightPercent = $price['barHeightPercent'] ?? 0;
                        $barEndPercent = $price['barEndPercent'] ?? $zeroPercent;
                        $direction = $price['direction'] ?? 'zero';
                        $isCurrent = !empty($price['isCurrentHour']);
                        $isForecastBar = !empty($price['isForecast']) || $strip['isForecast'];
                        $timestamp = $price['timestamp'] ?? 0;
                        $isRank1 = ($price['todayRank'] ?? null) === 1;
                        $barTone = $isCurrent ? 'is-now' : ($isForecastBar ? 'is-forecast' : 'is-actual');
                        $topPercent = $direction === 'negative' ? $zeroPercent : $barEndPercent;
                    @endphp
                    @if ($isPlaceholder)
                        <span class="spot-col" aria-hidden="true"></span>
                    @else
                        @php $meta = $hourMeta($price); @endphp
                        <button
                            type="button"
                            @click="selectHour({{ $timestamp }}, '{{ $strip['key'] }}', {{ $meta['json'] }})"
                            class="spot-col spot-col-button"
                            :class="expandedHour === {{ $timestamp }} && expandedStripKey === '{{ $strip['key'] }}' ? 'is-selected' : ''"
                            aria-label="{{ $meta['label'] }} · {{ number_format($priceWithVat, 2, ',', ' ') }} c/kWh"
                            title="{{ str_pad($price['hour'] ?? $price['helsinki_hour'] ?? 0, 2, '0', STR_PAD_LEFT) }}:00 · {{ number_format($priceWithVat, 2, ',', ' ') }} c/kWh"
                        >
                            <span
                                class="spot-bar {{ $barTone }} {{ $direction === 'negative' ? 'is-negative' : '' }}"
                                style="bottom: {{ $barBottomPercent }}%; height: {{ $barHeightPercent }}%"
                                data-price-direction="{{ $direction }}"
                            ></span>

                            @if (! $mini)
                                @if ($isCurrent)
                                    <span class="spot-now" style="bottom: calc({{ $topPercent }}% + 3px)" aria-hidden="true">
                                        nyt
                                        <svg width="12" height="14" viewBox="0 0 12 14" focusable="false"><path d="M6 1 C 5.5 5, 6.5 8, 6 12 M2.5 8.5 L6 12.5 L9.5 8.5" fill="none" style="stroke: var(--color-fox)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                    </span>
                                @elseif ($direction === 'negative')
                                    <span class="spot-value" style="top: calc({{ 100 - $barEndPercent }}% + 3px)" aria-hidden="true">{{ number_format($priceWithVat, 2, ',', ' ') }}</span>
                                @else
                                    <span class="spot-value" style="bottom: calc({{ $barEndPercent }}% + 3px)" aria-hidden="true">{{ number_format($priceWithVat, 2, ',', ' ') }}</span>
                                @endif
                            @endif

                            @if ($isRank1)
                                <span
                                    class="spot-rank-star {{ $isCurrent ? 'is-above-now' : '' }}"
                                    style="bottom: calc({{ $topPercent }}% + {{ $mini ? 3 : 18 }}px)"
                                    title="Päivän halvin"
                                ><x-site.spot-star size="14" /></span>
                            @endif
                        </button>
                    @endif
                @endforeach
            </div>

            {{-- Inked zero baseline. --}}
            <span class="spot-zero-line" style="bottom: {{ $zeroPercent }}%;" aria-hidden="true" data-chart-zero-baseline>
                <svg viewBox="0 0 1202 6" preserveAspectRatio="none" focusable="false"><path d="M0 3 C 192 1, 397 5, 601 3 S 1010 1, 1202 3" fill="none" style="stroke: var(--color-ink)" stroke-width="2.2" stroke-linecap="round" vector-effect="non-scaling-stroke" /></svg>
            </span>
        </div>

        <div class="spot-hours" aria-hidden="true">
            @foreach ($strip['prices'] as $price)
                @php $h = (int) ($price['hour'] ?? $price['helsinki_hour'] ?? 0); @endphp
                <span class="{{ !empty($price['isCurrentHour']) ? 'is-now' : '' }}">
                    @if (! $mini || in_array($h, [0, 6, 12, 18, 23], true))
                        {{ str_pad($h, 2, '0', STR_PAD_LEFT) }}
                    @endif
                </span>
            @endforeach
        </div>
    </div>

    @if ($mini)
        <p class="text-[12px] leading-4 text-ink-muted">Kolmannen osapuolen ennuste, ei virallinen spot-hinta.</p>
    @endif

    @if ($isToday && $strip['provenance'] === null && ! $hasTomorrow)
        <p class="text-[13px] leading-4 text-ink-muted">Huomisen viralliset hinnat julkaistaan noin klo 13.45.</p>
    @endif

    {{-- Per-day detail: opens directly below this day's bars. --}}
    <div x-show="expandedStripKey === '{{ $strip['key'] }}'" x-collapse x-cloak>
        <div class="spot-detail">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-[13px] font-extrabold uppercase leading-4 tracking-[0.08em] text-ink-muted" x-text="selectedMeta?.label"></p>
                    <p class="mt-1 font-display text-[32px] font-extrabold leading-9 text-ink tabular-nums" x-text="(selectedMeta?.price ?? 0).toFixed(2).replace('.', ',') + ' c/kWh'"></p>
                    <p class="mt-1 text-[13px] leading-4 text-ink-muted" x-show="selectedMeta?.isForecast">Kolmannen osapuolen ennuste, ei virallinen spot-hinta.</p>
                </div>
                <button
                    type="button"
                    @click="closeDetail()"
                    class="vk-focus -m-1 p-1 text-ink-muted hover:text-ink"
                    aria-label="Sulje"
                >
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <template x-if="quarterPricesByHour[expandedHour] && quarterPricesByHour[expandedHour].length > 0">
                <div class="mt-4">
                    <p class="mb-2 text-[13px] font-extrabold uppercase leading-4 tracking-[0.08em] text-ink-muted">15 minuutin hinnat</p>
                    <div class="flex flex-col gap-2">
                        <template x-for="(quarter, idx) in quarterPricesByHour[expandedHour]" :key="idx">
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex w-28 shrink-0 items-center gap-1.5 text-[13px] font-bold tabular-nums sm:w-32"
                                    :class="quarter.is_current_slot ? 'text-fox-deep' : 'text-ink-body'"
                                >
                                    <span x-text="quarter.time_label"></span>
                                    <template x-if="quarter.is_current_slot">
                                        <span class="font-display font-extrabold">nyt</span>
                                    </template>
                                </span>
                                <div class="spot-quarter-track">
                                    <span class="spot-quarter-zero" :style="'left: ' + quarter.zero_percent + '%'" aria-hidden="true"></span>
                                    <div
                                        class="spot-quarter-bar"
                                        :class="quarter.is_current_slot ? 'bg-fox' : 'bg-granite'"
                                        :style="'left: ' + quarter.bar_left_percent + '%; width: ' + quarter.bar_width_percent + '%'"
                                        :data-price-direction="quarter.direction"
                                    ></div>
                                </div>
                                <span
                                    class="w-16 shrink-0 text-right text-[13px] font-extrabold tabular-nums"
                                    :class="quarter.is_current_slot ? 'text-fox-deep' : 'text-ink'"
                                    x-text="quarter.price_with_tax.toFixed(2).replace('.', ',') + ' c'"
                                ></span>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
            <template x-if="!quarterPricesByHour[expandedHour] || quarterPricesByHour[expandedHour].length === 0">
                <p class="mt-3 text-[14px] text-ink-muted">15 minuutin hintoja ei ole saatavilla tälle tunnille.</p>
            </template>
        </div>
    </div>
</div>
