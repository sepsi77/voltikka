@props(['color' => 'var(--color-edge-strong)'])
{{-- Hand-inked 1.5px hairline between ledger entries and FAQ rows. Full width of the parent. --}}
<svg width="100%" height="8" viewBox="0 0 1232 8" preserveAspectRatio="none" aria-hidden="true" focusable="false" {{ $attributes->merge(['class' => 'block']) }}>
    <path d="M0 4 C 200 2, 400 6, 616 4 S 1030 2, 1232 4" fill="none" style="stroke: {{ $color }}" stroke-width="1.5" stroke-linecap="round" vector-effect="non-scaling-stroke" />
</svg>
