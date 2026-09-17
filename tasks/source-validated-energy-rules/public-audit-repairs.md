# Public audit repairs — local only

## Result

The confirmed public-output fixes are complete locally. No financial arithmetic, DTO, estimator,
configuration default, Remotion code, production data or shared task status changed in this unit.
The pre-existing worktree remains in place. The release gate in `production-preflight.md` is not
changed by this report.

## Changes

- `ContractCardCopy` and `ContractDetail` describe `forward_premium` with current electricity
  futures and the retail-price addition estimated from comparable contracts. They do not describe
  this basis as holding the current price. The wording follows `ResetEstimateCopy`.
- `ContractDetailSeoPresenter` reads the real `contract_term` months and total to label short-term
  annualized costs. Both cost-bearing meta branches, and their shared JSON-LD description, use this
  basis. History and rank branches are unchanged. No relational price fallback was added.
- Detail FAQ, fixed-price qualifier, cancellation FAQ and terms grid no longer infer an unknown
  continuation from `TermPriceOnly`. They describe the real contract term converted to an annual
  comparison price. Source-rule explanations remain unchanged.
- `CardFooterItems` places the short-term comparison basis in a quiet fact, not a warning. This
  includes short Hybrids and leaves the existing consumption-effect warning and two-warning cap
  intact. It makes no claim about whether a continuation is published.
- Weekly output and contract-type comparison use real short-term metadata for the annualized
  basis label, including base-only Hybrids. Real-term promotion benefit is unchanged. Actual-only
  six-month contracts still do not qualify as offers without a normal-price benefit.
- The methodology page explains comparable company/market retail-premium fallback before the
  seasonal/hold fallback, and states that estimated normal-price savings are not guaranteed.

## Tests

Added or extended tests in:

- `tests/Feature/ContractCardPresenterTest.php`: premium reset card/detail copy; short Hybrid quiet
  term fact with the consumption-effect warning retained.
- `tests/Feature/ContractDetailPresenterTest.php`: six-month fixed contracts with and without a
  published continuation; FAQ, footer, terms and qualifier remain neutral.
- `tests/Unit/ContractDetailSeoPresenterTest.php`: both cost-bearing meta branches and shared Product
  description use real-term annualization.
- `tests/Feature/WeeklyOffersCanonicalPricingTest.php`: short Hybrid basis and unchanged real benefit;
  actual-only Fixed6 omitted from offers.
- `tests/Feature/ContractTypeComparisonTest.php`: short Hybrid annualized basis.
- `tests/Unit/EnergyRulePublicOutputTest.php`: actual-only source-rule Fixed6 has no weekly benefit.
- `tests/Feature/AboutPageMethodologyTest.php`: premium fallback and estimated savings copy.

Final command, from `laravel/`:

```sh
DB_URL='' APP_CONFIG_CACHE=/tmp/public-audit-no-config php artisan test tests/Unit/ContractDetailSeoPresenterTest.php tests/Unit/EnergyRulePublicOutputTest.php tests/Feature/ContractDetailPresenterTest.php tests/Feature/ContractDetailPageTest.php tests/Feature/ContractCardPresenterTest.php tests/Feature/WeeklyOffersCanonicalPricingTest.php tests/Feature/ContractTypeComparisonTest.php tests/Feature/AboutPageMethodologyTest.php tests/Feature/MarketResetEstimateSurfacesTest.php
```

Result: **241 passed, 1260 assertions, 5.75 seconds**. Log: `/tmp/public-audit-tests-final.log`.
`vendor/bin/pint --test` passed on all 13 touched PHP files. `git diff --check` passed.
Pint had first corrected imports in two test files. No CSS or JS changed; no asset build was needed.

Earlier runs found one old copy assertion and test fixture defects; these were corrected. One
filter-based discovery run stopped on the concurrent, unowned
`EnergyRuleEstimateDisclosureTest::output()` final-method conflict. The final explicit-file run
avoided unrelated discovery and passed. This unit did not change that test.

## Manager handoff

Per the parallel ownership instruction, shared task status and context files were not edited here.
Update the nearest context notes with these rules: `TermPriceOnly` is not proof of missing
continuation; short-term metadata also applies to base-only Hybrids; short-term footer facts do not
consume warning slots; premium reset copy is not hold-current copy. Remove the old contrary
ContractDetail terms/qualifier context notes when the parallel work is merged.

No network, application LLM, production operation, migration command, deployment, commit or push
was run. No default activation occurred.

## Short-term qualifier follow-up

Confirmed through the real Livewire/card presenter path: a V4-compatible Fixed6 contract with
6 c/kWh for months 1–3 and 8 c/kWh for months 4–6 reaches `fixedPriceQualifier`. No earlier
source-rule, reset or supplier guard prevents the false constant-price claim. Before the repair,
the regression reached the final copy assertion and received “Energian hinta 6,00 c/kWh ei muutu
6 kuukauden sopimusjakson aikana.” Both dated receipt prices were known, not estimated, and the
payload had `term_price_only`, six term months and a total annualized from the real term.

Only the short-term branch now says: “Vertailuhinta perustuu ilmoitettuihin hintoihin N kuukauden
sopimuskauden aikana.” It adds the existing annualization sentence only without an estimate
explainer. Early guards and 12/24-month copy are unchanged. No financial code, real cost/benefit
calculation or Historical path changed.

The new `ContractDetailPresenterTest` regression covers both the known price change and a constant
six-month price, real receipt rows, term basis, rendered copy and the no-explainer fallback.
The first run confirmed the phased defect and also found an invalid constant fixture with duplicate
same-price phases; the constant case now uses one six-month phase.

Final verification from `laravel/`:

```sh
DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config php artisan test tests/Feature/ContractDetailPresenterTest.php tests/Feature/ContractDetailPageTest.php tests/Feature/ContractCardPresenterTest.php
vendor/bin/pint --test app/Livewire/ContractDetail.php tests/Feature/ContractDetailPresenterTest.php
```

Result: **183 passed, 788 assertions, 3.14 seconds**. Log: `/tmp/short-term-copy-tests.log`.
Pint and `git diff --check` passed. Final diff and status were reviewed; prior changes remain.
No network, production operation, application LLM call, migration command, default change, commit
or push occurred. The parallel documentation owner must record the nearest-context rule: a short
term and annualization do not establish a constant energy price. Context files remain owner-managed.
