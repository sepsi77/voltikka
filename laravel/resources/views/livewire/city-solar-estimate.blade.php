<div>
    {{-- City solar potential (redesign): a short block on the page ground, no card. An inked
         sun vignette at the left of the heading (spec §5 "Section heading"), the estimate as a
         sentence with the figure in ink, and an inked action to the solar calculator. --}}
    @if($solarEstimate)
    <section class="vk-seo-solar" aria-labelledby="city-solar-title">
        <x-site.seo-sun class="vk-seo-solar-sun" />
        <div class="min-w-0">
            <x-site.section-heading id="city-solar-title" size="sub" :wave="150">
                Aurinkosähkön tuottopotentiaali {{ $cityLocative ?: $citySlug }}
            </x-site.section-heading>
            <p class="vk-seo-solar-text">
                {{ number_format($solarEstimate['system_kwp'], 1, ',', ' ') }} kWp aurinkopaneelijärjestelmä tuottaisi arviolta
                <strong class="vk-seo-solar-figure">{{ number_format($solarEstimate['annual_kwh'], 0, ',', ' ') }}&nbsp;kWh</strong>
                vuodessa.
            </p>
            <a href="/aurinkopaneelit/laskuri" class="vk-action vk-seo-solar-action vk-focus">
                <span>Laske tarkka tuotto omalle kotillesi &rarr;</span>
                <x-ink.wave width="230" stroke="2.6" />
            </a>
        </div>
    </section>
    @endif
</div>
