@props(['size' => 14])
{{-- Inked star on the cheapest hour of the day (design spec §5 "Inked marks": --color-star fill, never text). --}}
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 14 14" aria-hidden="true" focusable="false" {{ $attributes->merge(['class' => 'inline-block shrink-0']) }}>
    <path d="M7 1l1.8 3.8 4.1.5-3 2.8.8 4.1L7 10.2 3.3 12.2l.8-4.1-3-2.8 4.1-.5z" style="fill: var(--color-star)" />
</svg>
