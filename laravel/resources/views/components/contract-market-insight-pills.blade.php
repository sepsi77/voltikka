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

    $latestDate = null;
    foreach ($items as $item) {
        $candidate = $item['as_of'] ?? $item['forecast_date'] ?? null;
        if (!$candidate) {
            continue;
        }
        try {
            $parsed = Carbon::parse($candidate);
        } catch (\Throwable $e) {
            continue;
        }
        if ($latestDate === null || $parsed->greaterThan($latestDate)) {
            $latestDate = $parsed;
        }
    }

    $fiMonths = ['tammi','helmi','maalis','huhti','touko','kesä','heinä','elo','syys','loka','marras','joulu'];
    $provenanceDate = $latestDate
        ? sprintf('%d. %skuuta', (int) $latestDate->format('j'), $fiMonths[(int) $latestDate->format('n') - 1])
        : null;

    $contractCount = $insight['trend']['contract_count'] ?? null;
@endphp

@if(count($items) > 0)
    <div {{ $attributes->merge(['class' => 'max-w-2xl']) }}>
        <div class="overflow-hidden rounded-2xl bg-white/[0.04] ring-1 ring-white/10 backdrop-blur-sm">
            {{-- Provenance eyebrow --}}
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-white/10 bg-white/[0.02] px-5 py-3 sm:px-6 sm:py-3.5">
                <div class="flex items-center gap-2.5">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-coral-400" aria-hidden="true"></span>
                    <span class="text-[13px] font-semibold uppercase tracking-[0.14em] text-slate-200">
                        Voltikan markkinadata
                    </span>
                </div>
                @if($provenanceDate)
                    <span class="text-[13px] font-medium text-slate-300 tabular-nums">
                        Päivitetty {{ $provenanceDate }}
                    </span>
                @endif
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
                    @endphp

                    <a href="{{ $item['url'] }}"
                       class="group relative block px-5 py-4 sm:px-6 sm:py-5 transition-colors hover:bg-white/[0.04] focus-visible:bg-white/[0.04] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coral-400/60"
                       aria-label="{{ $eyebrow }}: {{ $focal }}. {{ $supporting }}. {{ $item['link_label'] ?? 'Lue lisää' }}.">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-[13px] font-semibold uppercase tracking-[0.12em] text-slate-300">
                                {{ $eyebrow }}
                            </span>
                            <span class="inline-flex items-center gap-1 text-xs font-medium text-slate-400 transition-colors group-hover:text-coral-300">
                                <span>{{ $item['link_label'] ?? 'Lue lisää' }}</span>
                                <svg class="h-3 w-3 transition-transform group-hover:translate-x-0.5"
                                     fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.25" d="M5 12h14M13 5l7 7-7 7"/>
                                </svg>
                            </span>
                        </div>

                        <div class="mt-3 flex items-center gap-2.5">
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

                        @if($supporting)
                            <p class="mt-3 text-sm text-slate-300">{{ $supporting }}</p>
                        @endif
                    </a>
                @endforeach
            </div>

            {{-- Source footer --}}
            @if($contractCount)
                <div class="border-t border-white/10 bg-white/[0.02] px-5 py-3 sm:px-6">
                    <p class="text-[13px] text-slate-300">
                        Perustuu <span class="font-semibold tabular-nums text-slate-100">{{ number_format($contractCount, 0, ',', ' ') }}</span> sähkösopimuksen päivittäiseen hintatilastoon.
                    </p>
                </div>
            @endif
        </div>
    </div>
@endif
