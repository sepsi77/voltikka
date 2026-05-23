@props(['insight' => null])

@php
    $items = [];
    if (!empty($insight['has_items'])) {
        foreach (['trend', 'forecast'] as $itemKey) {
            if (!empty($insight[$itemKey])) {
                $items[] = $insight[$itemKey];
            }
        }
    }
@endphp

@if(count($items) > 0)
    <div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2']) }}>
        @foreach($items as $item)
            @php
                $tone = $item['tone'] ?? 'neutral';
                $toneClass = 'bg-white/5 border-white/10 text-slate-100';
                $iconClass = match ($tone) {
                    'up' => 'text-coral-300',
                    'down' => 'text-emerald-300',
                    default => 'text-slate-300',
                };
            @endphp
            <a href="{{ $item['url'] }}"
               class="group inline-flex max-w-full items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold backdrop-blur-sm transition-colors hover:border-coral-400/50 hover:bg-white/10 {{ $toneClass }}"
            >
                <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10 {{ $iconClass }}">
                    @if($tone === 'up')
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                        </svg>
                    @elseif($tone === 'down')
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    @else
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
                        </svg>
                    @endif
                </span>
                <span class="min-w-0 truncate">{{ $item['headline'] }}</span>
                <span class="hidden text-slate-300 sm:inline">{{ $item['detail'] }}</span>
                <span class="sr-only">{{ $item['link_label'] ?? 'Lue lisää' }}</span>
            </a>
        @endforeach
    </div>
@endif
