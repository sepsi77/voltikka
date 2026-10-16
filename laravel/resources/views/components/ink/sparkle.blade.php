@props(['size' => 13, 'color' => 'currentColor'])
{{-- Four-point inked sparkle before a tag, kicker or legend item (design spec §5 "Inked marks"). --}}
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 18 18" aria-hidden="true" focusable="false" {{ $attributes->merge(['class' => 'inline-block shrink-0']) }}>
    <path d="M9 2 C 9.6 6, 12 8.4, 16 9 C 12 9.6, 9.6 12, 9 16 C 8.4 12, 6 9.6, 2 9 C 6 8.4, 8.4 6, 9 2 Z" style="fill: {{ $color }}" />
</svg>
