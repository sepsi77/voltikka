@props([
    'contract',
    'consumption' => null,
])
{{--
    Compact one-line contract row for the business section of a company page (design spec §5
    page patterns: "Business contracts on a company page are compact one-line rows"; Paper
    board AX2-0, "Yrityksille").

    Same view model as the ledger entry (App\Services\ContractCard\ContractCardPresenter), so
    the facts cannot drift: category glyph, name, meta line with the category and the Arvio
    popover, the price parts in one line, €/kk and €/v, the measured offer saving, the warning
    and fact tags, and "Katso →". The seller is the page itself, so the meta line leaves out
    the company name and the row has no logo. No price logic here.
--}}
@php
    $card = app(\App\Services\ContractCard\ContractCardPresenter::class)->present(
        contract: $contract,
        prices: [],
        consumption: $consumption,
        detailConsumption: null,
        billMode: false,
    );
    $catClass = match ($card->bucket->value) {
        'porssisahko' => 'text-cat-spot',
        'paivittyva' => 'text-cat-periodic',
        'kulutusvaikutus' => 'text-cat-usage',
        default => 'text-cat-fixed',
    };
    $metaParts = array_values(array_filter($card->metaParts, fn ($part) => $part !== $card->companyName));
@endphp
<li class="vk-co-compact">
    <x-ink.glyph :bucket="$card->bucket" size="28" class="vk-co-compact-glyph" />

    <div class="vk-co-compact-name">
        <h3 class="vk-co-compact-title">{{ $card->contractName }}</h3>
        <p class="vk-co-compact-meta">
            @foreach ($metaParts as $part)
                <span>{{ $part }}</span>
                <span aria-hidden="true">·</span>
            @endforeach
            <span class="font-bold {{ $catClass }}">{{ $card->band->headline }}</span>
            @if ($card->estimate)
                <span aria-hidden="true">·</span>
                <x-info-popover
                    variant="inked"
                    label="Arvio"
                    :heading="$card->estimate->heading"
                    :body="$card->estimate->body"
                    :link-url="$card->estimate->linkUrl"
                    :link-text="$card->estimate->linkText"
                />
            @endif
        </p>
        @if ($card->hasFooter())
            <x-card.footer variant="ledger" :warnings="$card->warnings" :facts="$card->facts" />
        @endif
    </div>

    <p class="vk-co-compact-parts">
        @foreach ($card->receiptLines as $line)
            @if (! $loop->first)
                <span aria-hidden="true">·</span>
            @endif
            <span>{{ $line->label }} <span @class(['vk-co-compact-part-value', 'vk-co-compact-part-value--soft' => $line->soft])>{{ $line->value }}@if ($line->unit)&nbsp;{{ $line->unit }}@endif</span></span>
        @endforeach
    </p>

    <div class="vk-co-compact-price">
        @if ($card->monthlyCost !== null)
            <p>
                <span class="vk-co-compact-monthly">{{ number_format($card->monthlyCost, 1, ',', ' ') }}</span>
                <span class="vk-co-compact-unit">€/kk</span>
                <span class="vk-co-compact-annual">{{ number_format($card->totalCost, 0, ',', ' ') }} €/v</span>
            </p>
            @if ($card->discountSavings !== null)
                <p class="vk-co-compact-saving">
                    <x-info-tip :text="$card->discountExplanation">{{ $card->benefitIsEstimate ? 'Arvioitu säästö' : 'Säästö' }}</x-info-tip>
                    {{ number_format($card->discountSavings, 0, ',', ' ') }} € / {{ $card->discountPeriodLabel }}
                </p>
                @if ($card->baseTotalCost !== null)
                    <p class="vk-co-compact-normal">{{ $card->normalPriceIsEstimated ? 'arvio ilman tarjousta' : 'ilman tarjousta' }} {{ number_format($card->baseTotalCost, 0, ',', ' ') }} € / {{ $card->discountPeriodLabel }}</p>
                @endif
            @endif
        @endif
    </div>

    <a href="{{ $card->detailUrl }}" class="vk-co-compact-action vk-focus">
        Katso<span class="sr-only"> sopimus {{ $card->contractName }}</span> <span aria-hidden="true">→</span>
    </a>
</li>
