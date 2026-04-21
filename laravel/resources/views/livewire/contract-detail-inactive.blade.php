<div>
    <x-schema-markup :schemas="$schemas" />

    @php
        use App\Support\ContractLabels;

        $company = $contract->company;
        $companyUrl = $company ? route('company.detail', ['companySlug' => $company->name_slug]) : null;
        $allContractsUrl = route('sahkosopimus.index');
    @endphp

    <section class="bg-slate-950 text-white">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10 lg:py-14">
            <a href="{{ $allContractsUrl }}" class="inline-flex items-center text-slate-300 hover:text-white text-sm mb-8">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                Takaisin sopimusvertailuun
            </a>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-6 sm:p-8 lg:p-10 shadow-2xl shadow-slate-950/30">
                <div class="flex items-start gap-4 sm:gap-5">
                    <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl bg-amber-400/15 text-amber-300 border border-amber-400/25">
                        <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-7.938 4h15.876c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L2.33 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="mb-4 flex flex-wrap gap-2">
                            @if ($contract->fixed_time_range)
                                <span class="inline-flex items-center rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium text-slate-200">
                                    {{ ContractLabels::fixedTimeRange($contract->fixed_time_range) }}
                                </span>
                            @elseif ($contract->contract_type)
                                <span class="inline-flex items-center rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium text-slate-200">
                                    {{ ContractLabels::contractType($contract->contract_type) }}
                                </span>
                            @endif
                            @if ($contract->metering)
                                <span class="inline-flex items-center rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium text-slate-200">
                                    {{ ContractLabels::metering($contract->metering) }}
                                </span>
                            @endif
                            @if ($contract->pricing_model)
                                <span class="inline-flex items-center rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium text-slate-200">
                                    {{ ContractLabels::pricingModel($contract->pricing_model) }}
                                </span>
                            @endif
                        </div>

                        <h1 class="text-3xl sm:text-4xl font-bold leading-tight text-white">
                            {{ $contract->name }} ei ole enää saatavilla
                        </h1>

                        <p class="mt-3 text-base sm:text-lg text-slate-300 max-w-3xl">
                            Tämä sähkösopimus ei ole enää aktiivinen, eikä se ole enää vertailussa Voltikassa.
                            @if ($company)
                                Sopimuksen myyjä oli <span class="font-semibold text-white">{{ $company->name }}</span>.
                            @endif
                        </p>

                        <div class="mt-6 grid gap-4 sm:grid-cols-2">
                            @if ($companyUrl)
                                <a
                                    href="{{ $companyUrl }}"
                                    class="group rounded-2xl border border-white/10 bg-white px-5 py-5 text-slate-900 shadow-lg shadow-black/10 transition hover:-translate-y-0.5 hover:border-coral-300 hover:shadow-xl"
                                >
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <p class="text-sm font-semibold text-coral-600">Vaihtoehto 1</p>
                                            <h2 class="mt-1 text-lg font-bold text-slate-900">Katso saman myyjän muut sopimukset</h2>
                                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                                Selaa {{ $company->name }}-yhtiön tällä hetkellä tarjolla olevia sähkösopimuksia.
                                            </p>
                                        </div>
                                        <svg class="h-5 w-5 flex-shrink-0 text-slate-400 transition group-hover:text-coral-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    </div>
                                </a>
                            @endif

                            <a
                                href="{{ $allContractsUrl }}"
                                class="group rounded-2xl border border-coral-500/30 bg-coral-500 px-5 py-5 text-white shadow-lg shadow-coral-900/25 transition hover:-translate-y-0.5 hover:bg-coral-600 hover:shadow-xl"
                            >
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-semibold text-coral-100">Vaihtoehto {{ $companyUrl ? '2' : '1' }}</p>
                                        <h2 class="mt-1 text-lg font-bold text-white">Vertaa kaikkia sähkösopimuksia</h2>
                                        <p class="mt-2 text-sm leading-6 text-coral-50/90">
                                            Siirry sopimusvertailuun ja löydä tällä hetkellä saatavilla olevat vaihtoehdot.
                                        </p>
                                    </div>
                                    <svg class="h-5 w-5 flex-shrink-0 text-coral-100 transition group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                    </svg>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
