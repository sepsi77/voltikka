# Canonical pricing ignores price-component discounts, so promotions are not applied

Status: **completed locally on 2026-07-27; not deployed.** Canonical
`normal_amount` offers now use measured actual and promotion-free passes. A
manager-review correction also preserves all disclosed phase timing for fully
covered Hybrids and short fixed terms. See `decisions.md` for the exact local
checks and blast radius.

## Reported symptom

The new `Etu 12 kk:ssa` column on `/sahkosopimus/sahkoyhtiot/{slug}` had to be
built to hide zeros, because `calculated_cost.discount_savings_total` returns
**0 for 26 of 69 discounted active contracts**, including promotions that are
demonstrably real. Example:

> **Vattenfall, "Yrityksen Optimi Pörssisähkö – Perusmaksut -50 % ensimmäiset 12 kuukautta"**
> `Monthly` price 4,74 €/kk, `discount_value` 2,37, `discount_discount_n_first_months` 12.
> Exactly half the fee, for a year, and the offer is in the product name.
> `discount_savings_total` = **0,00 €**.

## Root cause

`app/Services/CanonicalPricing/` **never reads the discount metadata at all.**

```bash
grep -rn "has_discount\|discount_value\|discount_discount_n_first_months" laravel/app/Services/CanonicalPricing/
# no matches
```

`CanonicalPricingOutcome::discountSavingsTotal()` is derived, not measured:

```php
return max(0.0, $this->baseTotalCost - $this->totalCost);   // DTO/CanonicalPricingOutcome.php:66
```

and one of the two main construction paths sets them equal:

```php
totalCost: $total,
baseTotalCost: $total,   // CanonicalContractPriceCalculator.php:304
```

So canonical pricing surfaces a promotion **only when the canonical JSON models
it as a pricing phase** (the deceptive-pricing machinery: a cheap first phase
followed by a dearer one, which makes `base != total`). A promotion expressed
only as relational component metadata — `has_discount` + `discount_value` +
`discount_discount_n_first_months` on a `price_components` row — is invisible.

That split matches the data exactly: 43 of 69 discounted contracts have a
phase-modelled promo and report a saving; the other 26 do not.

`CANONICAL_PRICING_ENABLED=true` in production, so this is the live path.

## Measured scope (2026-07-27, 5 000 kWh, local snapshot of production data)

69 active contracts carry a live discount. Canonical returns zero savings for
26 of them. **25 of those 26 are canonical-only**: the legacy
`ContractPriceCalculator` computes a real saving on the same components.

| Shape | Contracts | Legacy saving, examples |
|---|---|---|
| FixedPrice / OpenEnded (Kuukausipaketti) | 4 | 697,20 · 498,00 · 298,80 · 149,40 € |
| Hybrid / FixedTerm (6, 12, 24 kk) | 11 | 5,90 … 27,00 € |
| FixedPrice / FixedTerm / Fixed6 | 6 | 3,50 … 5,90 € |
| Spot / OpenEnded | 4 | 1,67 · 28,44 · 35,76 · 52,88 € |
| Zero under **both** calculators | 1 | (probably genuinely zero) |

Largest individual discrepancies:

| Legacy saving | Company | Contract | Model | Canonical verdict | Live total |
|---|---|---|---|---|---|
| 697,20 € | Vaasan Sähkö Myynti Oy | Kuukausipaketti L | FixedPrice | comparable_exact | 2 006 € |
| 498,00 € | Vaasan Sähkö Myynti Oy | Kuukausipaketti M | FixedPrice | comparable_exact | 1 250 € |
| 298,80 € | Vaasan Sähkö Myynti Oy | Kuukausipaketti S | FixedPrice | comparable_exact | 1 082 € |
| 149,40 € | Vaasan Sähkö Myynti Oy | Kuukausipaketti XS | FixedPrice | comparable_exact | 956 € |
| 52,88 € | Nordic Green Energy | Ilmasto pörssisähkö | Spot | comparable_estimate | 427,30 € |
| 35,76 € | Vattenfall Oy | Optimi Pörssisähkö – Perusmaksut -50 % | Spot | comparable_estimate | 435,06 € |
| 28,44 € | Vattenfall Oy | Yrityksen Optimi Pörssisähkö – Perusmaksut -50 % | Spot | comparable_estimate | 422,86 € |
| 27,00 € | Hehku Energia Oy | Hehku Yritysjousto 24 kk | Hybrid | base_only_hybrid | 359,50 € |

## Impact beyond the new column

`discount_savings_total` is cosmetic, but it is derived from `total_cost`, and
**`total_cost` is what ranks contracts everywhere.** If the promotion is not
applied, the contract is priced and ranked at its undiscounted price on the
homepage, `/sahkosopimus`, every SEO listing page, `/halvin-sahkosopimus`, the
contract detail hero and rank, and the bill-comparison surfaces.

