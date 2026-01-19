<div>
    <!-- Page Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900">Sähkösopimukset</h1>
        <p class="mt-2 text-gray-600">Vertaile sähkösopimuksia ja löydä edullisin vaihtoehto.</p>
    </div>

    <!-- Consumption Selector -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Arvioitu kulutus</h2>
        <div class="flex flex-wrap gap-3">
            @foreach ($presets as $label => $value)
                <button
                    wire:click="setConsumption({{ $value }})"
                    class="px-4 py-2 rounded-lg transition-colors {{ $consumption === $value ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}"
                >
                    {{ $label }} ({{ $value }} kWh)
                </button>
            @endforeach
        </div>
        <p class="mt-4 text-sm text-gray-500">
            Valittu kulutus: <span class="font-semibold">{{ number_format($consumption, 0, ',', ' ') }} kWh/vuosi</span>
        </p>
    </div>

    <!-- Contracts List -->
    <div class="space-y-4">
        @forelse ($contracts as $contract)
            @php
                $prices = $this->getLatestPrices($contract);
                $generalPrice = $prices['General']['price'] ?? null;
                $monthlyFee = $prices['Monthly']['price'] ?? 0;
                $totalCost = $contract->calculated_cost['total_cost'] ?? 0;
                $source = $contract->electricitySource;
            @endphp
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <div class="flex flex-col md:flex-row md:items-center gap-6">
                    <!-- Company Logo & Name -->
                    <div class="flex items-center gap-4 md:w-48 flex-shrink-0">
                        @if ($contract->company?->logo_url)
                            <img
                                src="{{ $contract->company->logo_url }}"
                                alt="{{ $contract->company->name }}"
                                class="h-12 w-auto object-contain"
                            >
                        @else
                            <div class="h-12 w-12 bg-gray-200 rounded flex items-center justify-center">
                                <span class="text-gray-500 text-sm font-bold">{{ substr($contract->company?->name ?? 'N/A', 0, 2) }}</span>
                            </div>
                        @endif
                        <div>
                            <p class="text-sm text-gray-500">{{ $contract->company?->name }}</p>
                        </div>
                    </div>

                    <!-- Contract Info -->
                    <div class="flex-grow">
                        <div class="flex items-center gap-2 mb-2">
                            <h3 class="text-lg font-semibold text-gray-900">{{ $contract->name }}</h3>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $contract->contract_type === 'Spot' ? 'bg-yellow-100 text-yellow-800' : ($contract->contract_type === 'Fixed' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800') }}">
                                {{ $contract->contract_type }}
                            </span>
                        </div>

                        <!-- Energy Source Badges -->
                        @if ($source)
                            <div class="flex flex-wrap gap-2 mb-3">
                                @if ($source->renewable_total && $source->renewable_total > 0)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                        Uusiutuva {{ number_format($source->renewable_total, 0, ',', ' ') }}%
                                    </span>
                                @endif
                                @if ($source->nuclear_total && $source->nuclear_total > 0)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                        Ydinvoima {{ number_format($source->nuclear_total, 0, ',', ' ') }}%
                                    </span>
                                @endif
                                @if ($source->fossil_total && $source->fossil_total > 0)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                                        Fossiilinen {{ number_format($source->fossil_total, 0, ',', ' ') }}%
                                    </span>
                                @endif
                            </div>
                        @endif

                        <!-- Price Breakdown -->
                        <div class="flex flex-wrap gap-4 text-sm text-gray-600">
                            @if ($generalPrice !== null)
                                <span>Energia: <span class="font-semibold text-gray-900">{{ number_format($generalPrice, 2, ',', ' ') }} c/kWh</span></span>
                            @endif
                            @if ($monthlyFee > 0)
                                <span>Perusmaksu: <span class="font-semibold text-gray-900">{{ number_format($monthlyFee, 2, ',', ' ') }} EUR/kk</span></span>
                            @endif
                        </div>
                    </div>

                    <!-- Total Cost -->
                    <div class="md:w-40 text-right flex-shrink-0">
                        <p class="text-sm text-gray-500">Vuosikustannus</p>
                        <p class="text-2xl font-bold text-gray-900">{{ number_format($totalCost, 0, ',', ' ') }} EUR</p>
                        <p class="text-xs text-gray-500">{{ number_format($totalCost / 12, 0, ',', ' ') }} EUR/kk</p>
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-12 text-center">
                <p class="text-gray-500">Ei sopimuksia saatavilla.</p>
            </div>
        @endforelse
    </div>
</div>
