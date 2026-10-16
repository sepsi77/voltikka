{{-- One heating-alternative ledger row (Paper board C1D-0 "Alternative · …"). No card: a rank
     number, the name, the outcome figures and the cost build-up with dotted leaders, separated
     by inked rules. Expects: $alt. Optional: $isRecommended (bool), $rank (int). Reads
     $this->currentSystem, $calculationPeriod and $interestRate from the parent scope. --}}
@php
    $savingsPositive = $alt['annualSavings'] > 0;
    $isRecommended = $isRecommended ?? false;
    $rank = $rank ?? null;
    $co2Diff = $this->currentSystem['co2KgPerYear'] - $alt['co2KgPerYear'];
    // Break the total into its two visible parts so it adds up on screen:
    // energy cost + investment annuity = total annual cost.
    $energyCost = round($alt['annualCost']);
    $totalCost = round($alt['annualizedTotalCost']);
    $investmentAnnuity = $totalCost - $energyCost;
@endphp
<li @class(['energy-alt', 'is-recommended' => $isRecommended])>
    <div class="energy-alt-main">
        <div class="energy-alt-title">
            <h4 class="energy-alt-name">
                @if ($rank)
                    <span @class(['energy-alt-rank', 'is-first' => $rank === 1])>{{ $rank }}</span>
                @endif
                <span>{{ $alt['label'] }}</span>
            </h4>
            @if ($isRecommended)
                <p class="energy-alt-tag"><x-ink.sparkle size="12" color="var(--color-aurora)" /> Suositeltu</p>
            @endif
            @if (!empty($alt['notes']))
                <p class="energy-alt-notes">{{ $alt['notes'] }}</p>
            @endif
        </div>

        {{-- Outcome metrics --}}
        <dl class="energy-alt-figures">
            <div>
                <dt class="energy-small-label">{{ $savingsPositive ? 'Vuosisäästö' : 'Lisäkustannus/v' }}</dt>
                <dd @class(['energy-alt-figure', 'is-saving' => $savingsPositive, 'is-cost' => ! $savingsPositive])>
                    {{ $savingsPositive ? '+' : '' }}{{ number_format($alt['annualSavings'], 0, ',', ' ') }} €
                </dd>
            </div>
            <div>
                <dt class="energy-small-label">Takaisinmaksuaika</dt>
                <dd @class(['energy-alt-figure', 'is-muted' => ! $alt['paybackYears']])>
                    @if ($alt['paybackYears'])
                        {{ number_format($alt['paybackYears'], 1, ',', ' ') }} v
                    @else
                        –
                    @endif
                </dd>
            </div>
            <div>
                <dt class="energy-small-label">CO₂-päästöt</dt>
                <dd class="energy-alt-co2">{{ number_format($alt['co2KgPerYear'], 0, ',', ' ') }} kg</dd>
                @if ($co2Diff > 0)
                    <dd class="energy-alt-co2-delta is-down">−{{ number_format($co2Diff, 0, ',', ' ') }} kg ({{ number_format(($co2Diff / $this->currentSystem['co2KgPerYear']) * 100, 0) }}%)</dd>
                @elseif ($co2Diff < 0)
                    <dd class="energy-alt-co2-delta is-up">+{{ number_format(abs($co2Diff), 0, ',', ' ') }} kg</dd>
                @endif
            </div>
        </dl>

        {{-- Cost build-up: energy cost + investment annuity = total annual cost --}}
        <div class="energy-alt-costs">
            <p class="energy-alt-cost">
                <span>Energiakustannus/v</span>
                <span class="vk-leader" aria-hidden="true"></span>
                <strong>{{ number_format($energyCost, 0, ',', ' ') }} €</strong>
            </p>
            <p class="energy-alt-cost">
                <span>Investoinnin osuus/v</span>
                <span class="vk-leader" aria-hidden="true"></span>
                <strong>+ {{ number_format($investmentAnnuity, 0, ',', ' ') }} €</strong>
            </p>
            <p class="energy-alt-cost-note">{{ number_format($alt['investment'], 0, ',', ' ') }} € jaettuna {{ $calculationPeriod }} vuodelle ({{ number_format($interestRate, 1, ',', ' ') }} % korko)</p>
            <p class="energy-alt-total">
                <span>Kokonaiskustannus/v*</span>
                <strong>{{ number_format($totalCost, 0, ',', ' ') }} €</strong>
            </p>
        </div>
    </div>

    @if ($alt['annualSavings'] > 0)
        @include('livewire.partials.heat-pump-payback-chart', ['alt' => $alt])
    @endif
</li>
