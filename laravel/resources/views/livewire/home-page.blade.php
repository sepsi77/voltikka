<div>
    <!-- Hero Section - Dark slate with coral gradient accents -->
    <section class="bg-gradient-to-br from-slate-900 via-slate-900 to-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-12 relative overflow-hidden">
        <!-- Decorative gradient blobs -->
        <div class="absolute inset-0 pointer-events-none">
            <div class="absolute top-0 right-1/4 w-96 h-96 bg-coral-500 rounded-full blur-3xl opacity-20"></div>
            <div class="absolute bottom-0 left-0 w-72 h-72 bg-coral-400 rounded-full blur-3xl opacity-10 -translate-x-1/2"></div>
            <div class="absolute top-1/2 right-0 w-64 h-64 bg-coral-600 rounded-full blur-3xl opacity-10 translate-x-1/2"></div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="pt-16 pb-20 lg:pt-24 lg:pb-28 text-center">
                <!-- Badge -->
                <div class="inline-flex items-center gap-2 bg-coral-500/20 backdrop-blur-sm px-4 py-2 rounded-full text-sm font-medium text-coral-300 mb-10 border border-coral-500/20">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    Suomen kattavin energiapalvelu
                </div>

                <!-- Headline -->
                <h1 class="max-w-4xl mx-auto mb-8 text-4xl font-extrabold text-white tracking-tight leading-tight md:text-5xl xl:text-6xl">
                    Tee fiksummat<br>
                    <span class="text-coral-400">energiapäätökset</span>
                </h1>

                <!-- Subheadline -->
                <p class="max-w-2xl mx-auto mb-12 text-slate-300 md:text-lg lg:text-xl">
                    Vertaile sähkösopimuksia, seuraa pörssihintoja, laske aurinkopaneelien tuotto
                    ja löydä paras lämpöpumppu. Kaikki yhdessä paikassa.
                </p>

                <!-- CTA Buttons -->
                <div class="flex flex-col sm:flex-row gap-4 justify-center mb-14">
                    <a href="/sahkosopimus" class="inline-flex items-center justify-center px-8 py-4 text-base font-semibold text-white bg-gradient-to-r from-coral-500 to-coral-600 rounded-xl hover:from-coral-600 hover:to-coral-700 transition-all shadow-lg shadow-coral-500/25">
                        Vertaile sähkösopimuksia
                        <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </a>
                    <a href="#laskurit" class="inline-flex items-center justify-center px-8 py-4 text-base font-semibold text-white bg-white/10 backdrop-blur-sm border border-white/20 rounded-xl hover:bg-white/20 transition-all">
                        Tutustu laskureihin
                        <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                        </svg>
                    </a>
                </div>

                <!-- Stats Cards -->
                <div class="flex flex-wrap gap-4 justify-center">
                    <div class="bg-white/5 backdrop-blur-sm rounded-2xl px-6 py-4 text-center border border-white/10">
                        <div class="text-3xl font-extrabold text-white">{{ $contractCount }}+</div>
                        <div class="text-sm text-slate-400">sopimusta</div>
                    </div>
                    <div class="bg-white/5 backdrop-blur-sm rounded-2xl px-6 py-4 text-center border border-white/10">
                        <div class="text-3xl font-extrabold text-white">{{ $companyCount }}+</div>
                        <div class="text-sm text-slate-400">sähköyhtiötä</div>
                    </div>
                    <div class="bg-coral-500/20 backdrop-blur-sm rounded-2xl px-6 py-4 text-center border border-coral-500/30">
                        <div class="text-3xl font-extrabold text-coral-400">4</div>
                        <div class="text-sm text-coral-300">laskuria</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Feature Cards Section -->
    <section id="laskurit" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-20">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold text-slate-900 mb-4">Kaikki energiatyökalut yhdessä paikassa</h2>
            <p class="text-lg text-slate-600 max-w-2xl mx-auto">Vertaile, laske ja optimoi. Tee parempia päätöksiä datalla.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Contract Comparison Card (Featured) -->
            <a href="/sahkosopimus" class="group relative bg-gradient-to-br from-slate-900 to-slate-800 rounded-2xl p-8 text-white overflow-hidden hover:shadow-xl transition-all">
                <div class="absolute -top-12 -right-12 w-40 h-40 bg-coral-500 rounded-full blur-3xl opacity-10 group-hover:opacity-15 transition-opacity"></div>
                <div class="relative">
                    <div class="inline-flex items-center gap-2 bg-coral-500/30 px-3 py-1 rounded-full text-sm font-medium text-coral-300 mb-4">
                        {{ $contractCount }}+ sopimusta
                    </div>
                    <div class="flex items-center gap-3 mb-4">
                        <div class="p-3 bg-coral-500/20 rounded-xl">
                            <svg class="w-8 h-8 text-coral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold">Sähkösopimukset</h3>
                    </div>
                    <p class="text-slate-300 mb-6">Vertaile yli {{ $contractCount }} sopimusta ja löydä edullisin vaihtoehto. Näe hinnat, päästöt ja sopimusehdot selkeästi.</p>
                    <span class="inline-flex items-center text-coral-400 font-semibold group-hover:translate-x-1 transition-transform">
                        Vertaile sopimuksia
                        <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </span>
                </div>
            </a>

            <!-- Spot Price Card -->
            <a href="/spot-price" class="group bg-white rounded-2xl border border-slate-200 p-8 hover:shadow-xl hover:border-slate-300 transition-all">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="p-3 bg-emerald-100 rounded-xl">
                            <svg class="w-8 h-8 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold text-slate-900">Pörssisähkö</h3>
                    </div>
                    @if($currentSpotPrice)
                        <div class="text-right">
                            <div class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-semibold {{ $currentSpotPrice['price_with_tax'] < 10 ? 'bg-emerald-100 text-emerald-700' : ($currentSpotPrice['price_with_tax'] < 20 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700') }}">
                                <span class="text-xs uppercase">Nyt</span>
                                {{ number_format($currentSpotPrice['price_with_tax'], 2, ',', ' ') }} c/kWh
                            </div>
                        </div>
                    @endif
                </div>
                <p class="text-slate-600 mb-6">Seuraa tuntihintojen kehitystä ja optimoi sähkönkäyttösi. Laske saunan ja pyykinpesun kulut.</p>
                <span class="inline-flex items-center text-emerald-600 font-semibold group-hover:translate-x-1 transition-transform">
                    Näe hintakehitys
                    <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                    </svg>
                </span>
            </a>

            <!-- Solar Calculator Card -->
            <a href="/aurinkopaneelit/laskuri" class="group bg-white rounded-2xl border border-slate-200 p-8 hover:shadow-xl hover:border-slate-300 transition-all">
                <div class="flex items-center gap-3 mb-4">
                    <div class="p-3 bg-amber-100 rounded-xl">
                        <svg class="w-8 h-8 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-slate-900">Aurinkopaneelit</h3>
                </div>
                <p class="text-slate-600 mb-6">Laske paneelien tuotto ja säästöpotentiaali osoitteellesi. Näe kuukausikohtaiset tuottoarviot.</p>
                <span class="inline-flex items-center text-amber-600 font-semibold group-hover:translate-x-1 transition-transform">
                    Laske tuotto
                    <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                    </svg>
                </span>
            </a>

            <!-- Heat Pump Calculator Card -->
            <a href="/lampopumput/laskuri" class="group bg-white rounded-2xl border border-slate-200 p-8 hover:shadow-xl hover:border-slate-300 transition-all">
                <div class="flex items-center gap-3 mb-4">
                    <div class="p-3 bg-sky-100 rounded-xl">
                        <svg class="w-8 h-8 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z"/>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-slate-900">Lämpöpumput</h3>
                </div>
                <p class="text-slate-600 mb-6">Vertaile lämpöpumppuja ja laske takaisinmaksuaika. Näe säästöt verrattuna nykyiseen lämmitykseen.</p>
                <span class="inline-flex items-center text-sky-600 font-semibold group-hover:translate-x-1 transition-transform">
                    Vertaile vaihtoehtoja
                    <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                    </svg>
                </span>
            </a>
        </div>
    </section>

    <!-- Use Cases Section -->
    <section class="bg-slate-50 -mx-4 sm:-mx-6 lg:-mx-8 py-20 mb-20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-slate-900 mb-4">Mitä energiapäätöstä olet tekemässä?</h2>
                <p class="text-lg text-slate-600">Valitse tilanne ja aloita heti</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Use Case 1 -->
                <a href="/sahkosopimus" class="group bg-white rounded-xl p-6 border border-slate-200 hover:border-coral-300 hover:shadow-lg transition-all text-center">
                    <div class="w-14 h-14 mx-auto mb-4 bg-coral-100 rounded-full flex items-center justify-center group-hover:bg-coral-200 transition-colors">
                        <svg class="w-7 h-7 text-coral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                    </div>
                    <h3 class="font-semibold text-slate-900 mb-2">Vaihdan sähkösopimusta</h3>
                    <p class="text-sm text-slate-500 mb-3">Vertaile ja löydä parempi</p>
                    <span class="text-coral-600 text-sm font-medium">Vertaile sopimuksia &rarr;</span>
                </a>

                <!-- Use Case 2 -->
                <a href="/aurinkopaneelit/laskuri" class="group bg-white rounded-xl p-6 border border-slate-200 hover:border-amber-300 hover:shadow-lg transition-all text-center">
                    <div class="w-14 h-14 mx-auto mb-4 bg-amber-100 rounded-full flex items-center justify-center group-hover:bg-amber-200 transition-colors">
                        <svg class="w-7 h-7 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </div>
                    <h3 class="font-semibold text-slate-900 mb-2">Harkitsen aurinkopaneeleita</h3>
                    <p class="text-sm text-slate-500 mb-3">Laske tuotto ja säästöt</p>
                    <span class="text-amber-600 text-sm font-medium">Laske tuotto &rarr;</span>
                </a>

                <!-- Use Case 3 -->
                <a href="/lampopumput/laskuri" class="group bg-white rounded-xl p-6 border border-slate-200 hover:border-sky-300 hover:shadow-lg transition-all text-center">
                    <div class="w-14 h-14 mx-auto mb-4 bg-sky-100 rounded-full flex items-center justify-center group-hover:bg-sky-200 transition-colors">
                        <svg class="w-7 h-7 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
                        </svg>
                    </div>
                    <h3 class="font-semibold text-slate-900 mb-2">Haluan lämpöpumpun</h3>
                    <p class="text-sm text-slate-500 mb-3">Vertaile vaihtoehtoja</p>
                    <span class="text-sky-600 text-sm font-medium">Vertaile vaihtoehtoja &rarr;</span>
                </a>

                <!-- Use Case 4 -->
                <a href="/spot-price" class="group bg-white rounded-xl p-6 border border-slate-200 hover:border-emerald-300 hover:shadow-lg transition-all text-center">
                    <div class="w-14 h-14 mx-auto mb-4 bg-emerald-100 rounded-full flex items-center justify-center group-hover:bg-emerald-200 transition-colors">
                        <svg class="w-7 h-7 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                        </svg>
                    </div>
                    <h3 class="font-semibold text-slate-900 mb-2">Käytän pörssisähköä</h3>
                    <p class="text-sm text-slate-500 mb-3">Seuraa ja optimoi</p>
                    <span class="text-emerald-600 text-sm font-medium">Seuraa hintoja &rarr;</span>
                </a>
            </div>
        </div>
    </section>

    <!-- Trust/Value Props Section -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-32 lg:pb-40">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold text-slate-900 mb-4">Miksi Voltikka?</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 bg-slate-100 rounded-2xl flex items-center justify-center">
                    <svg class="w-8 h-8 text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-slate-900 mb-2">{{ $contractCount }}+ sopimusta</h3>
                <p class="text-slate-600">Kaikki suurimmat sähköyhtiöt yhdessä paikassa</p>
            </div>

            <div class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 bg-slate-100 rounded-2xl flex items-center justify-center">
                    <svg class="w-8 h-8 text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-slate-900 mb-2">Läpinäkyvät hinnat</h3>
                <p class="text-slate-600">Ei piilokustannuksia tai mainosrahaa</p>
            </div>

            <div class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 bg-slate-100 rounded-2xl flex items-center justify-center">
                    <svg class="w-8 h-8 text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-slate-900 mb-2">Ilmainen palvelu</h3>
                <p class="text-slate-600">Vertaile vapaasti ilman sitoutumista</p>
            </div>
        </div>
    </section>
</div>