A contract carrying a genuine 28-52 € first-year benefit is therefore ranked
below rivals it should beat. The card's "ilman tarjousta" / savings copy also
disappears, so the visitor is never told the offer exists.

## Not every case is a straight bug — one needs a product decision

**Do not "fix" this by making canonical copy the legacy number.** The legacy
result is not automatically the right answer:

- **Kuukausipaketti XS/S/M/L** store `General` 16,60 c/kWh with a `discount_value`
  of 16,60 — the whole energy price — beside a 35 €/kk fee. Legacy therefore
  removes 149-697 € and prices the package as fee-only. That may be exactly right
  (a package fee that already covers the energy) or badly wrong (a 2 006 € product
  listed at 1 309 €). The canonical interpretation calls these
  `comparable_exact`, so it believes it priced them correctly **without** the
  discount. Decide what these products actually are before changing their price.
- **Hybrids** are costed base-only by design (`base_only_hybrid`), because the
  consumption effect is unquantifiable. A discount on the base monthly fee still
  looks legitimately applicable, but confirm against
  `app/Services/CanonicalPricing/AGENTS.md` before assuming it.
- **Short fixed terms** (`Fixed6`) are annualised. Confirm whether a promo month
  inside the real term should survive annualisation, and how, before applying it.

The **Spot / OpenEnded** group is the clearest genuine bug: an ordinary
percentage discount on a base fee, disclosed in the product name, silently
dropped.

## Requirements

1. Canonical pricing must account for relational component discounts, or state
   in code and in `AGENTS.md` exactly why a given shape deliberately ignores
   them. Silent divergence from the legacy calculator is the defect.
2. A promotion must not be double counted when it is already modelled as a
   pricing phase in the canonical JSON. Phase-modelled and component-modelled
   promotions have to resolve to one number.
3. `discount_savings_total` must be a measured figure, not `base - total`
   arithmetic that silently returns 0 whenever the two paths happen to agree.
4. Decide the Kuukausipaketti question explicitly and record it in
   `decisions.md`; it changes published prices by up to 697 €.
5. Whatever changes, `total_cost` moves, so treat it as a reviewed pricing
   change: run `php artisan contracts:compare-canonical-pricing` and inspect the
   ranking diff before shipping.

## Acceptance

- No active contract reports `discount_savings_total = 0` while the legacy
  calculator reports a saving, unless a documented rule explains that shape.
- Vattenfall's "Perusmaksut -50 % ensimmäiset 12 kuukautta" contracts report a
  saving near 28,44 € and 35,76 € at 5 000 kWh.
- The company page's `Etu 12 kk:ssa` column shows a figure instead of a dash for
  those contracts, with no change needed on that page.
- `php artisan test` stays green, and the ranking diff from
  `contracts:compare-canonical-pricing` is reviewed and recorded.

## Where to look

- `laravel/app/Services/CanonicalPricing/CanonicalContractPriceCalculator.php`
  (lines ~245 and ~304 build the two `CanonicalPricingOutcome` shapes)
- `laravel/app/Services/CanonicalPricing/DTO/CanonicalPricingOutcome.php:66`
- `laravel/app/Services/CanonicalPricing/AGENTS.md`
- `laravel/app/Services/ContractPriceCalculator.php` (the discount-aware legacy path)
- `laravel/app/Models/ElectricityContract.php`
  (`getLatestPriceComponentsForCalculation()`, `getActiveDiscountInfo()`,
  `formatActiveDiscountValue()`)
- `laravel/app/Livewire/AGENTS.md`, `CompanyDetail` section, records how the
  company page works around this today

## Reproduce

```bash
cd laravel && php artisan tinker
```

```php
$canon = app(App\Services\CanonicalPricing\CanonicalContractPricingService::class);
$legacy = app(App\Services\ContractPriceCalculator::class);
$usage = new App\Services\DTO\EnergyUsage(total: 5000, basicLiving: 5000);
$avg = App\Models\SpotPriceAverage::latestRolling365Days();

App\Models\ElectricityContract::active()->with('priceComponents')->chunk(40, function ($chunk) use ($canon, $legacy, $usage, $avg) {
    foreach ($chunk as $c) {
        if (! $c->hasActiveDiscounts()) continue;
        $pc = $c->getLatestPriceComponentsForCalculation();
        $l = $legacy->calculate($pc, ['contract_type' => $c->contract_type, 'pricing_model' => $c->pricing_model, 'metering' => $c->metering], $usage, $avg?->day_avg_with_tax, $avg?->night_avg_with_tax)->toArray();
        $k = $canon->evaluate($c, $usage)['outcome']->toCalculatedCostArray();
        if (($k['discount_savings_total'] ?? 0) > 0.005 || ($l['discount_savings_total'] ?? 0) <= 0.005) continue;
        echo sprintf("%8s | %s | %s\n", round($l['discount_savings_total'], 2), $c->company_name, $c->name);
    }
});
```

Chunk the query. Loading every active contract with `priceComponents` at once
exhausts memory.
