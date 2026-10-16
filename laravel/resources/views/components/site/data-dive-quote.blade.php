@props([
    'dive',
    'citation' => '',
    'description' => '',
])
{{--
    Lower part of one contract-type cell on /sahkosopimus/tilastot (round 3, Paper "R3 · Data ·
    Tilastot"): the quotable claim, an optional slot (the spot margin chart), the type
    description and the inked "Kopioi sitaatti" action last. The copy behaviour
    (window.voltikkaQuote, "Quote Copied" event) is unchanged.
    A "Vs. pörssisähkö" claim shows its label and figure; a claim that only repeats the key
    figures above (energy price now, change since the start) shows the sentence alone.
--}}
@php
    $quotable = $dive['quotable'] ?? null;
    $tone = $quotable['tone'] ?? 'neutral';
    $toneClass = match ($tone) {
        'down' => 'data-delta--down',
        'up' => 'data-delta--up',
        default => 'data-delta--ink',
    };
    $showFigure = $quotable && str_starts_with($quotable['headline_label'], 'Vs.');
@endphp
@if ($quotable)
    <div {{ $attributes->class(['data-quote-block']) }}
         x-data="voltikkaQuote({
             sentence: @js($quotable['sentence_plain']),
             citation: @js($citation),
             segment: @js($dive['anchor']),
         })">
        <figure class="data-vs {{ $showFigure ? '' : 'data-vs--sentence' }}">
            @if ($showFigure)
                <div class="data-vs-head">
                    <p class="data-vs-label">{{ $quotable['headline_label'] }}</p>
                    <p class="data-vs-figure {{ $toneClass }}">{{ $quotable['headline'] }}</p>
                </div>
            @endif
            <blockquote class="data-vs-sentence">
                {{ $quotable['sentence_before'] }}<span class="font-extrabold tabular-nums {{ $toneClass }}">{{ $quotable['sentence_highlight'] }}</span>{{ $quotable['sentence_after'] }}
            </blockquote>
        </figure>

        {{ $slot }}

        @if ($description)
            <p class="data-type-note">{{ $description }}</p>
        @endif

        <button type="button"
                @click="copy()"
                class="data-copy data-copy--cell vk-focus"
                :aria-label="copied ? 'Kopioitu' : 'Kopioi sitaatti'">
            <span x-text="copied ? 'Kopioitu' : 'Kopioi sitaatti'">Kopioi sitaatti</span>
            <x-ink.wave width="full" stroke="2" />
        </button>
    </div>
@else
    <div {{ $attributes->class(['data-quote-block']) }}>
        {{ $slot }}

        @if ($description)
            <p class="data-type-note">{{ $description }}</p>
        @endif
    </div>
@endif
