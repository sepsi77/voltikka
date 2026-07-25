@props(['band', 'estimate' => null])
{{--
    The contract card's type band. SINGLE PURPOSE: it states the pricing category and
    nothing else. Warnings never go here, however important they are; they render as filled
    coral pills in the footer. A contract whose price rises keeps a truthful fixed band
    ("Kiinteät hinnat · Julkaistu etukäteen, ei sidottu markkinaan") because both prices are
    pre-published, and the increase is a footer warning plus two dated receipt rows.

    The tints are a semantic data-colour axis (sky = follows the market, violet = consumption
    effect), documented in DESIGN.md alongside the emissions tiers. Fixed is deliberately
    slate: certainty is the default state, and colour marks deviation from it. They appear
    only here and only as tints.

    Copy comes from App\Services\ContractCard\ContractCardCopy. Never inline a sentence here.
--}}
@php
    // The hairline gives every band a defined bottom edge. Without it the tints sit only
    // 1.05:1 from the white body, so the fixed (slate) band in particular barely read as a
    // band at all. The rule tracks its own tint so a coloured band never gets a grey edge.
    $tintClass = match ($band->tint()) {
        'market' => 'bg-sky-100 text-sky-700 border-sky-200',
        'usage' => 'bg-violet-100 text-violet-700 border-violet-200',
        default => 'bg-slate-100 text-slate-700 border-slate-200',
    };
@endphp
<div class="flex flex-wrap items-center gap-x-2.5 gap-y-2 border-b px-6 py-3.5 text-sm {{ $tintClass }}">
    <span class="flex-shrink-0" aria-hidden="true">
        @switch($band->icon)
            @case('wave')
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M2 12c2.5 0 2.5-4 5-4s2.5 4 5 4 2.5-4 5-4 2.5 4 5 4"/></svg>
                @break
            @case('pulse')
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg>
                @break
            @default
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>
        @endswitch
    </span>

    <span class="min-w-0">
        <b class="font-bold">{{ $band->headline }}</b>
        @if ($band->detail)
            <span class="px-0.5 font-normal opacity-45" aria-hidden="true">·</span>
            <span class="font-medium opacity-85">{{ $band->detail }}</span>
        @endif
    </span>

    @if ($estimate)
        <span class="ml-auto flex-shrink-0">
            <x-info-popover
                label="Arvio"
                :heading="$estimate->heading"
                :body="$estimate->body"
                :link-url="$estimate->linkUrl"
                :link-text="$estimate->linkText"
            />
        </span>
    @endif
</div>
