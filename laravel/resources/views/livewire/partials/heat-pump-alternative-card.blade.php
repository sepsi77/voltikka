{{-- One heating-alternative result card.
     Expects: $alt. Optional: $isRecommended (bool). Reads $this->currentSystem,
     $calculationPeriod from the parent scope. --}}
@php
    $savingsPositive = $alt['annualSavings'] > 0;
    $isRecommended = $isRecommended ?? false;
    $co2Diff = $this->currentSystem['co2KgPerYear'] - $alt['co2KgPerYear'];
    // Break the total into its two visible parts so it adds up on screen:
    // energy cost + investment annuity = total annual cost.
    $energyCost = round($alt['annualCost']);
    $totalCost = round($alt['annualizedTotalCost']);
    $investmentAnnuity = $totalCost - $energyCost;
@endphp
<div class="bg-white rounded-2xl border {{ $isRecommended ? 'border-coral-300 ring-1 ring-coral-200' : 'border-slate-200' }} p-6 hover:shadow-lg transition-shadow">
    <div class="flex items-start justify-between gap-3 mb-4">
        <h4 class="text-lg font-bold text-slate-900">{{ $alt['label'] }}</h4>
        @if ($isRecommended)
            <span class="shrink-0 text-xs font-semibold uppercase tracking-wide text-coral-700 bg-coral-50 border border-coral-200 rounded-full px-3 py-1">Suositeltu</span>
        @endif
    </div>

    {{-- Outcome metrics --}}
    <div class="space-y-3">
        <div class="flex justify-between items-center">
            <span class="text-slate-600">{{ $savingsPositive ? 'Vuosisäästö' : 'Lisäkustannus/v' }}</span>
            <span class="text-lg font-bold text-slate-900 tabular-nums whitespace-nowrap">
                {{ $savingsPositive ? '+' : '' }}{{ number_format($alt['annualSavings'], 0, ',', ' ') }} €
            </span>
        </div>
        <div class="flex justify-between items-center">
            <span class="text-slate-600">Takaisinmaksuaika</span>
            <span class="text-lg font-semibold text-slate-900 tabular-nums whitespace-nowrap">
                @if ($alt['paybackYears'])
                    {{ number_format($alt['paybackYears'], 1, ',', ' ') }} v
                @else
                    –
                @endif
            </span>
        </div>
        <div class="flex justify-between items-center">
            <span class="text-slate-600">CO₂-päästöt</span>
            <div class="text-right">
                <span class="text-lg font-semibold text-slate-900 tabular-nums whitespace-nowrap">{{ number_format($alt['co2KgPerYear'], 0, ',', ' ') }} kg</span>
                @if ($co2Diff > 0)
                    <span class="block text-sm text-green-600 font-medium tabular-nums">−{{ number_format($co2Diff, 0, ',', ' ') }} kg ({{ number_format(($co2Diff / $this->currentSystem['co2KgPerYear']) * 100, 0) }}%)</span>
                @elseif ($co2Diff < 0)
                    <span class="block text-sm text-red-600 font-medium tabular-nums">+{{ number_format(abs($co2Diff), 0, ',', ' ') }} kg</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Cost build-up: energy cost + investment annuity = total annual cost --}}
    <div class="border-t border-slate-100 pt-3 mt-4 space-y-2">
        <div class="flex justify-between items-center">
            <span class="text-slate-600">Energiakustannus/v</span>
            <span class="text-slate-900 font-semibold tabular-nums whitespace-nowrap">{{ number_format($energyCost, 0, ',', ' ') }} €</span>
        </div>
        <div class="flex justify-between items-start gap-2">
            <span class="text-slate-600">
                Investoinnin osuus/v
                <span class="block text-xs text-slate-400">{{ number_format($alt['investment'], 0, ',', ' ') }} € jaettuna {{ $calculationPeriod }} vuodelle ({{ number_format($interestRate, 1, ',', ' ') }} % korko)</span>
            </span>
            <span class="text-slate-900 font-semibold tabular-nums whitespace-nowrap">+ {{ number_format($investmentAnnuity, 0, ',', ' ') }} €</span>
        </div>
        <div class="flex justify-between items-baseline gap-2 border-t border-slate-200 pt-2">
            <span class="text-slate-900 font-semibold">Kokonaiskustannus/v*</span>
            <span class="text-xl font-bold text-slate-900 tabular-nums whitespace-nowrap">{{ number_format($totalCost, 0, ',', ' ') }} €</span>
        </div>
    </div>

    @if (!empty($alt['notes']))
        <p class="mt-4 text-sm text-slate-500 bg-slate-50 rounded-lg p-3">{{ $alt['notes'] }}</p>
    @endif

    @if ($alt['annualSavings'] > 0)
        @include('livewire.partials.heat-pump-payback-chart', ['alt' => $alt])
    @endif
</div>
