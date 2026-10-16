{{--
    Explains the three pricing categories of the contract entries on listing pages
    (Paper board 2J3-0, "Results heading"): an inked sparkle in the category colour and the
    category name in that colour, 14/18 800. No swatches or chips.

    Keep the wording identical to PricingCategory::label(). Markkinahinta uses the spot
    colour (aurora), as on the board: it covers spot and market-reset prices.
--}}
<ul {{ $attributes->class(['flex flex-wrap items-center gap-x-[18px] gap-y-1.5 text-sm font-extrabold leading-[18px]']) }}>
    <li class="flex items-center gap-1.5" style="color: var(--color-cat-fixed)">
        <x-ink.sparkle size="13" color="var(--color-cat-fixed)" />
        Kiinteä hinta
    </li>
    <li class="flex items-center gap-1.5" style="color: var(--color-cat-spot)">
        <x-ink.sparkle size="13" color="var(--color-cat-spot)" />
        Markkinahinta
    </li>
    <li class="flex items-center gap-1.5" style="color: var(--color-cat-usage)">
        <x-ink.sparkle size="13" color="var(--color-cat-usage)" />
        Kulutusvaikutus
    </li>
</ul>
