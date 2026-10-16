{{--
    One version node of the contract detail page's version timeline.

    Extracted so the timeline can render its three newest versions on the page
    and the rest inside a <details> without two copies of this markup drifting
    apart. Deltas are always shown in the component's own unit (c/kWh or €/kk),
    never as a percentage: two consecutive "-8 %" rows read as a copy-paste bug.

    Redesign (Paper board 39Y-0 "Price timeline"): date lane, an inked rail with a node
    (the current active version is a fox ring), then the version content. A falling price is
    marked in the price-low colour with "↓", a rising one in price-high with "↑", so the
    direction never depends on colour alone. Layout: .vk-tl-* in resources/css/redesign-detail.css.

    @param array  $entry          one row of ContractDetail::$contractHistory + delta_to_previous
    @param bool   $showConnector  draw the rail down to the next node
    @param string $deltaUnit      unit label for the delta mark
    @param string $deltaSubject   what the delta describes, for example "Energianhinta"
    @param bool   $isOlder        an older version (dashed rail), optional
--}}
@php
    $isCurrent = $entry['is_current'] && $entry['is_active'];
    $delta = $showConnector ? $entry['delta_to_previous'] : null;
@endphp
<li @class([
    'vk-tl-item',
    'vk-tl-item--current' => $isCurrent,
    'vk-tl-item--older' => $isOlder ?? false,
    'vk-tl-item--last' => ! $showConnector,
])>
    <div class="vk-tl-when">
        <span class="vk-tl-caps">{{ $entry['last_seen_on_sale_date'] ? 'Viimeisin hintahavainto' : 'Hintahavainnot' }}</span>
        @if ($entry['last_seen_on_sale_date'])
            <span class="vk-tl-date">
                <time datetime="{{ $entry['last_seen_on_sale_date']->format('Y-m-d') }}">{{ $entry['last_seen_on_sale_date']->translatedFormat('j.n.Y') }}</time>
                @if ($isCurrent)
                    <span class="vk-tl-current">
                        <x-ink.sparkle size="12" color="currentColor" />
                        Nykyinen
                    </span>
                @endif
            </span>
        @else
            <span class="vk-tl-date">Ei tallennettuja hintahavaintoja</span>
        @endif

        @if ($entry['first_price_date'])
            <span class="vk-tl-meta">
                Ensimmäinen hintahavainto
                <time datetime="{{ $entry['first_price_date']->format('Y-m-d') }}">{{ $entry['first_price_date']->format('j.n.Y') }}</time>
                · {{ $entry['observation_count'] }} {{ $entry['observation_count'] === 1 ? 'havaintopäivä' : 'havaintopäivää' }}
            </span>
        @endif
    </div>

    <div class="vk-tl-rail" aria-hidden="true"><span class="vk-tl-node"></span></div>

    <div class="vk-tl-what">
        <p class="vk-tl-name">{{ $entry['name'] }}</p>

        @if (! empty($entry['prices']))
            <dl class="vk-tl-prices">
                @foreach ($entry['prices'] as $price)
                    <div>
                        <dt>{{ $price['label'] }}</dt>
                        <dd>
                            {{ number_format($price['price'], 2, ',', '') }}
                            {{ $price['unit'] === 'EUR/kk' ? '€/kk' : $price['unit'] }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if (! empty($entry['promotion']))
            <p class="vk-tl-promo">
                <x-ink.sparkle size="11" color="currentColor" />
                {{ $entry['promotion'] }}
            </p>
        @endif

        @if (! empty($entry['prices']))
            <p class="vk-tl-fine">Viimeisimmät havaitut hinnat. Hinnat ovat voineet muuttua havaintojakson aikana.</p>
        @endif
    </div>

    @if ($delta !== null)
        <div class="vk-tl-delta">
            <span class="vk-tl-delta-value {{ $delta > 0 ? 'vk-tl-delta-value--up' : 'vk-tl-delta-value--down' }}">
                <span aria-hidden="true">{{ $delta > 0 ? '↑' : '↓' }}</span>
                {{ $delta > 0 ? '+' : '' }}{{ number_format($delta, 2, ',', '') }} {{ $deltaUnit }}
            </span>
            <span class="vk-tl-delta-subject">{{ $deltaSubject }} muuttui</span>
        </div>
    @else
        <span class="vk-tl-spacer" aria-hidden="true"></span>
    @endif
</li>
