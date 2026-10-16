{{--
    Body of the 404 page (Paper AO6-0 "404 — sivua ei löytynyt", seasons EDN-0): the fox lost in
    the forest, then four paths back. Rendered by resources/views/errors/404.blade.php, inside the
    site layout or, if the layout cannot render, inside a minimal page. No database access here.
    The weather word of the lead follows the season (design spec §7 "Seasonal copy").
--}}
@php
    use App\Support\Season;

    $trail = match (Season::current()) {
        Season::KEVAT => 'sulamisveteen',
        Season::KESA => 'saniaisiin',
        Season::SYKSY => 'lehtien alle',
        default => 'lumeen',
    };

    $paths = [
        ['Vertaa sähkösopimuksia', '/sahkosopimus', 'Kaikki sopimukset oman kulutuksesi mukaan.'],
        ['Pörssisähkön hinta nyt', '/spot-price', 'Tämän päivän ja huomisen tuntihinnat.'],
        ['Maksatko liikaa?', '/maksatko-liikaa', 'Vertaa omaa sähkölaskuasi markkinaan.'],
        ['Etusivulle', '/', 'Voltikan kaikki vertailut ja laskurit.'],
    ];
@endphp
<div data-not-found-page>
    <x-site.longread-hero variant="404" :tone="Season::heroTone('404-forest')" plate="404-forest">
        <p class="vk-lr-404" aria-hidden="true">404</p>
        <h1 class="vk-hero-h1">Sivua ei löytynyt</h1>
        <p class="vk-hero-lead !mt-0 max-w-[520px]">
            Etsimäsi sivu on siirtynyt tai sitä ei ole enää. Polku katosi {{ $trail }} – tästä pääset takaisin.
        </p>
    </x-site.longread-hero>

    <nav class="vk-wrap vk-404-paths" aria-labelledby="not-found-paths-heading">
        <x-site.section-heading id="not-found-paths-heading" wave="240">Mihin olit menossa?</x-site.section-heading>

        <ul class="vk-404-links">
            @foreach ($paths as $index => [$label, $href, $text])
                <li class="vk-404-link {{ $index === 0 ? 'vk-404-link--primary' : '' }}">
                    <a href="{{ $href }}" class="vk-404-link-title vk-focus">
                        <span>{{ $label }} <span aria-hidden="true">→</span></span>
                        <x-ink.wave width="full" stroke="2.6" :color="$index === 0 ? 'var(--color-fox)' : 'var(--color-ink)'" />
                    </a>
                    <p class="vk-404-link-text">{{ $text }}</p>
                </li>
            @endforeach
        </ul>

        {{-- A short track of fox paw prints (Paper "Paw-print trail"). Decoration only. --}}
        <svg class="h-10 w-[480px] max-w-full" viewBox="0 0 480 40" aria-hidden="true" focusable="false">
            <g style="fill: var(--color-fox); opacity: .55">
                @foreach ([[60, 26], [190, 14], [320, 28], [450, 16]] as [$x, $y])
                    <ellipse cx="{{ $x }}" cy="{{ $y }}" rx="5" ry="6.5" />
                    <circle cx="{{ $x - 7 }}" cy="{{ $y - 10 }}" r="2.4" />
                    <circle cx="{{ $x }}" cy="{{ $y - 13 }}" r="2.4" />
                    <circle cx="{{ $x + 7 }}" cy="{{ $y - 10 }}" r="2.4" />
                @endforeach
            </g>
        </svg>
    </nav>
</div>
