@props(['size' => 56])
{{--
    Inked map-pin vignette at the left of a local-contracts heading (Paper 6TD-0, spec §5 "Page
    patterns": a heading of a seller section gets an object vignette, never a villager).
    Misregistered print: a flat fox fill offset from the ink outline. Decorative.
--}}
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 56 56" aria-hidden="true" focusable="false" {{ $attributes->merge(['class' => 'block shrink-0']) }}>
    <path d="M10 47 C 20 45.5, 36 48.5, 47 46.4" fill="none" style="stroke: var(--color-ink)" stroke-width="1.6" stroke-linecap="round" />
    <ellipse cx="28.6" cy="45.6" rx="7" ry="2.2" style="fill: var(--color-fox-deep); opacity: .25" />
    <path d="M29.4 44 C 22 34.6, 16.8 28.6, 16.8 22 A12 12 0 0 1 40.8 22 C 40.8 28.6, 35.8 34.6, 29.4 44 Z" style="fill: var(--color-fox); opacity: .55" />
    <path d="M28 43 C 21 33.8, 15.6 27.8, 15.6 21.2 A12.2 12.2 0 0 1 40 21.2 C 40 27.8, 35 33.8, 28 43 Z" fill="none" style="stroke: var(--color-ink)" stroke-width="1.5" stroke-linejoin="round" />
    <circle cx="27.8" cy="21.2" r="4.6" style="fill: var(--color-ground); stroke: var(--color-ink)" stroke-width="1.3" />
    <path d="M19 26 L23 18 M21.5 30 L26.5 20 M31 30 L35.5 21 M33.5 32 L37 25" fill="none" style="stroke: var(--color-ink); opacity: var(--opacity-hatching)" stroke-width="0.8" stroke-linecap="round" />
</svg>
