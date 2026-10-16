@props(['variant' => 'piece'])
{{--
    Painted season ground line under the /spot-price villagers (design spec §2
    --color-season-ground-*, Paper board EBO-0 "Ground line · <season>"). `row` is the full
    1232px line behind the five villagers on a laptop; `piece` is a short line under one
    villager on a phone. Spring and summer add inked grass tufts and flowers; autumn adds leaves.
    Decoration only.
--}}
@php
    $season = \App\Support\Season::current();
    $row = $variant === 'row';
    // Tuft and decoration positions: board values for the row, a short repeat for a piece.
    $xs = $row ? [90, 372, 612, 858, 1104] : [28, 150];
@endphp
<svg {{ $attributes->merge(['class' => 'block']) }} viewBox="0 0 {{ $row ? 1232 : 180 }} 26" preserveAspectRatio="none" aria-hidden="true" focusable="false">
    @if ($row)
        <path d="M0 14 C 150 6, 300 18, 470 12 S 820 6, 980 13 S 1160 16, 1232 10 L1232 26 L0 26 Z" style="fill: var(--color-season-ground-{{ $season }})" />
    @else
        <path d="M0 14 C 40 8, 80 17, 120 12 S 165 9, 180 12 L180 26 L0 26 Z" style="fill: var(--color-season-ground-{{ $season }})" />
    @endif

    @if ($season === 'kevat' || $season === 'kesa')
        <g fill="none" style="stroke: var(--color-aurora); opacity: .72" stroke-width="1.3" stroke-linecap="round">
            @foreach ($xs as $x)
                <path d="M{{ $x }} 12 l2 -7 M{{ $x + 4 }} 12 l0 -8 M{{ $x + 8 }} 12 l-2 -6" />
            @endforeach
        </g>
        @foreach ($xs as $i => $x)
            @if ($season === 'kevat')
                <circle cx="{{ $x + 12 }}" cy="7" r="2.2" style="fill: var(--color-star)" />
            @elseif ($i % 2 === 0)
                <circle cx="{{ $x + 12 }}" cy="5" r="2.4" stroke-width="1.2" style="fill: var(--color-snow-white); stroke: var(--color-star)" />
            @else
                <circle cx="{{ $x + 12 }}" cy="5" r="2.2" style="fill: var(--color-violet); opacity: .8" />
            @endif
        @endforeach
    @elseif ($season === 'syksy')
        @foreach ($xs as $i => $x)
            <path d="M{{ $x + 30 }} {{ 14 + ($i % 2) }} c3 -4 8 -4 10 0 c-3 3 -7 3 -10 0 Z" style="fill: {{ $i % 3 === 1 ? 'var(--color-star)' : 'var(--color-fox)' }}; opacity: .8" />
        @endforeach
    @endif
</svg>
