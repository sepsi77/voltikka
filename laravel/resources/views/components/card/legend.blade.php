{{--
    Explains the contract-card type-band tints on listing pages.

    It replaced the emissions colour legend (Päästötön / Vähäpäästöinen / Fossiilinen),
    which described the card's coloured left stripe. That stripe is gone: the band tint and
    a 4px emissions stripe on the same card competed with each other, and the emissions
    information moved to the footer as a data tag carrying the real figure rather than a
    three-step colour tier.

    The tints are documented in DESIGN.md as a semantic data-colour axis, like the emissions
    tiers. Keep the wording identical to PricingCategory::label().

    Each swatch is the band's own tint (100) with the band's own hairline (200) as its border,
    so the legend shows the colour it names. It previously used the 200 step alone, which made
    every swatch a shade the cards never display.
--}}
<ul class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-600">
    <li class="flex items-center gap-1.5">
        <span class="h-3 w-3 flex-shrink-0 rounded border border-slate-200 bg-slate-100" aria-hidden="true"></span>
        Kiinteä hinta
    </li>
    <li class="flex items-center gap-1.5">
        <span class="h-3 w-3 flex-shrink-0 rounded border border-sky-200 bg-sky-100" aria-hidden="true"></span>
        Markkinahinta
    </li>
    <li class="flex items-center gap-1.5">
        <span class="h-3 w-3 flex-shrink-0 rounded border border-violet-200 bg-violet-100" aria-hidden="true"></span>
        Kulutusvaikutus
    </li>
</ul>
