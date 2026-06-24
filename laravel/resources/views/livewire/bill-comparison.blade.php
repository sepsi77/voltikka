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
                    Syötä sähkölaskusi tiedot, niin laskuri näyttää heti, mitä olisit maksanut muilla sopimuksilla ja kuinka paljon säästäisit vaihtamalla.
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
                <p class="text-sm font-semibold uppercase tracking-wide text-coral-700 mb-1">Laskun tiedot</p>
                <h2 class="text-xl font-bold text-slate-900">Syötä sähkölaskusi tiedot</h2>
                <p class="text-sm text-slate-500 mt-1">Kaikki löytyy sähkölaskulta, et tarvitse vuosikulutusta.</p>
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
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-w-md">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Alkaa</label>
                        <input
                            type="date"
                            wire:model.blur="startDate"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                        >
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Päättyy</label>
                        <input
                            type="date"
                            wire:model.blur="endDate"
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
                        wire:model.blur="kwh"
                        min="1"
                        step="1"
                        placeholder="esim. 400"
                        class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                    >
                    <p class="text-xs text-slate-500 mt-1">Laskun kulutus jakson ajalta.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Maksettu yhteensä (€)</label>
                    <input
                        type="number"
                        wire:model.blur="totalEur"
                        min="0"
                        step="0.01"
                        placeholder="esim. 35,00"
                        class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                    >
                    <p class="text-xs text-slate-500 mt-1">Vain sähkösopimus, ei sähkön siirtoa.</p>
                </div>
            </div>

            {{-- Toggles. Real checkbox inputs styled as switches: keyboard and
                 screen-reader accessible (role="switch"), coral when on. --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <label class="flex items-start justify-between gap-3 p-3 rounded-lg border border-slate-200 cursor-pointer hover:bg-slate-50">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-slate-700">Hinta sisältää ALV:n</span>
                        <span class="block text-xs text-slate-500">Useimmat syöttävät verollisen hinnan. Kytke pois, jos laskussa on veroton hinta.</span>
                    </span>
                    <span class="relative inline-flex shrink-0 mt-0.5">
                        <input type="checkbox" role="switch" wire:model.live="includesVat" class="peer sr-only">
                        <span aria-hidden="true" class="block h-6 w-11 rounded-full bg-slate-300 transition-colors peer-checked:bg-coral-500 peer-focus-visible:ring-2 peer-focus-visible:ring-coral-500 peer-focus-visible:ring-offset-2"></span>
                        <span aria-hidden="true" class="pointer-events-none absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                    </span>
                </label>
                <label class="flex items-start justify-between gap-3 p-3 rounded-lg border border-slate-200 cursor-pointer hover:bg-slate-50">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-slate-700">Lämmitän kotini sähköllä</span>
                        <span class="block text-xs text-slate-500">Mikä tahansa sähköllä toimiva lämmitys: suora sähkölämmitys, ilma- tai maalämpöpumppu. Tarkentaa vuosisäästön arviota, ei vaikuta vertailuun.</span>
                    </span>
                    <span class="relative inline-flex shrink-0 mt-0.5">
                        <input type="checkbox" role="switch" wire:model.live="includesHeating" class="peer sr-only">
                        <span aria-hidden="true" class="block h-6 w-11 rounded-full bg-slate-300 transition-colors peer-checked:bg-coral-500 peer-focus-visible:ring-2 peer-focus-visible:ring-coral-500 peer-focus-visible:ring-offset-2"></span>
                        <span aria-hidden="true" class="pointer-events-none absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                    </span>
                </label>
            </div>

            {{-- Helper / siirto clarification --}}
            <div class="rounded-lg bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600 mb-6">
                <p class="font-medium text-slate-700 mb-1">Mikä hinta syötetään?</p>
                <p>Syötä <strong>vain sähkösopimuksen hinta</strong>, ei sähkön siirtoa. Siirto laskutetaan verkkoyhtiöltä erikseen, eikä sitä voi säästää vaihtamalla sopimusta. Yhdistelmälaskulla etsi "sähkö"- tai "energia"-rivi. Hinnan voi syöttää verollisena; valitse alta, sisältyykö ALV.</p>
            </div>

            {{-- Optional input that sharpens the annual estimate (the page's
                 hero number). A native <details> keeps its open state only in the
                 DOM, which a Livewire morph (fired by the live input inside it)
                 resets to closed on every keystroke. Hold the open state in Alpine
                 so it survives morphs: x-on:toggle syncs the state on user toggle
                 and x-bind:open reasserts it after each re-render. --}}
            <details x-data="{ open: false }" x-bind:open="open" x-on:toggle="open = $event.target.open" class="group">
                <summary class="cursor-pointer text-sm font-medium text-slate-700 hover:text-slate-900 flex items-center gap-2">
                    <svg class="w-4 h-4 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    Tiedän vuosikulutukseni (tarkentaa vuosiarviota)
                </summary>
                <div class="mt-4 pl-6 max-w-xs">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Vuosikulutus (kWh)</label>
                    <input
                        type="number"
                        wire:model.blur="annualKwh"
                        min="0"
                        step="1"
                        placeholder="esim. 18000"
                        class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                    >
                    <p class="text-xs text-slate-500 mt-1">Jos tiedät vuosikulutuksesi, vuosisäästön arvio lasketaan suoraan sillä kausivaihtelun arvion sijaan.</p>
                </div>
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
                // Share of the market the user already beats on price. Phrased
                // positively ("edullisempi kuin X %") so the verdict reads as
                // empowerment, not blame: rank 38/326 means cheaper than ~89 %,
                // which the old "kalliimpi kuin 11 %" wording contradicted.
                $betterThanPct = $total > 1 ? round(($total - $userRank) / max(1, $total - 1) * 100) : 0;
            @endphp

            {{-- Verdict hero --}}
            <section class="rounded-2xl {{ $res['is_overpaying'] ? 'bg-slate-950' : 'bg-emerald-50 border border-emerald-200' }} p-8 mb-8 text-center">
                @if ($res['is_overpaying'])
                    <p class="text-sm font-semibold uppercase tracking-wide text-coral-400 mb-2">Voisit säästää vaihtamalla</p>
                    <div class="my-6">
                        <p class="text-4xl md:text-5xl font-extrabold text-coral-400 tabular-nums">≈ {{ number_format(abs($res['annual_saving_eur']), 0, ',', ' ') }} €/vuosi</p>
                        <p class="text-slate-400 text-xs font-semibold uppercase tracking-wide mt-2 tabular-nums">Arvio · noin {{ number_format(abs($res['monthly_saving_eur']), 0, ',', ' ') }} €/kk</p>
                    </div>
                    <p class="text-slate-300">Sopimuksesi on jo edullisempi kuin <strong class="text-white tabular-nums">{{ $betterThanPct }} %</strong> markkinoista, mutta halvimpaan vaihtamalla säästäisit silti tämän verran.</p>
                    <p class="text-sm text-slate-400 mt-3 tabular-nums">Tällä laskutusjaksolla säästö olisi {{ number_format(abs($res['period_saving_eur']), 0, ',', ' ') }} € (toteutunut). Vuosiarvio huomioi kausivaihtelun{{ $includesHeating ? ' ja sähkölämmityksen' : '' }}.</p>
                @else
                    <p class="text-sm font-semibold uppercase tracking-wide text-emerald-700 mb-2">Sopimuksesi on kilpailukykyinen</p>
                    <p class="text-emerald-900">Sopimuksesi on edullisempi kuin <strong class="tabular-nums">{{ $betterThanPct }} %</strong> markkinoista. Vaihtaminen ei tällä kulutuksella tuota merkittävää säästöä.</p>
                @endif
            </section>

            {{-- Spot caveat --}}
            @if ($res['has_spot_in_top'] && $res['spot_avg_cents_per_kwh'] !== null)
                <div class="rounded-lg bg-amber-50 border border-amber-200 p-4 text-sm text-amber-900 mb-6">
                    <p class="font-medium mb-1">Pörssisähkö on top 3:ssa, huomio</p>
                    <p class="tabular-nums">Tämän jakson pörssihinta oli keskimäärin {{ number_format($res['spot_avg_cents_per_kwh'], 2, ',', ' ') }} c/kWh (sis. alv). Jakson säästö on toteutunut, mutta pörssisähkön vuosisäästö on <em>arvio</em>, sillä hinta vaihtelee jatkuvasti.</p>
                </div>
            @endif

            {{-- Implied price sanity warning --}}
            @if (in_array('implied_out_of_range', $res['warnings'] ?? []))
                <div class="rounded-lg bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600 mb-6 tabular-nums">
                    Syöttämäsi hinta ja kulutus antavat epätavallisen €/kWh-luvun ({{ number_format($res['user_implied_cents_per_kwh'], 2, ',', ' ') }} c/kWh). Tarkista, että syötit sähkösopimuksen hinnan ilman siirtoa.
                </div>
            @endif

            {{-- Ranking table --}}
            <section class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-8">
                <div class="flex items-baseline justify-between mb-4 flex-wrap gap-2">
                    <h2 class="text-xl font-bold text-slate-900">Markkinoiden halvimmat tällä kulutuksella</h2>
                    <span class="text-sm text-slate-500 tabular-nums">Sijasi {{ $userRank }} / {{ $total }}</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm tabular-nums">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-500 border-b border-slate-200">
                                <th class="py-2 pr-3 font-medium">#</th>
                                <th class="py-2 pr-3 font-medium">Sopimus</th>
                                <th class="py-2 pr-3 font-medium text-right">Jakson hinta</th>
                                <th class="py-2 pr-3 font-medium text-right hidden sm:table-cell">c/kWh</th>
                                <th class="py-2 pl-3 font-medium text-right">Säästö €/kk (arvio)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($topRows as $entry)
                                @php
                                    $row = $entry['row'];
                                    $rank = $entry['rank'];
                                    $isUser = $row['is_user'];
                                @endphp
                                <tr wire:key="rank-row-{{ $isUser ? 'user' : $row['contract_id'] }}" class="border-b border-slate-100 {{ $isUser ? 'bg-coral-50' : 'hover:bg-slate-50' }}">
                                    <td class="py-3 pr-3 font-semibold text-slate-500">{{ $rank }}</td>
                                    <td class="py-3 pr-3">
                                        @if ($isUser)
                                            <span class="font-bold text-slate-900">Sinun sopimuksesi</span>
                                            <span class="block text-xs text-slate-500">omasta laskustasi</span>
                                        @elseif ($row['detail_url'])
                                            <a href="{{ $row['detail_url'] }}" class="font-medium text-slate-900 hover:text-coral-700">
                                                {{ $row['name'] }}
                                            </a>
                                            <span class="block text-xs text-slate-500">{{ $row['company_name'] }}</span>
                                        @else
                                            <span class="font-medium text-slate-900">{{ $row['name'] }}</span>
                                        @endif
                                        @if ($row['is_spot'])
                                            <span class="ml-1 inline-block text-xs px-1.5 py-0.5 rounded bg-sky-100 text-sky-800 align-middle">pörssi</span>
                                        @endif
                                        @if ($row['has_promo'])
                                            <span class="ml-1 inline-block text-xs px-1.5 py-0.5 rounded bg-coral-100 text-coral-800 align-middle">tarjous</span>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-3 text-right font-semibold text-slate-900 whitespace-nowrap">
                                        {{ number_format($row['period_cost_eur'], 2, ',', ' ') }} €
                                    </td>
                                    <td class="py-3 pr-3 text-right text-slate-600 whitespace-nowrap hidden sm:table-cell">
                                        {{ number_format($row['implied_cents_per_kwh'], 2, ',', ' ') }}
                                    </td>
                                    <td class="py-3 pl-3 text-right whitespace-nowrap {{ $isUser ? 'text-slate-500' : 'text-coral-700 font-semibold' }}">
                                        @if ($row['saving_per_month_eur'] !== null && $row['saving_per_month_eur'] > 0)
                                            −{{ number_format($row['saving_per_month_eur'], 1, ',', ' ') }} €
                                        @else
                                            –
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            @if ($showUserTail)
                                <tr>
                                    <td colspan="5" class="py-1 text-center text-slate-400 text-xs">…</td>
                                </tr>
                                <tr class="border-b border-slate-100 bg-coral-50">
                                    <td class="py-3 pr-3 font-semibold text-slate-500">{{ $userRank }}</td>
                                    <td class="py-3 pr-3">
                                        <span class="font-bold text-slate-900">Sinun sopimuksesi</span>
                                        <span class="block text-xs text-slate-500">omasta laskustasi</span>
                                    </td>
                                    <td class="py-3 pr-3 text-right font-semibold text-slate-900 whitespace-nowrap">{{ number_format($userRow['period_cost_eur'], 2, ',', ' ') }} €</td>
                                    <td class="py-3 pr-3 text-right text-slate-600 whitespace-nowrap hidden sm:table-cell">{{ number_format($userRow['implied_cents_per_kwh'], 2, ',', ' ') }}</td>
                                    <td class="py-3 pl-3 text-right text-slate-500">–</td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-slate-500 mt-4 tabular-nums">
                    Hinnat sisältävät alv 25,5 %, eivät sisällä sähkön siirtoa. Jakson hinta perustuu samaan laskutusjaksoon ja kulutukseen kuin laskusi. c/kWh on jaosta laskettu keskiarvo (sis. perusmaksun). Säästö €/kk on vuosikohtainen arvio jaettuna 12:lla.
                </p>
            </section>

        @endif
        </div>

        {{-- SEO content / FAQ --}}
        <section class="mb-8">
            <h2 class="text-2xl font-bold text-slate-900 mb-4">Maksatko sähköstä liikaa?</h2>
            <div class="prose prose-slate max-w-none text-slate-600">
                <p>Sähkösopimuksen hinta vaihtelee merkittävästi eri yhtiöiden välillä, ja sama kulutus voi maksaa satoja euroja vuodessa enemmän kalliimmalla sopimuksella. Voltikan laskuri vertaa sähkölaskuasi suoraan markkinoiden voimassa oleviin sopimuksiin: tarvitset vain yhden laskun tiedot.</p>
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
