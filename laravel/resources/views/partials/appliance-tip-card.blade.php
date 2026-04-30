{{--
    Appliance "best time" tip card. One container, one focal answer (the time).
    Required vars:
      - $title:        appliance name (e.g. "Saunan lämmitys")
      - $assumption:   one-line assumption + permitted window (e.g. "Illalla 17–22, 8 kW kiuas")
      - $startHour:    int 0–23
      - $endHour:      int 0–23 (inclusive end of the cheap window)
      - $cost:         array with keys (one of):
                         ['type' => 'cents', 'value' => float]   for total cents (e.g. 24)
                         ['type' => 'rate',  'value' => float]   for c/kWh (e.g. 2.99)
      - $diffPercent:  ?float, signed, vs 30 pv ka (-40.5 = 40.5% halvempi)
      - $savingsEuros:    ?float, euros saved compared to the worst case in the same permitted window
      - $comparisonLabel: string, what "kallein aika" means for this card (e.g.
                          "vs aikavälin kallein tunti", "vs päivän kallein tunti").
                          The label must be honest about whether the comparison is
                          window-bounded (sauna 17–22, dishwasher 18–08) or full-day.
      - $icon:            SVG path d-attribute for the appliance icon
--}}
@php
    $hStart = str_pad($startHour, 2, '0', STR_PAD_LEFT);
    $hEnd   = str_pad(($endHour + 1) % 24, 2, '0', STR_PAD_LEFT);
@endphp
<article class="flex flex-col bg-white rounded-2xl border border-slate-200 p-6 transition-all duration-200 hover:border-slate-300 hover:shadow-sm">
    <header class="flex items-start gap-3 mb-6">
        <span class="bg-slate-100 p-2 rounded-lg shrink-0">
            <svg class="w-5 h-5 text-slate-700" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/>
            </svg>
        </span>
        <div class="min-w-0">
            <h4 class="text-base font-bold text-slate-900 leading-tight">{{ $title }}</h4>
            <p class="text-sm text-slate-500 mt-1">{{ $assumption }}</p>
        </div>
    </header>

    <div class="flex-1">
        <p class="text-3xl lg:text-[2rem] font-extrabold text-slate-900 leading-none tabular-nums">
            {{ $hStart }}:00<span class="text-slate-400 mx-0.5">–</span>{{ $hEnd }}:00
        </p>
        <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
            <span class="text-base font-semibold text-slate-700 tabular-nums">
                @if ($cost['type'] === 'cents')
                    {{ number_format($cost['value'], 0, ',', ' ') }} senttiä
                @else
                    {{ number_format($cost['value'], 2, ',', ' ') }} c/kWh
                @endif
            </span>
            @if ($diffPercent !== null)
                @if ($diffPercent < -5)
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-semibold bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                        {{ number_format(abs($diffPercent), 1, ',', ' ') }} % halvempi
                    </span>
                @elseif ($diffPercent > 5)
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-semibold bg-rose-50 text-rose-700 ring-1 ring-rose-200">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
                        {{ number_format($diffPercent, 1, ',', ' ') }} % kalliimpi
                    </span>
                @else
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-sm font-semibold bg-slate-100 text-slate-600">
                        lähellä 30 pv ka.
                    </span>
                @endif
            @endif
        </div>
    </div>

    @if ($savingsEuros !== null && $savingsEuros > 0)
        <p class="mt-5 pt-4 border-t border-slate-100 text-sm text-slate-600 tabular-nums">
            Säästät <span class="font-semibold text-slate-700">{{ number_format($savingsEuros, 2, ',', ' ') }} €</span> {{ $comparisonLabel }}
        </p>
    @endif
</article>
