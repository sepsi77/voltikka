{{--
    Paw-print divider between the major blocks of /spot-price (design spec §4 "Line language",
    Paper board 11H-0 "Fox tracks divider"): a track of 30 fox prints that fades in from left to
    right. On a phone the track keeps its size and is cut at the right edge. Decoration only.
--}}
@php
    $rows = [29.0, 19.2, 33.1, 22.7, 35.7, 24.0, 35.7, 22.7, 33.1, 19.2, 29.0, 14.8, 24.9, 11.3, 22.3,
        10.0, 22.3, 11.3, 24.9, 14.8, 29.0, 19.2, 33.1, 22.7, 35.7, 24.0, 35.7, 22.7, 33.1, 19.2];
    $toes = [[4.8, -3.4], [5.9, -0.7], [5.9, 2.1], [4.8, 4.6]];
@endphp
<svg {{ $attributes->merge(['class' => 'block h-[46px] w-full']) }} viewBox="0 0 1232 46" preserveAspectRatio="xMinYMid slice" aria-hidden="true" focusable="false">
    <g style="fill: var(--color-ink-muted)">
        @foreach ($rows as $i => $y)
            @php $x = 60 + $i * 38.345; @endphp
            <g opacity="{{ round((77 + $i * 5.28) / 255, 3) }}">
                <ellipse cx="{{ $x }}" cy="{{ $y }}" rx="3.8" ry="3.1" />
                @foreach ($toes as [$dx, $dy])
                    <circle cx="{{ $x + $dx }}" cy="{{ $y + $dy }}" r="1.35" />
                @endforeach
            </g>
        @endforeach
    </g>
</svg>
