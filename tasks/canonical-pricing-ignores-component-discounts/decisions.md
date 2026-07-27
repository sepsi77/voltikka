# Decisions

## 2026-07-27 — How this was found, and what was decided on the spot

Found while adding an `Etu 12 kk:ssa` column to the new offers table on
`/sahkosopimus/sahkoyhtiot/{slug}`. The column states what a promotion is worth
against the first-year total, which matters because most promotions run 1-3
months: a "-5,90 €/kk alennus" pill is worth about 12 € over a year, not 70,80 €.

Building it exposed that `discount_savings_total` is 0 for 26 of 69 discounted
contracts. See `spec.md` for the measurement and the root cause.

### Decided: the company page hides the zero, it does not fix the calculation

`resources/views/livewire/company-detail.blade.php` renders a **dash** rather
than "0 €" whenever `discount_savings_total` is not above zero.

Reason: a zero is not evidence that the promotion is worthless. Vattenfall's
"Perusmaksut -50 % ensimmäiset 12 kuukautta" is a real 28,44 € benefit and
reports zero, so "0 €" would publish a false claim about a live offer. A dash
plus "ei voi laskea luotettavasti" is the honest statement of what we know.

Pinned by `test_promotion_benefit_shows_a_dash_instead_of_a_false_zero` in
`tests/Feature/CompanyDetailSectionsTest.php`. Remove the workaround only after
the underlying calculation is fixed.

### Decided: do not fix the pricing code in that session

The fix is in shared pricing code behind `CANONICAL_PRICING_ENABLED`, which is
live in production. It changes `total_cost`, and `total_cost` ranks contracts on
every listing, the cheapest page, the contract detail hero and the bill
comparison. That is a reviewed pricing change, not a display fix, and it was
outside the scope of the company-page work.

### Explicitly NOT decided — do not assume these

- **Whether the legacy calculator is right.** It is the *other* answer, not the
  correct one. For the four Kuukausipaketti products it removes 149-697 € by
  discounting the entire energy price to zero, which may be correct for a package
  fee that covers the energy, or may under-price a 2 006 € product by a third.
  Canonical calls those contracts `comparable_exact`, meaning it believes it
  priced them right *without* the discount. Somebody has to look at the actual
  product terms.
- **Whether a base-fee discount should apply to a Hybrid.** Hybrids are costed
  base-only on purpose because the consumption effect is unquantifiable. A
  discount on the base monthly fee looks applicable, but check
  `app/Services/CanonicalPricing/AGENTS.md` first.
- **Whether a promo month inside a 6 kk term survives annualisation.**

### Retracted

An earlier reading of the data blamed `n_first_months`, on the theory that
`n_first_months = 12` was mishandled. That is wrong. `n_first_months = 0` means
"no month limit" to the legacy calculator (a fixture with `months = 0` produced a
full 12 × 3,00 = 36,00 € saving), and the savings scale sensibly with the promo
length under the legacy path at every value. The variable that actually predicts
a zero is **which calculator runs**, not the promo length: 25 of the 26 zeros are
canonical-only.

## 2026-07-27 — Canonical `normal_amount` savings implementation

Canonical pricing now calculates the actual and promotion-free prices from the
canonical JSON. It does not read relational `price_components` as a fallback.
This preserves canonical pricing as the source of truth and prevents a raw row
from overriding a validated phase timeline. A component uses `normal_amount` in
the promotion-free pass only when it is higher than `amount`. Both passes use the
same usage profile, Spot assumptions, and market-reset offsets. The outcome stores
measured total and monthly savings.

This fixes the clear Vattenfall base-fee offers. It does not change Surffari,
included-energy package interpretation, duplicate package fees, or offers that
are absent from canonical JSON. Those items remain in
`tasks/canonical-pricing-source-of-truth-completion/`. Kuukausipaketti is not
priced from the relational full-energy discount in this change because its
canonical interpretation is the validated source and the package meaning is a
separate source-interpretation question.

## 2026-07-27 — Correction: held-forward paths must keep phase timing

The first implementation used the phase timeline in the normal listed path, but
it still called the one-phase held-forward path for every unsupported Hybrid and
every short fixed term. That extended an introductory phase past its disclosed
end. The documentation claim that those outcomes used the same phase segments
was therefore false.

The correction reuses `PhaseTimelineBuilder` and `costWindow()`:

- A fully covered Hybrid costs all disclosed base-price phases over 12 months.
  The unknown consumption effect remains excluded. Only an uncovered Hybrid uses
  the old held-current fallback.
- A short fixed term costs every disclosed phase up to the real term end. It then
  multiplies both actual and promotion-free term results by `12 / term_months`.
  It does not fill the unknown continuation.
- Market-reset offsets still come from one shared estimate. Constant single-price
  Hybrid and short-term controls retain their prior totals.

### Read-only local-data check

Run on the 2026-07-27 local database at 5,000 kWh with the application service:

- Active `epasaz-hehku-energia-oy-hehku-yritysjousto-24-kk`: total
  **386.500000 EUR**, base **413.500000 EUR**, saving **27.000000 EUR**,
  `base_only_hybrid`, `hybrid_base_only`.
- Local Vattenfall business-shape row
  `difwxj-vattenfall-oy-yrityksen-optimi-porssisahko-perusmaksut-50-ensimmaiset-12-kuukautta`:
  total **422.860341 EUR**, base **451.300341 EUR**, saving **28.440000 EUR**,
  `comparable_estimate`, `rolling_365_spot`. Both checked rows are present in the
  application's `active()` scope. The Vattenfall output is unchanged by the
  timing correction.

The Hehku result is coherent: `413.50 - 386.50 = 27.00`.

### Local blast radius, not deployed

A read-only reflection check compared the corrected calculator with its prior
held-forward branch for all 425 active local contracts at 5,000 kWh. **20 active
contracts change**: **12 `base_only_hybrid`** and **8 `term_price_only`**. Total
changes range from **-7.30 EUR to +59.00 EUR**. Hehku Yritysjousto changes from
359.50 to 386.50 EUR and its saving changes from 54.00 to 27.00 EUR. This is the
expected correction for offers that the old path extended too far.

`contracts:compare-canonical-pricing --consumption=5000` completed for all 425
active contracts. Its current distribution is 59 base-only Hybrid, 138 comparable
estimate, 193 comparable exact, 11 excluded incomplete, and 24 term-price-only.
It reports 7 integrity labels. Final verification passed: the canonical/market-reset
selection ran 80 tests with 323 assertions, and the complete Laravel suite ran
1,459 tests with 4,709 assertions. No production command, deployment, cache clear,
or data write was run.
