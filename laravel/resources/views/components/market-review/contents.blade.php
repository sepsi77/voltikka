@props(['items', 'archive' => [], 'nextLabel' => null])
{{--
    Section list of a market review with the archive under it (Paper "Tässä katsauksessa").
    `archive` holds the other visible reviews, newest first; it is the archive page until the
    list gets long (decisions.md, "URLs").
--}}
<x-site.longread-toc title="Tässä katsauksessa" label="Katsauksen sisältö" :items="$items">
    <div class="mr-archive">
        @if ($archive !== [])
            <p class="vk-lr-toc-title">Aiemmat katsaukset</p>
            <ul class="mr-archive-list">
                @foreach ($archive as $other)
                    <li><a href="/markkinakatsaus/{{ $other['month'] }}" class="vk-focus">{{ $other['label'] }}</a></li>
                @endforeach
            </ul>
        @endif
        @if ($nextLabel)
            <p class="mr-archive-next">Seuraava katsaus ilmestyy noin {{ $nextLabel }}.</p>
        @endif
    </div>
</x-site.longread-toc>
