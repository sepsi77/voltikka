@props(['number', 'caption' => null, 'table' => null])
{{--
    Numbered chart of a market review. The default slot is the interactive chart (uPlot line chart
    or Chart.js bars, drawn from the review's frozen kuva-*.json). `caption` is visible text and
    `table` is the same data as a table in a closed disclosure.
--}}
<figure class="mr-figure">
    <p class="mr-caps mr-caps--fox">Kuva {{ $number }}</p>
    {{ $slot }}
    @if ($caption)
        <figcaption class="mr-figcaption">{{ $caption }}</figcaption>
    @endif
    @if ($table)
        <details class="vk-lr-details">
            <summary>Näytä kuvan luvut</summary>
            <div class="vk-lr-table-wrap">{{ $table }}</div>
        </details>
    @endif
</figure>
