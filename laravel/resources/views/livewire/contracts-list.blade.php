<div>
    {{-- Schema.org structured data --}}
    <x-schema-markup :schemas="$schemas" />

    <!-- Hero Section - Dark slate with coral gradient accents -->
    <section class="bg-gradient-to-br from-slate-900 via-slate-900 to-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8 relative overflow-hidden">
        <!-- Decorative gradient blobs -->
        <div class="absolute inset-0 pointer-events-none">
            <div class="absolute top-0 right-1/4 w-96 h-96 bg-coral-500 rounded-full blur-3xl opacity-20"></div>
            <div class="absolute bottom-0 left-0 w-72 h-72 bg-coral-400 rounded-full blur-3xl opacity-10 -translate-x-1/2"></div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="grid max-w-screen-xl py-12 mx-auto lg:gap-8 xl:gap-0 lg:py-20 lg:grid-cols-12">
                <div class="mx-auto place-self-center col-12 lg:col-span-7">
                    <div class="inline-flex items-center gap-2 bg-coral-500/20 backdrop-blur-sm px-4 py-2 rounded-full text-sm font-medium text-coral-300 mb-6 border border-coral-500/20">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        Vertaile älykkäästi
                    </div>
                    <h1 class="max-w-2xl mb-4 text-4xl font-extrabold text-white tracking-tight leading-tight md:text-5xl xl:text-6xl">
                        @if ($this->hasActiveFilters())
                            {{ $pageTitle }}
                        @else
                            Löydä paras<br>
                            <span class="text-coral-400">sähkösopimus</span>
                        @endif
                    </h1>
                    <p class="max-w-2xl mb-5 text-slate-300 md:text-lg lg:text-xl">
                        Vertaile hintoja ja päästöjä läpinäkyvästi. Näe mitä todella maksat — ilman piilokustannuksia.
                    </p>
                    <x-contract-market-insight-pills :insight="$marketInsight ?? null" class="mb-1" />
                </div>
                <div class="hidden lg:flex lg:col-span-5 items-center justify-end">
                    <!-- Stats cards -->
                    <div class="flex gap-3">
                        <div class="bg-white/5 backdrop-blur-sm rounded-2xl px-6 py-4 text-center border border-white/10">
                            <div class="text-3xl font-extrabold text-white">{{ $contracts->total() }}</div>
                            <div class="text-sm text-slate-400">sopimusta</div>
                        </div>
                        <div class="bg-white/5 backdrop-blur-sm rounded-2xl px-6 py-4 text-center border border-white/10">
                            <div class="text-3xl font-extrabold text-white">{{ $this->getUniqueCompanyCount() }}</div>
                            <div class="text-sm text-slate-400">yhtiötä</div>
                        </div>
                        <div class="bg-coral-500/20 backdrop-blur-sm rounded-2xl px-6 py-4 text-center border border-coral-500/30">
                            <div class="text-3xl font-extrabold text-coral-400">{{ $this->getZeroEmissionCount() }}</div>
                            <div class="text-sm text-coral-300">päästötöntä</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    @include('partials.contract-consumption-selector', [
        'isBusinessPage' => false,
        'showCalculatorTab' => true,
    ])

    {{-- Pricing-type filter: its own disclosure, outside the filters accordion. --}}
    @include('partials.pricing-bucket-pills')

    @include('partials.contract-postcode-selector')

    {{-- Filter Section (shared partial) --}}
    @include('partials.contract-filters')

    {{-- Results heading (redesign, Paper board 2J3-0 "Results heading"): the count in
         Bricolage, the note line and the category legend with sparkle marks. --}}
    <div class="vk-results-head mb-5 mt-4">
        <div class="min-w-0">
            <h2 class="vk-results-count">{{ $contracts->total() }} sopimusta.</h2>
            <p class="vk-results-note">
                12 kk arvio sisältää tarjoukset ja ALV 25,5 %. Siirtomaksu ei sisälly.
                <a href="/tietoa#menetelma" class="vk-text-link whitespace-nowrap">Näin laskemme &rarr;</a>
            </p>
        </div>
        {{-- The legend explains the pricing categories of the entries below. --}}
        <x-card.legend class="shrink-0 lg:pb-1.5" />
    </div>

    {{-- Contracts list (ledger list contract: entries end with a vk-ledger-sep hairline). --}}
    <div class="vk-ledger">
        @forelse ($contracts as $index => $contract)
            @php
                // Calculate the overall rank based on current page
                $overallRank = (($contracts->currentPage() - 1) * $contracts->perPage()) + $index + 1;
            @endphp

            @if ($overallRank === 1)
                <x-featured-contract-card
                    :contract="$contract"
                    :consumption="$consumption"
                    :prices="$this->getLatestPrices($contract)"
                />
            @else
                <x-contract-card
                    :contract="$contract"
                    :rank="$overallRank"
                    :featured="$overallRank <= 3"
                    :consumption="$consumption"
                    :prices="$this->getLatestPrices($contract)"
                    :percentiles="$this->getPercentiles()"
                    :showRank="true"
                    :showEmissions="true"
                    :showEnergyBadges="true"
                    :showSpotBadge="true"
                />
            @endif
        @empty
            <p class="py-10 text-center font-display text-[22px] font-extrabold leading-[26px] text-ink-muted">Ei sopimuksia saatavilla.</p>
        @endforelse
    </div>

    <!-- Pagination Links -->
    @if ($contracts->lastPage() > 1)
        <div class="mt-6">
            {{ $contracts->links('livewire.partials.pagination') }}
        </div>
    @endif
    </div>
</div>
