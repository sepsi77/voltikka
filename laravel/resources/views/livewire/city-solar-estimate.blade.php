<div>
    @if($solarEstimate)
    <section class="bg-gradient-to-r from-amber-50 to-yellow-50 rounded-2xl shadow-sm border border-amber-200 p-6 mb-8">
        <div class="flex flex-col md:flex-row items-start md:items-center gap-6">
            <div class="flex-shrink-0">
                <div class="p-4 bg-amber-100 rounded-xl">
                    <svg class="w-10 h-10 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </div>
            </div>
            <div class="flex-1">
                <h2 class="text-lg font-bold text-slate-900 mb-1">
                    Aurinkosähkön tuottopotentiaali {{ $cityLocative ?: $citySlug }}
                </h2>
                <p class="text-slate-600 mb-3">
                    {{ number_format($solarEstimate['system_kwp'], 1, ',', ' ') }} kWp aurinkopaneelijärjestelmä tuottaisi arviolta
                    <span class="font-bold text-amber-700">{{ number_format($solarEstimate['annual_kwh'], 0, ',', ' ') }} kWh</span>
                    vuodessa.
                </p>
                <a href="/aurinkopaneelit/laskuri" class="inline-flex items-center gap-2 text-amber-700 hover:text-amber-800 font-medium">
                    Laske tarkka tuotto omalle kotillesi
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>
        </div>
    </section>
    @endif
</div>
