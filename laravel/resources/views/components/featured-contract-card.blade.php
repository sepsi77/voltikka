@props([
    'contract',
    'consumption' => null,
    'prices' => null,
    'detailConsumption' => null,
])

{{--
    The #1 slot on the homepage, /sahkosopimus, every SEO listing page and
    /halvin-sahkosopimus: the "cheapest" variant of the ledger contract entry.

    It renders contract-card.blade.php with `cheapest` and rank 1, so both slots share one
    template and one presenter (App\Services\ContractCard\ContractCardPresenter). Before the
    presenter, this file held its own copy of the derivation and had drifted: no
    price-increase warning, no market-reset figures, and no estimate marker. The most-seen
    entry on the site was the least honest one. Any card fact belongs in the presenter.

    The cheapest treatment: the "Halvin sähkösopimus" kicker, the fox-deep rank and price
    with the hand-drawn fox loop, and the fox-deep "Katso" (design spec §5).
--}}
<x-contract-card
    :contract="$contract"
    :rank="1"
    :cheapest="true"
    :consumption="$consumption"
    :prices="$prices"
    :detail-consumption="$detailConsumption"
/>
