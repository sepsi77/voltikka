@props(['insight' => null])
{{--
    The energy price index on the sky (Paper board 2J3-0, "Price index on sky"): the hero
    aside of the listing pages. It replaces <x-contract-market-insight-pills> on these pages
    and shows the same facts from the same ContractMarketInsightService payload: the 30-day
    trend (title, change, period, supporting line, date, offer and supplier count, link)
    and, when the page has one, the fixed-term forecast as a compact second block.

    Colours come from the hero tone variables (--tone-strong, --tone-soft, --tone-lead,
    --tone-accent, --tone-underline), so the light hero tone of bright seasons works too.
    Not a card: text sits on the sky.
--}}
@php
    use Carbon\Carbon;

    $formatDate = static function (?string $date): ?string {
        if (! $date) {
            return null;
        }
        try {
            return Carbon::parse($date)->format('j.n.Y');
        } catch (\Throwable) {
            return null;
        }
    };

    $trend = ! empty($insight['has_items']) && ! empty($insight['trend']) ? $insight['trend'] : null;
    $forecast = ! empty($insight['has_items']) && ! empty($insight['forecast']) ? $insight['forecast'] : null;

    $trendChange = null;
    if ($trend) {
        $trendChange = $trend['change_label']
            ?? (isset($trend['change_percent'])
                ? sprintf(
                    '%s%s %%',
                    $trend['change_percent'] > 0 ? '+' : ($trend['change_percent'] < 0 ? '−' : '±'),
                    number_format(abs((float) $trend['change_percent']), 1, ',', ' ')
                )
                : '—');
    }
    $contractCount = $trend['contract_count'] ?? null;
    $supplierCount = $trend['supplier_count'] ?? null;
@endphp

@if ($trend || $forecast)
    {{-- Quiet version (Paper "R4 · calm top", 2026-10-23): the index is context, not the page's
         headline, so it has no kicker, a 15px title, a 32px number in the strong tone (not the
         accent) and one caption line. On a phone it is one line under the lead. --}}
    <div {{ $attributes->class(['vk-index']) }} data-price-index>
        @if ($trend)
            @php
                $trendTitle = $trend['eyebrow'] ?? '30 päivän trendi';
                $trendSupporting = $trend['supporting'] ?? $trend['headline'] ?? '';
                $trendDate = $formatDate($trend['as_of'] ?? null);
                $trendLink = $trend['link_label'] ?? 'Lue lisää';
                $trendBasis = collect([
                    $contractCount
                        ? number_format($contractCount, 0, ',', ' ').($supplierCount ? ' tarjousta, '.number_format($supplierCount, 0, ',', ' ').' yhtiötä' : ' sopimusta')
                        : null,
                    $trendDate ? 'päivitetty '.$trendDate : null,
                ])->filter()->implode(', ');
            @endphp
            <p class="vk-index-title">{{ $trendTitle }}</p>
            <p class="vk-index-figure">
                <span class="vk-index-number">{{ $trendChange }}</span>
                <span class="vk-index-period">30 pv</span>
            </p>
            @if ($trendSupporting || $trendBasis)
                <p class="vk-index-text">
                    {{ $trendSupporting }}@if ($trendSupporting && $trendBasis). @endif{{ $trendBasis ? ucfirst($trendBasis).'.' : '' }}
                </p>
            @endif
            <a href="{{ $trend['url'] }}" class="vk-index-action vk-focus"
               aria-label="{{ $trendTitle }}: {{ $trendChange }}. {{ $trendSupporting }}. {{ $trendDate ? 'Päivitetty '.$trendDate.'.' : '' }} {{ $trendLink }}.">
                <span>{{ $trendLink }} →</span>
                <x-ink.wave width="150" stroke="2" color="var(--tone-underline)" />
            </a>
        @endif

        @if ($forecast)
            @php
                $forecastTitle = $forecast['eyebrow'] ?? '12 kk ennuste';
                $forecastFocal = $forecast['direction_label']
                    ?? trim(preg_replace('/^Ennuste:\s*/u', '', (string) ($forecast['headline'] ?? '—')));
                $forecastSupporting = $forecast['supporting'] ?? $forecast['detail'] ?? '';
                $forecastDate = $formatDate($forecast['forecast_date'] ?? null);
                $forecastLink = $forecast['link_label'] ?? 'Lue lisää';
            @endphp
            <div @class(['vk-index-forecast', 'vk-index-forecast--after' => $trend])>
                <p class="vk-index-subkicker">{{ $forecastTitle }}</p>
                <p class="vk-index-direction">{{ $forecastFocal }}</p>
                @if ($forecastSupporting || $forecastDate)
                    <p class="vk-index-text">
                        {{ $forecastSupporting }}@if ($forecastSupporting && $forecastDate) <span aria-hidden="true">·</span> @endif
                        @if ($forecastDate)<span class="whitespace-nowrap">Päivitetty {{ $forecastDate }}</span>@endif
                    </p>
                @endif
                <a href="{{ $forecast['url'] }}" class="vk-index-action vk-index-action--small vk-focus"
                   aria-label="{{ $forecastTitle }}: {{ $forecastFocal }}. {{ $forecastSupporting }}. {{ $forecastDate ? 'Päivitetty '.$forecastDate.'.' : '' }} {{ $forecastLink }}.">
                    <span>{{ $forecastLink }} →</span>
                    <x-ink.wave width="120" stroke="2.6" color="var(--tone-underline)" />
                </a>
            </div>
        @endif
    </div>
@endif
