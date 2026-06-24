<div>
    {{-- Structured data: WebApplication + FAQPage (FAQPage from getFaqItemsProperty) --}}
    <x-schema-markup :schemas="[$jsonLd, $faqJsonLd]" />

    {{-- Hero --}}
    <section class="bg-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="py-12 lg:py-16 text-center">
                <h1 class="text-3xl md:text-4xl xl:text-5xl font-extrabold text-white tracking-tight leading-none mb-4">
                    Maksatko sähköstä <span class="text-coral-400">liikaa?</span>
                </h1>
                <p class="max-w-2xl mx-auto text-slate-300 md:text-lg">
                    Syötä sähkölaskusi tiedot — laskuri näyttää heti, mitä olisit maksanut muilla sopimuksilla ja kuinka paljon säästäisit vaihtamalla. Ei vuosikulutusta tarvita.
                </p>
            </div>
        </div>
    </section>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        {{-- Breadcrumb --}}
        <nav class="pb-6" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <li><a href="/" class="hover:text-slate-900">Etusivu</a></li>
                <li aria-hidden="true" class="text-slate-300">/</li>
                <li><a href="/sahkosopimus" class="hover:text-slate-900">Sähkösopimukset</a></li>
                <li aria-hidden="true" class="text-slate-300">/</li>
                <li class="text-slate-900 font-medium" aria-current="page">Maksatko liikaa?</li>
            </ol>
        </nav>

        {{-- Intro --}}
        <p class="mb-8 max-w-3xl text-slate-600">
            Vertaa sähkölaskuasi markkinoiden sopimuksiin. Laskuri käyttää samaa jaksoa ja kulutusta jokaiselle sopimukselle, joten näet tarkalleen, mitä olisit maksanut kilpailijalla. Säästöarvio huomioi kausivaihtelun, kun kerrot, onko sähkölämmitys mukana.
        </p>

        {{-- Form --}}
        <section class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-8">
            <div class="mb-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-coral-600 mb-1">Laskun tiedot</p>
                <h2 class="text-xl font-bold text-slate-900">Syötä sähkölaskusi tiedot</h2>
                <p class="text-sm text-slate-500 mt-1">Kaikki löytyy sähkölaskulta — ei vuosikulutusta tarvita.</p>
            </div>

            {{-- Period --}}
            <div class="mb-6">
                <label class="block text-sm font-medium text-slate-700 mb-2">Laskutusjakso</label>
                <div class="flex flex-wrap gap-2 mb-3">
                    @foreach ($presetLabels as $key => $label)
                        <button
                            type="button"
                            wire:click="setPeriodPreset('{{ $key }}')"
                            class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ $periodPreset === $key ? 'bg-coral-500 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}"
                        >{{ $label }}</button>
                    @endforeach
                </div>
                <div class="grid grid-cols-2 gap-3 max-w-md">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Alkaa</label>
                        <input
                            type="date"
                            wire:model.live.debounce.300ms="startDate"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                        >
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Päättyy</label>
                        <input
                            type="date"
                            wire:model.live.debounce.300ms="endDate"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                        >
                    </div>
                </div>
            </div>

            {{-- Required numbers --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Kulutus (kWh)</label>
                    <input
                        type="number"
                        wire:model.live.debounce.500ms="kwh"
                        min="1"
                        step="1"
                        placeholder="esim. 400"
                        class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                    >
                    <p class="text-xs text-slate-400 mt-1">Laskun kulutus jakson ajalta.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Maksettu yhteensä (€)</label>
                    <input
                        type="number"
                        wire:model.live.debounce.500ms="totalEur"
                        min="0"
                        step="0.01"
                        placeholder="esim. 35,00"
                        class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                    >
                    <p class="text-xs text-slate-400 mt-1">Vain sähkösopimus — ei sähkön siirtoa.</p>
                </div>
            </div>

            {{-- Toggles --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <label class="flex items-start gap-3 p-3 rounded-lg border border-slate-200 cursor-pointer hover:bg-slate-50">
                    <input type="checkbox" wire:model.live="includesVat" class="mt-1 w-4 h-4 rounded border-slate-300 text-coral-500 focus:ring-coral-500">
                    <span>
                        <span class="block text-sm font-medium text-slate-700">Hinta sisältää ALV:n</span>
                        <span class="block text-xs text-slate-400">useimmat syöttävät verollisen hinnan. Poista rasti, jos laskussa on veroton hinta.</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 p-3 rounded-lg border border-slate-200 cursor-pointer hover:bg-slate-50">
                    <input type="checkbox" wire:model.live="includesHeating" class="mt-1 w-4 h-4 rounded border-slate-300 text-coral-500 focus:ring-coral-500">
                    <span>
                        <span class="block text-sm font-medium text-slate-700">Sähkölämmitys mukana</span>
                        <span class="block text-xs text-slate-400">Suora sähkölämmitys, ilmalämpöpumppu tai maalämpö. Vaikuttaa vuosisäästön arvioon.</span>
                    </span>
                </label>
            </div>

            {{-- Helper / siirto clarification --}}
            <div class="rounded-lg bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600 mb-6">
                <p class="font-medium text-slate-700 mb-1">Mikä hinta syötetään?</p>
                <p>Syötä <strong>vain sähkösopimuksen hinta</strong> — ei sähkön siirtoa eikä veroja, jotka tulevat erillisellä laskulla. Yhdistelmälaskulla etsi "sähkö" tai "energia" -rivi. Siirto on verkkoyhtiön laskuttamaa eikä sitä voi säästää vaihtamalla sopimusta.</p>
            </div>

            {{-- Optional explanation inputs --}}
            <details class="group">
                <summary class="cursor-pointer text-sm font-medium text-slate-700 hover:text-slate-900 flex items-center gap-2">
                    <svg class="w-4 h-4 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    Tiedän vuosikulutukseni tai haluan selityksen (valinnainen)
                </summary>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4 pl-6">
                    <div class="md:col-span-1">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Vuosikulutus (kWh)</label>
                        <input
                            type="number"
                            wire:model.live.debounce.500ms="annualKwh"
                            min="0"
                            step="1"
                            placeholder="esim. 18000"
                            class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                        >
                        <p class="text-xs text-slate-400 mt-1">Parantaa säästöarviota — käytetään vuosilaskennassa suoraan.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Energiahinta (c/kWh)</label>
                        <input
                            type="number"
                            wire:model.live.debounce.500ms="energyPriceCents"
                            min="0"
                            step="0.01"
                            placeholder="esim. 12,40"
                            class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Perusmaksu (€/kk)</label>
                        <input
                            type="number"
                            wire:model.live.debounce.500ms="baseFeeEur"
                            min="0"
                            step="0.01"
                            placeholder="esim. 9,90"
                            class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                        >
                    </div>
                </div>
                <p class="text-xs text-slate-400 mt-2 pl-6">Vuosikulutus parantaa säästöarviota; energiahinta ja perusmaksu käytetään vain selitykseen.</p>
            </details>
        </section>

        @if ($errorMessage)
            <div class="rounded-xl bg-red-50 border border-red-200 text-red-700 px-4 py-3 mb-6 text-sm">
                {{ $errorMessage }}
            </div>
        @endif

        {{-- Quiet, non-blocking recalculation status --}}
        <div wire:loading.delay class="fixed bottom-4 right-4 z-40 inline-flex items-center gap-2 rounded-full bg-slate-900 text-white text-sm font-medium px-4 py-2 shadow-lg">
            <x-spinner size="h-4 w-4" color="text-coral-400" label="Lasketaan vertailua" />
            Lasketaan vertailua…
        </div>

        {{-- Results --}}
        @if ($this->hasResults && $resultArray)
        <div wire:loading.delay.class="opacity-50" class="transition-opacity duration-200 motion-reduce:transition-none">
            @php
                $res = $resultArray;
                $userRow = null;
                $userRowIndex = null;
                foreach ($res['rows'] as $i => $row) {
                    if ($row['is_user']) { $userRow = $row; $userRowIndex = $i; }
                }
                $topRows = [];
                foreach ($res['rows'] as $i => $row) {
                    if (count($topRows) < 10) { $topRows[] = ['row' => $row, 'rank' => $i + 1]; }
                }
                // If the user ranks outside the top 10, append their row so they
                // can always see where they landed.
                $showUserTail = $userRow !== null && $userRowIndex >= 10;
                $userRank = $res['user_rank'];
                $total = $res['total_contracts'];
                $cheaperPct = $total > 1 ? round(($userRank - 1) / max(1, $total - 1) * 100) : 0;
            @endphp

            {{-- Verdict hero --}}
            <section class="rounded-2xl {{ $res['is_overpaying'] ? 'bg-slate-950' : 'bg-emerald-50 border border-emerald-200' }} p-8 mb-8 text-center">
                @if ($res['is_overpaying'])
                    <p class="text-sm font-semibold uppercase tracking-wide text-coral-400 mb-2">Maksat liikaa</p>
                    <p class="text-slate-300 mb-1">Sopimuksesi on kalliimpi kuin <strong class="text-white">{{ $cheaperPct }} %</strong> markkinoiden sopimuksista.</p>
                    <div class="my-6">
                        <p class="text-slate-400 text-sm">Säästäisit arviolta</p>
                        <p class="text-4xl md:text-5xl font-extrabold text-coral-400">{{ number_format(abs($res['monthly_saving_eur']), 0, ',', ' ') }} €/kk</p>
                        <p class="text-slate-400 text-sm mt-1">≈ {{ number_format(abs($res['annual_saving_eur']), 0, ',', ' ') }} €/vuosi (arvio)</p>
                    </div>
                    <p class="text-xs text-slate-500">Tällä jaksolla säästäisit tarkalleen noin {{ number_format(abs($res['period_saving_eur']), 0, ',', ' ') }} € vaihtamalla halvimpaan.</p>
                @else
                    <p class="text-sm font-semibold uppercase tracking-wide text-emerald-700 mb-2">Sopimuksesi on kilpailukykyinen</p>
                    <p class="text-emerald-900">Sopimuksesi on markkinoiden halvimmasta {{ $cheaperPct }} %:sta. Vaihtaminen ei tällä kulutuksella tuota merkittävää säästöä.</p>
                @endif
            </section>

            {{-- Spot caveat --}}
            @if ($res['has_spot_in_top'] && $res['spot_avg_cents_per_kwh'] !== null)
                <div class="rounded-lg bg-amber-50 border border-amber-200 p-4 text-sm text-amber-900 mb-6">
                    <p class="font-medium mb-1">Pörssisähkö on top 3:ssa — huomio</p>
                    <p>Tämän jakson pörssihinta oli keskimäärin {{ number_format($res['spot_avg_cents_per_kwh'], 2, ',', ' ') }} c/kWh (sis. alv). Jakson säästö on toteutunut, mutta pörssisähkön vuosisäästö on <em>arvio</em>, sillä hinta vaihtelee jatkuvasti.</p>
                </div>
            @endif

            {{-- Implied price sanity warning --}}
            @if (in_array('implied_out_of_range', $res['warnings'] ?? []))
                <div class="rounded-lg bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600 mb-6">
                    Syöttämäsi hinta ja kulutus antavat epätavallisen €/kWh-luvun ({{ number_format($res['user_implied_cents_per_kwh'], 2, ',', ' ') }} c/kWh). Tarkista, että syötit sähkösopimuksen hinnan ilman siirtoa.
                </div>
            @endif

            {{-- Ranking table --}}
            <section class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-8">
                <div class="flex items-baseline justify-between mb-4 flex-wrap gap-2">
                    <h2 class="text-xl font-bold text-slate-900">Markkinoiden halvimmat tällä kulutuksella</h2>
                    <span class="text-sm text-slate-500">Sinun sijallesi {{ $userRank }} / {{ $total }}</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-200">
                                <th class="py-2 pr-3 font-medium">#</th>
                                <th class="py-2 pr-3 font-medium">Sopimus</th>
                                <th class="py-2 pr-3 font-medium text-right">Jakson hinta</th>
                                <th class="py-2 pr-3 font-medium text-right">c/kWh</th>
                                <th class="py-2 pl-3 font-medium text-right">Säästä €/kk</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($topRows as $entry)
                                @php
                                    $row = $entry['row'];
                                    $rank = $entry['rank'];
                                    $isUser = $row['is_user'];
                                @endphp
                                <tr class="border-b border-slate-100 {{ $isUser ? 'bg-coral-50' : 'hover:bg-slate-50' }}">
                                    <td class="py-3 pr-3 font-semibold text-slate-400">{{ $rank }}</td>
                                    <td class="py-3 pr-3">
                                        @if ($isUser)
                                            <span class="font-bold text-slate-900">Sinun sopimuksesi</span>
                                            <span class="block text-xs text-slate-400">omasta laskustasi</span>
                                        @elseif ($row['detail_url'])
                                            <a href="{{ $row['detail_url'] }}" class="font-medium text-slate-900 hover:text-coral-600">
                                                {{ $row['name'] }}
                                            </a>
                                            <span class="block text-xs text-slate-400">{{ $row['company_name'] }}</span>
                                        @else
                                            <span class="font-medium text-slate-900">{{ $row['name'] }}</span>
                                        @endif
                                        @if ($row['is_spot'])
                                            <span class="ml-1 inline-block text-[10px] px-1.5 py-0.5 rounded bg-sky-100 text-sky-700 align-middle">pörssi</span>
                                        @endif
                                        @if ($row['has_promo'])
                                            <span class="ml-1 inline-block text-[10px] px-1.5 py-0.5 rounded bg-coral-100 text-coral-700 align-middle">tarjous</span>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-3 text-right font-semibold text-slate-900 whitespace-nowrap">
                                        {{ number_format($row['period_cost_eur'], 2, ',', ' ') }} €
                                    </td>
                                    <td class="py-3 pr-3 text-right text-slate-600 whitespace-nowrap">
                                        {{ number_format($row['implied_cents_per_kwh'], 2, ',', ' ') }}
                                    </td>
                                    <td class="py-3 pl-3 text-right whitespace-nowrap {{ $isUser ? 'text-slate-400' : 'text-coral-600 font-semibold' }}">
                                        @if ($row['saving_per_month_eur'] !== null && $row['saving_per_month_eur'] > 0)
                                            −{{ number_format($row['saving_per_month_eur'], 0, ',', ' ') }} €
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            @if ($showUserTail)
                                <tr>
                                    <td colspan="5" class="py-1 text-center text-slate-300 text-xs">…</td>
                                </tr>
                                <tr class="border-b border-slate-100 bg-coral-50">
                                    <td class="py-3 pr-3 font-semibold text-slate-400">{{ $userRank }}</td>
                                    <td class="py-3 pr-3">
                                        <span class="font-bold text-slate-900">Sinun sopimuksesi</span>
                                        <span class="block text-xs text-slate-400">omasta laskustasi</span>
                                    </td>
                                    <td class="py-3 pr-3 text-right font-semibold text-slate-900 whitespace-nowrap">{{ number_format($userRow['period_cost_eur'], 2, ',', ' ') }} €</td>
                                    <td class="py-3 pr-3 text-right text-slate-600 whitespace-nowrap">{{ number_format($userRow['implied_cents_per_kwh'], 2, ',', ' ') }}</td>
                                    <td class="py-3 pl-3 text-right text-slate-400">—</td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-slate-400 mt-4">
                    Hinnat sisältävät alv 25,5 %, eivät sisällä sähkön siirtoa. Jakson hinta perustuu samaan laskutusjaksoon ja kulutukseen kuin laskusi. c/kWh on jaosta laskettu keskiarvo (sis. perusmaksun). Säästä €/kk on vuosikohtainen arvio jaettuna 12:lla.
                </p>
            </section>

            {{-- Explanation box (optional inputs) --}}
            @if ($this->nullableFloat($energyPriceCents) !== null)
                @php
                    $marketMedianCents = null;
                    $cents = [];
                    foreach ($res['rows'] as $r) {
                        if (!$r['is_user'] && $r['implied_cents_per_kwh'] > 0) { $cents[] = $r['implied_cents_per_kwh']; }
                    }
                    sort($cents);
                    $count = count($cents);
                    if ($count > 0) {
                        $marketMedianCents = $count % 2 ? $cents[intdiv($count, 2)] : ($cents[$count/2 - 1] + $cents[$count/2]) / 2;
                    }
                    $userCents = (float) $this->nullableFloat($energyPriceCents);
                @endphp
                <section class="rounded-2xl border border-slate-200 bg-slate-50 p-6 mb-8">
                    <h2 class="text-lg font-bold text-slate-900 mb-2">Miksi sopimuksesi on täällä?</h2>
                    @if ($marketMedianCents !== null)
                        <p class="text-sm text-slate-700">
                            Maksat energiaa {{ number_format($userCents, 2, ',', ' ') }} c/kWh, kun markkinoiden mediaani on noin {{ number_format($marketMedianCents, 2, ',', ' ') }} c/kWh
                            @if ($this->nullableFloat($baseFeeEur) !== null)
                                ja perusmaksusi on {{ number_format((float) $this->nullableFloat($baseFeeEur), 2, ',', ' ') }} €/kk
                            @endif
                            . @if ($userCents > $marketMedianCents)
                                Hinta on energiassa kalliimpi kuin markkinoiden mediaani.
                            @else
                                Energiahinta on markkinoiden mediaania edullisempi.
                            @endif
                        </p>
                    @endif
                    <p class="text-xs text-slate-400 mt-3">Arvio perustuu syöttämääsi energiahintaan ja perusmaksuun; itse vertailu käyttää laskun kokonaishintaa.</p>
                </section>
            @endif
        @endif
        </div>

        {{-- SEO content / FAQ --}}
        <section class="mb-8">
            <h2 class="text-2xl font-bold text-slate-900 mb-4">Maksatko sähköstä liikaa?</h2>
            <div class="prose prose-slate max-w-none text-slate-600">
                <p>Sähkösopimuksen hinta vaihtelee merkittävästi eri yhtiöiden välillä, ja sama kulutus voi maksaa satoja euroja vuodessa enemmän kalliimmalla sopimuksella. Voltikan laskuri vertaa sähkölaskuasi suoraan markkinoiden voimassa oleviin sopimuksiin — tarvitset vain yhden laskun tiedot.</p>
                <p>Laskuri käyttää samaa laskutusjaksoa ja kulutusta jokaiselle markkinoiden sopimukselle, joten vertailu on reilu: näet mitä olisit maksanut kilpailijalla juuri samalla jaksolla. Vuosisäästö arvioidaan kausivaihtelun huomioiden, kun kerrot onko sähkölämmitys mukana kulutuksessa.</p>
            </div>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-bold text-slate-900 mb-4">Usein kysytyt kysymykset</h2>
            <div class="space-y-3">
                @foreach ($this->faqItems as $faq)
                    <details class="group rounded-xl border border-slate-200 bg-white p-4">
                        <summary class="cursor-pointer font-medium text-slate-900 flex items-center justify-between gap-2">
                            {{ $faq['question'] }}
                            <svg class="w-4 h-4 transition-transform group-open:rotate-180 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </summary>
                        <p class="text-sm text-slate-600 mt-3">{{ $faq['answer'] }}</p>
                    </details>
                @endforeach
            </div>
        </section>

        <div class="rounded-xl bg-slate-50 p-6 text-sm text-slate-600 mb-8">
            <p class="font-medium text-slate-700 mb-1">Huomio</p>
            <p>Laskuri antaa suuntaa-antavan arvion markkinoiden hinnoista annetun jakson ja kulutuksen perusteella. Pörssisähkön toteutunut hinta vaihtelee, ja tarjoukset voivat päättyä. Todellinen säästö riippuu kulutustottumuksista ja sopimusehdoista.</p>
        </div>
    </div>
</div>
