<div>
    <!-- Hero Section - Dark slate background -->
    <section class="bg-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="py-12 lg:py-16">
                <h1 class="max-w-2xl mb-4 text-4xl font-extrabold text-white tracking-tight leading-none md:text-5xl xl:text-6xl">
                    Sähkösopimukset <span class="text-coral-400">paikkakunnittain</span>
                </h1>
                <p class="max-w-2xl mb-6 text-slate-300 md:text-lg lg:text-xl">
                    Löydä sähkösopimukset, jotka ovat saatavilla omalla paikkakunnallasi. Valitse kunta listalta tai hae paikkakuntaa nimellä.
                </p>
            </div>
        </div>
    </section>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Municipality Search -->
        <div class="mb-8">
            <div class="relative max-w-md">
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Hae paikkakuntaa..."
                    class="w-full pl-10 pr-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                >
                <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </div>
        </div>

        <!-- Municipality List -->
        <div class="mb-4 text-sm text-slate-600">
            <span class="font-semibold">{{ $municipalities->count() }}</span> paikkakuntaa
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @forelse ($municipalities as $municipality)
                <a
                    href="/sahkosopimus/paikkakunnat/{{ $municipality->municipal_name_fi_slug }}"
                    class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm hover:border-coral-500 hover:shadow-md transition-all text-left block"
                >
                    <h3 class="text-lg font-semibold text-slate-900">
                        {{ $municipality->municipal_name_fi }}
                    </h3>
                    <p class="text-sm text-slate-500">
                        {{ $municipality->postcode_count }} postinumeroa
                    </p>
                </a>
            @empty
                <div class="col-span-full bg-white rounded-2xl shadow-sm-sm border border-slate-100 p-12 text-center">
                    <p class="text-slate-500">Ei paikkakuntia löytynyt hakusanalla "{{ $search }}"</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
