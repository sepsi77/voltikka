# Integrated verification

## Scope
Preparation is complete. No commit, push, deployment, production inspection, data refresh, historical apply, or public method switch was run. Existing uncommitted pricing and About changes remain in the working tree.

## Code review
The parent reviewed the versioned calculator/writer changes, dated seasonal and reference-vintage boundaries, preview baseline and coverage logic, active-method reader changes, dated copy, and rollout documentation. Remaining v1-only code references are the retained API defaults and explicit diagnostic compatibility paths, not public reader branches.

The review corrected two details before completion:
- The relational energy guard follows supplied-rate precedence and both winter aliases. It accepts an explicit raw Spot margin for Spot only. Missing counterpart rates cannot become free energy.
- A typed mandatory base consumption effect can use the known annual base price despite an opaque zero cents/kWh placeholder. This applies only to the Unsupported base-only path with an explicit base effect; it does not depend on the legacy pricing-model enum. Nonzero, unknown, wrong-unit, positive-normal-price, absent-effect, optional-effect, fee-only, and conflicting-source guards remain. Source objects and exact-period behavior stay unchanged.

## Tests and build
- `cd laravel && php artisan test`: **2,276 passed, 10,522 assertions**, 92.89 seconds. Log: `/tmp/annual-v2-final-tests.log`.
- After final test-only import/type-reference formatting, `php artisan test --filter='CanonicalContractPriceCalculatorTest|AsOfAnnualCostCalculatorTest'`: **92 passed, 657 assertions**. Both formatted test files pass Pint. Application code did not change after the full suite.
- `cd laravel && npm run build`: passed. Log: `/tmp/annual-v2-build.log`. The existing nine-month-old Browserslist-data warning remains.
- `git diff --check`: passed.
- Changed AGENTS/CLAUDE pairs were checked for identical contents and preserved symlinks.
- Pint still reports style findings in the AsOf calculator; a HEAD copy also fails. Broad unrelated formatting was not applied. Earlier style findings in the two regression files were corrected, not described as pre-existing.

## Read-only local preview
Three full dates from the existing local SQLite snapshot were compared with stored v1. This is a sample, not a full-history audit or a measurement of live production. The script verifies the opened SQLite path, sets `PRAGMA query_only=ON`, uses an array cache, omits apply, and compares source/annual fingerprints before and after. The fingerprint remained unchanged. Peak PHP memory was 72.5 MiB.

Final log: `/tmp/annual-v2-final-preview.log`. Verification script: `/tmp/annual-v2-final-preview.php`.

Counts below are **contract-consumption identities**, not distinct contracts. Median ranges cover matched segment/consumption aggregates at 2,000, 5,000, and 18,000 kWh; they are not all 5,000-kWh changes.

| Date | Matched | New | Lost | Matched aggregate median delta |
|---|---:|---:|---:|---|
| 2026-02-15 | 840 | 132 | 9 | −€260.35 to +€0.51 |
| 2026-07-25 | 852 | 6 | 3 | −€11.78 to +€469.40 |
| 2026-08-10 | 825 | 0 | 0 | −€1.62 to +€109.61 |

No aggregate was lost in these samples. February adds nine aggregate identities. Missing snapshot identity and unknown dated eligibility remain explicit exclusions; the correction does not invent historical evidence.

## Coverage investigation
The first preview also lost Helen Valkkysähkö and Herrfors Vakaa because of their zero consumption-effect placeholders. The bounded fix restores their 5,000-kWh base estimates, €716.88 and €442.60, with the unknown effect still excluded and disclosed. Regression tests cover the arithmetic and chronology.

The remaining sampled losses are:
- February: Paneliankosken Käyttöwoima Kulutusjousto has a conflicting-source interpretation; Turku Energia Louna Helppo XS and S lack a known package allowance.
- July: Cheap Määräaikainen 6 kk has a conflicting-source interpretation.

These four cases account for 12 contract-consumption identities across the two dates. The existing conflicting-source and unidentifiable-package safeguards remain; they were not weakened to reproduce old totals. Review these source findings, all full-range coverage changes, and large numerical changes before approving an apply or activation.

## Release boundary
Follow `rollout.md`. A reviewed release, approved full backup, exact historical preview/apply range, and separately approved public method switch are still required. V1 rollback is selection of retained dated data, not continued execution of the old current algorithm. Unversioned snapshot provenance requires the documented same-day precautions and backup.
