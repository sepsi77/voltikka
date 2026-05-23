{{--
    Appliance "best time" tip card. Compact 5-up layout.
    Required vars:
      - $title:        appliance name (e.g. "Saunan lämmitys")
      - $assumption:   one-line assumption + permitted window (e.g. "Illalla 17–22, 8 kW kiuas")
      - $startHour:    int 0–23
      - $endHour:      int 0–23 (inclusive end of the cheap window)
      - $cost:         array with keys (one of):
                         ['type' => 'cents', 'value' => float]   for total cents
                         ['type' => 'rate',  'value' => float]   for c/kWh
      - $diffPercent:  ?float, signed, vs 30 pv ka (-40.5 = 40.5% halvempi)
      - $savingsEuros:    ?float, euros saved compared to the worst case in the same permitted window
      - $comparisonLabel: string, what "kallein aika" means for this card.
      - $icon:            SVG path d-attribute for the appliance icon
--}}
@php
    $hStart = str_pad($startHour, 2, '0', STR_PAD_LEFT);
    $hEnd   = str_pad(($endHour + 1) % 24, 2, '0', STR_PAD_LEFT);
@endphp
<article class="flex flex-col bg-white rounded-xl border border-slate-200 p-3 lg:p-4 transition-all duration-200 hover:border-slate-300 hover:shadow-sm">
    <div class="flex items-center gap-2 mb-2">
        <span class="bg-slate-100 p-1.5 rounded-md shrink-0">
            <svg class="w-4 h-4 text-slate-700" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/>
            </svg>
        </span>
        <h4 class="text-sm font-bold text-slate-900 leading-tight min-w-0 truncate">{{ $title }}</h4>
    </div>

    <p class="text-xl lg:text-2xl font-extrabold text-slate-900 leading-none tabular-nums">
        {{ $hStart }}<span class="text-slate-400 mx-0.5">–</span>{{ $hEnd }}
    </p>
    <p class="mt-1 text-[11px] text-slate-500 leading-tight">{{ $assumption }}</p>

    <div class="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1">
        <span class="text-sm font-semibold text-slate-700 tabular-nums">
            @if ($cost['type'] === 'cents')
                {{ number_format($cost['value'], 0, ',', ' ') }} senttiä
            @else
                {{ number_format($cost['value'], 2, ',', ' ') }} c/kWh
            @endif
        </span>
        @if ($diffPercent !== null)
            @if ($diffPercent < -5)
                <span class="text-[11px] font-semibold text-emerald-700 tabular-nums">↓ {{ number_format(abs($diffPercent), 0, ',', ' ') }} %</span>
            @elseif ($diffPercent > 5)
                <span class="text-[11px] font-semibold text-rose-700 tabular-nums">↑ {{ number_format($diffPercent, 0, ',', ' ') }} %</span>
            @endif
        @endif
    </div>

    @if ($savingsEuros !== null && $savingsEuros > 0)
        <p class="mt-2 text-[11px] text-slate-500 tabular-nums leading-snug">
            Säästät <span class="font-semibold text-slate-700">{{ number_format($savingsEuros, 2, ',', ' ') }} €</span>
        </p>
    @endif
</article>
