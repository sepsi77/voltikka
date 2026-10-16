@props([
    'label' => '',
    // CSS colour of a lead series (for example var(--color-aurora)); null = pick from the label.
    'lead' => null,
    'width' => 24,
])
{{--
    Legend swatch for a `data-line-chart` series. The colour and dash rules mirror
    inkSeriesStyle() in resources/js/contract-price-statistics.js; keep both in step.
--}}
@php
    $text = mb_strtolower((string) $label);
    [$colour, $dash, $stroke] = match (true) {
        $lead !== null => [$lead, null, 3.4],
        str_contains($text, 'toistaiseksi') => ['var(--color-ink-muted)', '6 4', 2.6],
        str_contains($text, 'pörssi') || str_contains($text, 'marginaali') => ['var(--color-cat-spot)', null, 2.6],
        str_contains($text, 'määräaikai') => ['var(--color-cat-fixed)', null, 2.6],
        (bool) preg_match('/jousto|hybrid|kulutusvaikut/u', $text) => ['var(--color-cat-usage)', null, 2.6],
        (bool) preg_match('/kvartaali|jaksoittain/u', $text) => ['var(--color-cat-periodic)', null, 2.6],
        (bool) preg_match('/mediaani|markkina/u', $text) => ['var(--color-cat-fixed)', '6 4', 2.6],
        default => ['var(--color-ink)', null, 2.6],
    };
@endphp
<svg width="{{ $width }}" height="10" viewBox="0 0 {{ $width }} 10" aria-hidden="true" focusable="false" {{ $attributes->class('shrink-0') }}>
    <line x1="1" y1="5" x2="{{ $width - 1 }}" y2="5" style="stroke: {{ $colour }}" stroke-width="{{ $stroke }}" stroke-linecap="round" @if ($dash) stroke-dasharray="{{ $dash }}" @endif />
</svg>
