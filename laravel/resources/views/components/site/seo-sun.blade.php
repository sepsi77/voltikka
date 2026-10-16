@props(['size' => 56])
{{--
    Inked sun vignette at the left of the city solar-potential heading (same misregistered-print
    style as <x-site.seo-pin>: a flat star-yellow fill offset from the ink outline, light
    hatching). Decorative.
--}}
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 56 56" aria-hidden="true" focusable="false" {{ $attributes->merge(['class' => 'block shrink-0']) }}>
    <path d="M10 47 C 20 45.5, 36 48.5, 47 46.4" fill="none" style="stroke: var(--color-ink)" stroke-width="1.6" stroke-linecap="round" />
    <circle cx="29.2" cy="25.2" r="10.4" style="fill: var(--color-star); opacity: .6" />
    <circle cx="28" cy="24" r="10.2" fill="none" style="stroke: var(--color-ink)" stroke-width="1.5" />
    <path d="M28 6.5 V10.5 M28 37.5 V41.5 M10.5 24 H14.5 M41.5 24 H45.5 M15.6 11.6 L18.4 14.4 M37.6 33.6 L40.4 36.4 M15.6 36.4 L18.4 33.6 M37.6 14.4 L40.4 11.6" fill="none" style="stroke: var(--color-ink)" stroke-width="1.6" stroke-linecap="round" />
    <path d="M21 27 L27 17 M23.5 31 L32 18 M28 32.5 L34.5 22.5" fill="none" style="stroke: var(--color-ink); opacity: var(--opacity-hatching)" stroke-width="0.8" stroke-linecap="round" />
</svg>
