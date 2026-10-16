@props(['width' => 86, 'stroke' => 2.6, 'color' => 'var(--color-fox)'])
{{--
    Hand-inked wavy underline (design spec §4 "Line language"): 3.5 under a section heading,
    2.6 under an inked action, 3 under the current page. `width` is px, or 'full' for the
    width of the parent. The stroke keeps its width when the path stretches.
--}}
<svg
    @if ($width === 'full') width="100%" @else width="{{ $width }}" @endif
    height="12" viewBox="0 0 100 12" preserveAspectRatio="none" aria-hidden="true" focusable="false"
    {{ $attributes->merge(['class' => 'block shrink-0']) }}
>
    <path d="M3 8 C 18 3, 35 11, 54 6 S 87 3, 97 7" fill="none" style="stroke: {{ $color }}" stroke-width="{{ $stroke }}" stroke-linecap="round" vector-effect="non-scaling-stroke" />
</svg>
