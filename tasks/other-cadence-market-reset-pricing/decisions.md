# Decisions

## 2026-07-30 — diagnosis

Production interpretation 1557 for `64ge8w-lumme-energia-perussahko` is internally correct:

- current energy price: 12.90 c/kWh
- monthly fee: 5.56 EUR/month
- structured pricing: complete
- misleading state: uncertain, not detected
- recurring schedule: present, cadence `other`, price reviewed 2–4 times per year
- calculation status: `estimate_required`

The live calculator returns `excluded_unknown_future` only because `RecurringScheduleData::isActiveReset()` accepts monthly, quarterly, and seasonal cadences but not `other`. The category resolver already accepts `other`, which causes the page contradiction.

Production has seven active `other`-cadence contracts. Three have `estimate_required` and can become comparable. Four Turku Energia package contracts are `incomplete` and must stay excluded by the existing incomplete-pricing gate.

## Pricing proxy

Use the quarterly cadence calendar and quarter-shaped futures reference for `other`. The source says these prices change several times per year, but it does not give exact period boundaries. A quarterly proxy is a clear approximation and matches the existing treatment requested for this contract. The public copy stays generic (`jaksoittain`) and the annual total stays marked as an estimate.

## 2026-07-30 — implementation

`other` is now an active canonical reset cadence. It uses the existing non-monthly quarterly calendar proxy and now also requests quarter or quarter-month-average futures references. The incomplete-pricing gate stays before reset handling, so the four incomplete Turku Energia package contracts remain excluded.

The change affects list, company, ranking, and prepared page membership on a code-only release. Their payload schema versions were increased so old cached exclusions cannot survive the release.

Focused verification passed: 104 tests and 480 assertions across the canonical calculator, forward-shift calculator, cache memoization, reset surfaces, and company list suites. `git diff --check` also passed.
