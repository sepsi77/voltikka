<div class="relative bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div
        wire:loading.flex
        wire:target="setComparisonMode,setConsumption,selectedContractA,selectedContractB"
        class="absolute inset-0 z-20 hidden items-center justify-center bg-white/75 backdrop-blur-sm"
        role="status"
        aria-live="polite"
    >
        <div class="inline-flex items-center gap-3 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm">
            <svg class="h-4 w-4 animate-spin text-coral-500" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            Päivitetään vertailua…
        </div>
    </div>
    {{-- Header with Tabs --}}
    <div class="border-b border-slate-100 p-4">
        <div class="flex justify-center">
            <div class="inline-flex rounded-full bg-slate-100 p-1">
                <button
                    wire:click="setComparisonMode('pricing_model')"
                    wire:loading.attr="disabled"
                    wire:target="setComparisonMode"
                    class="px-4 py-2 text-sm font-medium rounded-full transition-colors disabled:cursor-wait disabled:opacity-70 {{ $comparisonMode === 'pricing_model' ? 'bg-white text-slate-900 shadow' : 'text-slate-500 hover:text-slate-700' }}"
                >
                    Pörssisähkö vs Kiinteä
                </button>
                <button
                    wire:click="setComparisonMode('contract_term')"
                    wire:loading.attr="disabled"
                    wire:target="setComparisonMode"
                    class="px-4 py-2 text-sm font-medium rounded-full transition-colors disabled:cursor-wait disabled:opacity-70 {{ $comparisonMode === 'contract_term' ? 'bg-white text-slate-900 shadow' : 'text-slate-500 hover:text-slate-700' }}"
                >
                    Määräaik. vs Toistaiseksi
                </button>
            </div>
        </div>
    </div>

    {{-- Consumption Selector --}}
    <div class="p-4 bg-slate-50 border-b border-slate-100">
        <div class="flex flex-wrap items-center justify-center gap-2">
            <span class="text-sm font-medium text-slate-600 mr-2">Kulutus:</span>
            @foreach ($consumptionPresets as $preset)
                <button
                    wire:click="setConsumption({{ $preset }})"
                    wire:loading.attr="disabled"
                    wire:target="setConsumption"
                    class="px-3 py-1.5 text-sm font-medium rounded-full transition-colors disabled:cursor-wait disabled:opacity-70 {{ $consumption === $preset ? 'bg-coral-500 text-white' : 'bg-white text-slate-700 border border-slate-200 hover:border-coral-400' }}"
                >
                    {{ number_format($preset, 0, ',', ' ') }}
                </button>
            @endforeach
            <span class="text-sm text-slate-500 ml-1">kWh/v</span>
        </div>
    </div>

    {{-- Contract Cards --}}
    <div class="p-4 md:p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
            {{-- Contract A --}}
            <div class="rounded-xl border-2 {{ $comparisonResult['winner'] === 'A' ? 'border-green-500 bg-green-50/30' : 'border-slate-200 bg-white' }} p-4">
                @if ($comparisonResult['winner'] === 'A')
                    <div class="inline-flex items-center gap-1 bg-green-500 text-white text-xs font-bold px-2 py-1 rounded-full mb-3">
                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                        Edullisempi
                    </div>
                @endif

                <h3 class="text-lg font-bold text-slate-900 mb-3">{{ $modeConfig['labelA'] }}</h3>

                @if ($contractA)
                    <div class="space-y-3">
                        {{-- Company Logo and Name --}}
                        <div class="flex items-center gap-3">
                            @if ($contractA->company && $contractA->company->getLogoUrl())
                                <img
                                    src="{{ $contractA->company->getLogoUrl() }}"
                                    alt="{{ $contractA->company->name }}"
                                    class="h-8 w-auto object-contain"
                                >
                            @endif
                            <div>
                                <p class="font-medium text-slate-900 text-sm">{{ $contractA->name }}</p>
                                <p class="text-xs text-slate-500">{{ $contractA->company?->name }}</p>
                            </div>
                        </div>

                        {{-- Price Info --}}
                        @php $priceA = $this->getDisplayPrice($contractA); @endphp
                        <div class="bg-slate-100 rounded-lg p-3">
                            @if ($priceA['type'] === 'spot')
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-slate-600">Marginaali</span>
                                    <span class="font-bold text-slate-900">{{ number_format($priceA['margin'], 2, ',', ' ') }} c/kWh</span>
                                </div>
                            @else
                                @if ($priceA['generalRate'])
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm text-slate-600">Energia</span>
                                        <span class="font-bold text-slate-900">{{ number_format($priceA['generalRate'], 2, ',', ' ') }} c/kWh</span>
                                    </div>
                                @elseif ($priceA['dayRate'] && $priceA['nightRate'])
                                    <div class="flex justify-between items-center text-sm">
                                        <span class="text-slate-600">Päivä</span>
                                        <span class="font-medium text-slate-900">{{ number_format($priceA['dayRate'], 2, ',', ' ') }} c/kWh</span>
                                    </div>
                                    <div class="flex justify-between items-center text-sm mt-1">
                                        <span class="text-slate-600">Yö</span>
                                        <span class="font-medium text-slate-900">{{ number_format($priceA['nightRate'], 2, ',', ' ') }} c/kWh</span>
                                    </div>
                                @endif
                            @endif
                            @if (isset($priceA['monthlyFee']) && $priceA['monthlyFee'])
                                <div class="flex justify-between items-center mt-2 pt-2 border-t border-slate-200">
                                    <span class="text-sm text-slate-600">Perusmaksu</span>
                                    <span class="font-medium text-slate-900">{{ number_format($priceA['monthlyFee'], 2, ',', ' ') }} €/kk</span>
                                </div>
                            @endif
                        </div>

                        {{-- Contract Selector --}}
                        <div>
                            <select
                                wire:model.live="selectedContractA"
                                wire:loading.attr="disabled"
                                wire:target="selectedContractA"
                                class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-coral-500 focus:border-coral-500 disabled:cursor-wait disabled:opacity-70"
                            >
                                <option value="">Valittu automaattisesti (edullisin)</option>
                                @foreach ($availableContractsA as $c)
                                    <option value="{{ $c->id }}">{{ $c->company?->name }} - {{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @else
                    <div class="text-center py-6 text-slate-500">
                        <p>Ei sopimuksia saatavilla</p>
                    </div>
                @endif
            </div>

            {{-- Contract B --}}
            <div class="rounded-xl border-2 {{ $comparisonResult['winner'] === 'B' ? 'border-green-500 bg-green-50/30' : 'border-slate-200 bg-white' }} p-4">
                @if ($comparisonResult['winner'] === 'B')
                    <div class="inline-flex items-center gap-1 bg-green-500 text-white text-xs font-bold px-2 py-1 rounded-full mb-3">
                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                        Edullisempi
                    </div>
                @endif

                <h3 class="text-lg font-bold text-slate-900 mb-3">{{ $modeConfig['labelB'] }}</h3>

                @if ($contractB)
                    <div class="space-y-3">
                        {{-- Company Logo and Name --}}
                        <div class="flex items-center gap-3">
                            @if ($contractB->company && $contractB->company->getLogoUrl())
                                <img
                                    src="{{ $contractB->company->getLogoUrl() }}"
                                    alt="{{ $contractB->company->name }}"
                                    class="h-8 w-auto object-contain"
                                >
                            @endif
                            <div>
                                <p class="font-medium text-slate-900 text-sm">{{ $contractB->name }}</p>
                                <p class="text-xs text-slate-500">{{ $contractB->company?->name }}</p>
                            </div>
                        </div>

                        {{-- Price Info --}}
                        @php $priceB = $this->getDisplayPrice($contractB); @endphp
                        <div class="bg-slate-100 rounded-lg p-3">
                            @if ($priceB['type'] === 'spot')
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-slate-600">Marginaali</span>
                                    <span class="font-bold text-slate-900">{{ number_format($priceB['margin'], 2, ',', ' ') }} c/kWh</span>
                                </div>
                            @else
                                @if ($priceB['generalRate'])
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm text-slate-600">Energia</span>
                                        <span class="font-bold text-slate-900">{{ number_format($priceB['generalRate'], 2, ',', ' ') }} c/kWh</span>
                                    </div>
                                @elseif ($priceB['dayRate'] && $priceB['nightRate'])
                                    <div class="flex justify-between items-center text-sm">
                                        <span class="text-slate-600">Päivä</span>
                                        <span class="font-medium text-slate-900">{{ number_format($priceB['dayRate'], 2, ',', ' ') }} c/kWh</span>
                                    </div>
                                    <div class="flex justify-between items-center text-sm mt-1">
                                        <span class="text-slate-600">Yö</span>
                                        <span class="font-medium text-slate-900">{{ number_format($priceB['nightRate'], 2, ',', ' ') }} c/kWh</span>
                                    </div>
                                @endif
                            @endif
                            @if (isset($priceB['monthlyFee']) && $priceB['monthlyFee'])
                                <div class="flex justify-between items-center mt-2 pt-2 border-t border-slate-200">
                                    <span class="text-sm text-slate-600">Perusmaksu</span>
                                    <span class="font-medium text-slate-900">{{ number_format($priceB['monthlyFee'], 2, ',', ' ') }} €/kk</span>
                                </div>
                            @endif
                        </div>

                        {{-- Contract Selector --}}
                        <div>
                            <select
                                wire:model.live="selectedContractB"
                                wire:loading.attr="disabled"
                                wire:target="selectedContractB"
                                class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-coral-500 focus:border-coral-500 disabled:cursor-wait disabled:opacity-70"
                            >
                                <option value="">Valittu automaattisesti (edullisin)</option>
                                @foreach ($availableContractsB as $c)
                                    <option value="{{ $c->id }}">{{ $c->company?->name }} - {{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @else
                    <div class="text-center py-6 text-slate-500">
                        <p>Ei sopimuksia saatavilla</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Summary Section --}}
    @if ($comparisonResult['hasResult'])
        <div class="border-t border-slate-100 p-4 md:p-6 bg-slate-50">
            <h4 class="text-sm font-semibold text-slate-600 uppercase tracking-wide mb-4">Yhteenveto (12 kk)</h4>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                {{-- Cost A --}}
                <div class="bg-white rounded-xl p-4 text-center {{ $comparisonResult['winner'] === 'A' ? 'ring-2 ring-green-500' : '' }}">
                    <p class="text-sm text-slate-500 mb-1">{{ $modeConfig['labelA'] }}</p>
                    <p class="text-2xl font-bold text-slate-900">{{ number_format($comparisonResult['costA'], 0, ',', ' ') }} €</p>
                </div>

                {{-- Comparison Arrow --}}
                <div class="hidden md:flex items-center justify-center">
                    <div class="text-slate-400">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                    </div>
                </div>

                {{-- Cost B --}}
                <div class="bg-white rounded-xl p-4 text-center {{ $comparisonResult['winner'] === 'B' ? 'ring-2 ring-green-500' : '' }}">
                    <p class="text-sm text-slate-500 mb-1">{{ $modeConfig['labelB'] }}</p>
                    <p class="text-2xl font-bold text-slate-900">{{ number_format($comparisonResult['costB'], 0, ',', ' ') }} €</p>
                </div>
            </div>

            {{-- Winner Summary --}}
            @if ($comparisonResult['winner'] !== 'tie')
                <div class="bg-green-100 border border-green-200 rounded-xl p-4 text-center">
                    <div class="flex items-center justify-center gap-2 text-green-800">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        <span class="font-semibold">
                            {{ $comparisonResult['winnerLabel'] }} säästää
                            <span class="text-green-900">{{ $comparisonResult['savings'] }} €/vuosi</span>
                            ({{ $comparisonResult['savingsPercent'] }}%)
                        </span>
                    </div>
                </div>
            @else
                <div class="bg-slate-100 border border-slate-200 rounded-xl p-4 text-center">
                    <span class="font-semibold text-slate-700">Molemmat vaihtoehdot ovat yhtä edullisia</span>
                </div>
            @endif
        </div>
    @endif

    {{-- Monthly Chart --}}
    @if ($comparisonResult['hasResult'] && count($projectedCostsA['monthly']) > 0)
        <div class="border-t border-slate-100 p-4 md:p-6">
            <h4 class="text-sm font-semibold text-slate-600 uppercase tracking-wide mb-4">Kuukausittaiset kustannukset</h4>

            {{-- Legend --}}
            <div class="flex justify-center gap-6 mb-4">
                <div class="flex items-center gap-2">
                    <div class="w-4 h-4 bg-coral-500 rounded"></div>
                    <span class="text-sm text-slate-600">{{ $modeConfig['labelA'] }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-4 h-4 bg-blue-500 rounded"></div>
                    <span class="text-sm text-slate-600">{{ $modeConfig['labelB'] }}</span>
                </div>
            </div>

            {{-- Bar Chart using Chart.js --}}
            @php
                $chartData = [
                    'labels' => $projectedCostsA['labels'],
                    'labelA' => $modeConfig['labelA'],
                    'labelB' => $modeConfig['labelB'],
                    'dataA' => array_values($projectedCostsA['monthly']),
                    'dataB' => array_values($projectedCostsB['monthly']),
                    'consumption' => array_values($projectedCostsA['consumption']),
                ];
            @endphp
            <div class="relative" style="height: 250px;">
                <canvas id="comparisonChart" data-chart="{{ json_encode($chartData) }}"></canvas>
            </div>

            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
            <script>
                function initComparisonChart() {
                    const ctx = document.getElementById('comparisonChart');
                    if (!ctx) return;

                    const dataAttr = ctx.getAttribute('data-chart');
                    if (!dataAttr) return;

                    // Destroy existing chart if any
                    if (ctx.chartInstance) {
                        ctx.chartInstance.destroy();
                    }

                    try {
                        const chartData = JSON.parse(dataAttr);

                        ctx.chartInstance = new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: chartData.labels,
                                datasets: [
                                    {
                                        label: chartData.labelA,
                                        data: chartData.dataA,
                                        backgroundColor: '#f97316',
                                        borderRadius: 4,
                                    },
                                    {
                                        label: chartData.labelB,
                                        data: chartData.dataB,
                                        backgroundColor: '#3b82f6',
                                        borderRadius: 4,
                                    }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                interaction: {
                                    intersect: false,
                                    mode: 'index'
                                },
                                plugins: {
                                    legend: {
                                        display: false
                                    },
                                    tooltip: {
                                        backgroundColor: '#1e293b',
                                        titleFont: { size: 14, weight: 'bold' },
                                        bodyFont: { size: 13 },
                                        padding: 12,
                                        cornerRadius: 8,
                                        callbacks: {
                                            label: function(context) {
                                                return context.dataset.label + ': ' + context.parsed.y.toLocaleString('fi-FI') + ' €';
                                            },
                                            afterBody: function(context) {
                                                const idx = context[0].dataIndex;
                                                const consumption = chartData.consumption[idx];
                                                if (consumption > 0) {
                                                    return '\nKulutus: ' + Math.round(consumption).toLocaleString('fi-FI') + ' kWh';
                                                }
                                                return '';
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    x: {
                                        grid: { display: false }
                                    },
                                    y: {
                                        beginAtZero: true,
                                        ticks: {
                                            callback: function(value) {
                                                return value + ' €';
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    } catch (e) {
                        console.error('Chart init error:', e);
                    }
                }

                // Initialize on page load
                document.addEventListener('DOMContentLoaded', initComparisonChart);

                // Reinitialize charts when Livewire updates the component
                document.addEventListener('livewire:initialized', function() {
                    Livewire.hook('commit', ({ component, commit, respond, succeed, fail }) => {
                        succeed(({ snapshot, effect }) => {
                            setTimeout(initComparisonChart, 100);
                        });
                    });
                });

                // Also re-initialize on Livewire navigation (SPA mode)
                document.addEventListener('livewire:navigated', initComparisonChart);
            </script>
        </div>
    @endif

    {{-- Info Note --}}
    <div class="border-t border-slate-100 p-4 bg-slate-50">
        <div class="flex items-start gap-2 text-sm text-slate-500">
            <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p>
                Spot-hinnat perustuvat viime vuoden saman kuukauden keskihintoihin.
                @if($consumption > 4000)
                Suuremmalla kulutuksella laskuri huomioi sähkölämmityksen kausivaihtelun (talvella enemmän, kesällä vähemmän).
                @endif
                Todelliset kustannukset voivat poiketa ennusteesta.
            </p>
        </div>
    </div>
</div>
