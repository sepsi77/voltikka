@props(['columns' => []])
{{--
    Link columns (design spec §5 "Link columns", Paper "Katso myös"): column heads in ink 800,
    links in ink-body, four columns on desktop, two on a tablet, one on a phone.

    columns: list of columns; each column is a list of groups:
        [ ['heading' => 'Hinnoittelumalli', 'links' => [['/sahkosopimus/porssisahko', 'Pörssisähkösopimukset'], ...]], ... ]
    A second group in one column (for example "Muut palvelut") gets a little space above it.
--}}
<div {{ $attributes->class(['vk-link-columns']) }}>
    @foreach ($columns as $groups)
        <div class="vk-link-column">
            @foreach ($groups as $group)
                <h3 @class(['vk-link-column-head', 'pt-3' => ! $loop->first])>{{ $group['heading'] }}</h3>
                <ul class="vk-link-column-list">
                    @foreach ($group['links'] as [$href, $label])
                        <li><a href="{{ $href }}" class="vk-link vk-focus">{{ $label }}</a></li>
                    @endforeach
                </ul>
            @endforeach
        </div>
    @endforeach
</div>
