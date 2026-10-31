{{--
    Site footer (Paper: "Footer · night village" on the /sahkosopimus v4 board; season variants on
    page "Seasons", "Seasons · footer"). A painted scene band with the fox asleep, then the live
    footer links on the night sky. The art follows the current season and loads lazily.
    Keep every live link, the dynamic contract count, the obfuscated email and the © year.
--}}
@php
    use App\Support\Season;

    $footerContractCount = \App\Livewire\AboutPage::cachedContractCount();

    $columns = [
        'Sähkösopimukset' => [
            ['Kaikki sopimukset', '/'],
            ['Vertaa sopimuksia', '/sahkosopimus'],
            ['Halvimmat sopimukset', '/sahkosopimus/halvin-sahkosopimus'],
            ['Tarjoukset', '/sahkosopimus/sahkotarjous'],
            ['Yrityksille', '/sahkosopimus/yritykselle'],
            ['Sähköyhtiöt', '/sahkosopimus/sahkoyhtiot'],
        ],
        'Laskurit' => [
            ['Arvioi sähkönkulutus', route('calculator')],
            ['Aurinkopaneelilaskuri', '/aurinkopaneelit/laskuri'],
            ['Lämpöpumppulaskuri', '/lampopumput/laskuri'],
        ],
        'Data ja selvitykset' => [
            ['Maksatko liikaa?', '/maksatko-liikaa'],
            ['Sähkösopimusten hintakehitys', '/sahkosopimus/tilastot'],
            ['Sähkön hintaennuste', '/sahkosopimus/sahkon-hintaennuste'],
            ['Kannattaako pörssisähkö?', '/sahkosopimus/kannattaako-porssisahko'],
            ['Kannattaako määräaikainen?', '/sahkosopimus/kannattaako-maaraaikainen'],
            ['Pörssisähkön hinta', '/spot-price'],
        ],
        'Paikkakunnat' => [
            ['Helsinki', '/sahkosopimus/paikkakunnat/helsinki'],
            ['Tampere', '/sahkosopimus/paikkakunnat/tampere'],
            ['Oulu', '/sahkosopimus/paikkakunnat/oulu'],
            ['Turku', '/sahkosopimus/paikkakunnat/turku'],
        ],
    ];
@endphp
<footer data-site-footer class="vk-footer vk-tone-dark mt-auto">
    <div class="vk-footer-scene" aria-hidden="true">
        <img
            src="{{ Season::art('footer') }}"
            srcset="{{ Season::art('footer', 960) }} 960w, {{ Season::art('footer') }} 1672w"
            sizes="100vw"
            width="1672"
            height="941"
            alt=""
            loading="lazy"
            decoding="async"
        >
    </div>

    <div class="bg-night">
        <div class="vk-wrap flex flex-col gap-[30px] pb-[30px] pt-9">
            <div class="grid grid-cols-2 gap-x-6 gap-y-8 md:grid-cols-4 md:gap-10">
                @foreach ($columns as $heading => $links)
                    <div class="flex flex-col gap-2.5">
                        <h3 class="vk-footer-head">{{ $heading }}</h3>
                        <ul class="flex flex-col gap-2.5">
                            @foreach ($links as [$label, $href])
                                <li><a href="{{ $href }}" class="vk-footer-link vk-focus">{{ $label }}</a></li>
                            @endforeach
                            @if ($heading === 'Paikkakunnat')
                                <li><a href="{{ route('locations') }}" class="vk-footer-link vk-footer-link-accent vk-focus">Kaikki paikkakunnat →</a></li>
                            @endif
                        </ul>
                    </div>
                @endforeach
            </div>

            {{-- Identity, publisher and legal row --}}
            <div class="vk-footer-rule flex flex-col gap-8 pt-[22px] md:flex-row md:items-start md:justify-between md:gap-10">
                <div class="flex flex-col gap-2.5 md:w-[360px] md:shrink-0">
                    <a href="/" class="vk-footer-wordmark vk-focus self-start">Voltikka</a>
                    <p class="vk-footer-text">Vertailussa {{ number_format($footerContractCount, 0, ',', ' ') }} sähkösopimusta — yksi Suomen kattavimmista vertailuista.</p>
                    <ul class="flex flex-col gap-1.5 pt-1">
                        <li><a href="/tietoa" class="vk-footer-link vk-focus">Tietoa Voltikasta</a></li>
                        <li><a href="/tietoa#menetelma" class="vk-footer-link vk-focus">Näin laskemme kustannukset</a></li>
                        <li><a href="/tietosuoja" class="vk-footer-link vk-focus">Tietosuoja ja evästeet</a></li>
                        <li><a href="/kayttoehdot" class="vk-footer-link vk-focus">Käyttöehdot</a></li>
                    </ul>
                </div>

                <div class="flex flex-col gap-2 md:w-[620px] md:max-w-[55%]">
                    <p class="vk-footer-text">
                        Voltikka on Seppo Puusan riippumaton energiavertailupalvelu. Se on harrasteprojekti,
                        joka ei ota provisiota sähköyhtiöiltä eikä rahoita toimintaansa mainoksilla.
                        <a href="/tietoa" class="vk-footer-inline-link vk-focus">Lue lisää</a>.
                    </p>
                    <p class="vk-footer-legal">
                        Yhteys:
                        <x-obfuscated-email
                            email="voltikka7@gmail.com"
                            label="Näytä sähköposti"
                            link-class="vk-footer-inline-link vk-focus"
                        />
                        ·
                        <a href="/tietosuoja" class="vk-footer-inline-link vk-focus">Tietosuoja ja evästeet</a>
                        ·
                        <a href="/kayttoehdot" class="vk-footer-inline-link vk-focus">Käyttöehdot</a>
                        · <span class="whitespace-nowrap">&copy; {{ date('Y') }} {{ \App\Livewire\AboutPage::COMPANY_NAME }}, rekisterikoodi {{ \App\Livewire\AboutPage::COMPANY_REGISTRATION_CODE }}</span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</footer>
