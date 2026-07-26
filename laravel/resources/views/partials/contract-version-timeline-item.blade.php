{{--
    One version node of the contract detail page's version timeline.

    Extracted so the timeline can render its three newest versions on the page
    and the rest inside a <details> without two copies of this markup drifting
    apart. Deltas are always shown in the component's own unit (c/kWh or €/kk),
    never as a percentage: two consecutive "-8 %" rows read as a copy-paste bug.

    @param array  $entry          one row of ContractDetail::$contractHistory + delta_to_previous
    @param bool   $showConnector  draw the vertical line down to the next node
    @param string $deltaUnit      unit label for the delta chip
    @param string $deltaSubject   what the delta describes, for example "Energianhinta"
--}}
<li class="relative pl-7 sm:pl-8 {{ $showConnector ? 'pb-6' : 'pb-0' }}">
    @if ($showConnector)
        <span aria-hidden="true" class="absolute left-[7px] top-5 bottom-0 w-[2px] bg-slate-200 rounded-full"></span>
    @endif

    <span aria-hidden="true" class="absolute left-0 top-1.5 flex items-center justify-center w-4 h-4">
        @if ($entry['is_current'] && $entry['is_active'])
            <span class="block w-3 h-3 rounded-full bg-slate-900 ring-4 ring-slate-100"></span>
        @else
            <span class="block w-2.5 h-2.5 rounded-full bg-slate-300 ring-[3px] ring-slate-100"></span>
        @endif
    </span>

    <div class="space-y-1.5">
        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
            @if ($entry['latest_price_date'])
                <time datetime="{{ $entry['latest_price_date']->format('Y-m-d') }}" class="text-sm font-semibold text-slate-900 tabular-nums">
                    {{ $entry['latest_price_date']->translatedFormat('j.n.Y') }}
                </time>
            @else
                <span class="text-sm font-semibold text-slate-500">Päivämäärä ei tiedossa</span>
            @endif

            @if ($entry['is_current'] && $entry['is_active'])
                <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700 ring-1 ring-inset ring-slate-300">
                    Nykyinen
                </span>
            @endif
        </div>

        <div class="text-sm font-medium text-slate-800">{{ $entry['name'] }}</div>

        @if (! empty($entry['prices']))
            <dl class="flex flex-wrap gap-x-5 gap-y-1 pt-0.5 tabular-nums">
                @foreach ($entry['prices'] as $price)
                    <div class="flex items-baseline gap-1.5">
                        <dt class="text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">{{ $price['label'] }}</dt>
                        <dd class="text-sm font-semibold text-slate-900">
                            {{ number_format($price['price'], 2, ',', '') }}
                            <span class="text-slate-400 font-normal text-[11px] ml-0.5">{{ $price['unit'] === 'EUR/kk' ? '€/kk' : $price['unit'] }}</span>
                        </dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if (! empty($entry['promotion']))
            <p class="mt-1.5 inline-flex items-center gap-1.5 text-xs text-coral-800 bg-coral-50 px-2.5 py-1 rounded-md ring-1 ring-inset ring-coral-200">
                {{ $entry['promotion'] }}
            </p>
        @endif
    </div>

    @if ($showConnector && $entry['delta_to_previous'] !== null)
        <div class="mt-3 -mb-1 inline-flex items-center gap-1.5">
            <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 tabular-nums">
                {{ $entry['delta_to_previous'] > 0 ? '+' : '' }}{{ number_format($entry['delta_to_previous'], 2, ',', '') }}
                {{ $deltaUnit }}
            </span>
            <span class="text-[11px] text-slate-400">{{ $deltaSubject }} muuttui</span>
        </div>
    @endif
</li>
