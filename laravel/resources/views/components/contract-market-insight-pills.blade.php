@props(['insight' => null])

@php
    use Carbon\Carbon;

    $items = [];
    if (!empty($insight['has_items'])) {
        foreach (['trend', 'forecast'] as $itemKey) {
            if (!empty($insight[$itemKey])) {
                $items[] = $insight[$itemKey];
            }
        }
    }

    $contractCount = $insight['trend']['contract_count'] ?? null;
@endphp

@if(count($items) > 0)
    <div {{ $attributes->merge(['class' => 'w-full min-w-0 max-w-2xl']) }}>
        <div class="overflow-hidden rounded-2xl bg-white/[0.04] ring-1 ring-white/10 backdrop-blur-sm">
            {{-- Provenance eyebrow --}}
            <div class="flex items-center gap-2.5 border-b border-white/10 bg-white/[0.02] px-5 py-2.5 sm:px-6 sm:py-3.5">
                <span class="inline-block h-1.5 w-1.5 rounded-full bg-coral-400" aria-hidden="true"></span>
                <span class="text-sm font-semibold uppercase tracking-[0.12em] text-slate-200">
                    Voltikan markkinadata
                </span>
            </div>

            {{-- Stat cells --}}
            <div @class([
                'grid grid-cols-1 divide-y divide-white/10 sm:divide-x sm:divide-y-0',
                'sm:grid-cols-2' => count($items) >= 2,
                'sm:grid-cols-1' => count($items) === 1,
            ])>
                @foreach($items as $item)
                    @php
                        $type = $item['type'] ?? 'trend';
                        $tone = $item['tone'] ?? 'neutral';
                        $eyebrow = $item['eyebrow']
                            ?? ($type === 'forecast' ? '12 kk ennuste' : '30 päivän trendi');

                        if ($type === 'trend') {
                            $focal = $item['change_label']
                                ?? (isset($item['change_percent'])
                                    ? sprintf(
                                        '%s%s %%',
                                        $item['change_percent'] > 0 ? '+' : ($item['change_percent'] < 0 ? '−' : '±'),
                                        number_format(abs((float) $item['change_percent']), 1, ',', ' ')
                                    )
                                    : '—');
                            $supporting = $item['supporting'] ?? $item['headline'] ?? '';
                        } else {
                            $focal = $item['direction_label']
                                ?? trim(preg_replace('/^Ennuste:\s*/u', '', (string)($item['headline'] ?? '—')));
                            $focal = mb_convert_case($focal, MB_CASE_TITLE, 'UTF-8');
                            $supporting = $item['supporting'] ?? $item['detail'] ?? '';
                        }

                        $itemDateLabel = null;
                        $itemDate = $item['as_of'] ?? $item['forecast_date'] ?? null;
                        if ($itemDate) {
                            try {
                                $itemDateLabel = Carbon::parse($itemDate)->format('j.n.Y');
                            } catch (\Throwable $e) {
                                $itemDateLabel = null;
                            }
                        }
                    @endphp

                    <a href="{{ $item['url'] }}"
                       class="group relative block px-5 py-3 sm:px-6 sm:py-5 transition-colors hover:bg-white/[0.04] focus-visible:bg-white/[0.04] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coral-400/60"
                       aria-label="{{ $eyebrow }}: {{ $focal }}. {{ $supporting }}. {{ $itemDateLabel ? 'Päivitetty '.$itemDateLabel.'.' : '' }} {{ $item['link_label'] ?? 'Lue lisää' }}.">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-semibold uppercase tracking-[0.1em] text-slate-300">
                                {{ $eyebrow }}
                            </span>
                            <span class="inline-flex items-center gap-1 text-sm font-medium text-slate-300 transition-colors group-hover:text-coral-300">
                                <span>{{ $item['link_label'] ?? 'Lue lisää' }}</span>
                                <svg class="h-3 w-3 transition-transform group-hover:translate-x-0.5"
                                     fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.25" d="M5 12h14M13 5l7 7-7 7"/>
                                </svg>
                            </span>
                        </div>

                        <div class="mt-2 flex items-center gap-2.5">
                            @if($tone === 'up')
                                <svg class="h-5 w-5 shrink-0 text-slate-100" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.25" d="M6 17L18 7M18 7H9M18 7v9"/>
                                </svg>
                            @elseif($tone === 'down')
                                <svg class="h-5 w-5 shrink-0 text-slate-100" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.25" d="M6 7l12 10M18 17H9M18 17V8"/>
                                </svg>
                            @else
                                <svg class="h-5 w-5 shrink-0 text-slate-100" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 12h14"/>
                                </svg>
                            @endif
                            <span class="text-3xl font-extrabold leading-none text-white tabular-nums sm:text-[2rem]">
                                {{ $focal }}
                            </span>
                        </div>

                        @if($supporting || $itemDateLabel)
                            <p class="mt-2 text-sm text-slate-300">
                                {{ $supporting }}
                                @if($itemDateLabel)
                                    <span aria-hidden="true"> · </span><span class="whitespace-nowrap text-slate-200">Päivitetty {{ $itemDateLabel }}</span>
                                @endif
                            </p>
                        @endif
                    </a>
                @endforeach
            </div>

            {{-- Source footer --}}
            @if($contractCount)
                <div class="border-t border-white/10 bg-white/[0.02] px-5 py-2.5 sm:px-6 sm:py-3">
                    <p class="text-sm text-slate-300">
                        Hintakehitys perustuu <span class="font-semibold tabular-nums text-slate-100">{{ number_format($contractCount, 0, ',', ' ') }}</span> sopimukseen.
                    </p>
                </div>
            @endif
        </div>
    </div>
@endif
