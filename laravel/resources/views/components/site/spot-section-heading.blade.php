@props([
    'title',
    'sub' => null,
    'art' => null,
    'wave' => 320,
    'level' => 'h2',
])
{{--
    Section heading of /spot-price (design spec §5 "Section heading", Paper board 11H-0):
    Bricolage 34/38 with a wavy fox underline and an optional sub line. `art` names an optional
    96px villager vignette at the left of the heading (never floating). The `action` slot sits
    at the right on the heading baseline (for example "Lataa CSV").
--}}
<div {{ $attributes->merge(['class' => 'flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="flex items-center gap-3.5">
        @if ($art)
            <img
                src="{{ \App\Support\Season::art($art) }}"
                alt=""
                width="96"
                height="96"
                loading="lazy"
                decoding="async"
                class="h-[72px] w-[72px] shrink-0 object-contain sm:h-24 sm:w-24"
            >
        @endif
        <div class="flex min-w-0 flex-col gap-0.5">
            <{{ $level }} class="spot-section-title">{{ $title }}</{{ $level }}>
            <x-ink.wave :width="$wave" stroke="3.5" class="max-w-full" />
            @if ($sub)
                <p class="max-w-[720px] text-[15px] leading-[22px] text-ink-body">{{ $sub }}</p>
            @endif
        </div>
    </div>
    @isset($action)
        <div class="shrink-0 sm:pb-1.5">{{ $action }}</div>
    @endisset
</div>
