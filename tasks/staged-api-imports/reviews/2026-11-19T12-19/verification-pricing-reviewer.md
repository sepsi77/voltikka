# pricing-reviewer — staged-api-imports verification
Range: cdc93650e77e1db73f7f9067fee987a73de59c62..743f1c14a0066e6e65ac042bc529ff91ef877a5c · Result: 1 Fixed, 2 Partially fixed; 0 new findings

Static verification of the requested fixes and their direct regressions only. No tests, live API calls, production access, Artisan commands or database probes were run. Untracked verification prompts are not source changes in scope. The concurrency counterexamples below describe permitted code execution, not observed production incidents.

## Verification verdicts

| Finding / check | Verdict | Evidence and assessment |
|---|---|---|
| PRC-001 — superseded contract refusal | **Partially fixed** | `laravel/app/Console/Commands/FetchContracts.php:196,342-348` rejects an older fetch against the global newest observation before replay checkpoint takeover. Timezone handling agrees with `laravel/app/Services/ContractImport/ContractImporter.php:57`. Sequential A→B→replay-A is fixed, including full/scoped callers and preservation of newer membership. However, the check is outside the import transaction, before payload parsing, and is never repeated under serialization: the concurrent form remains possible. See residual below. |
| PRC-002 — Spot-forecast display | **Partially fixed** | `laravel/app/Console/Commands/FetchSpotForecast.php:97-131,149-159` checks both archived runs and display rows, skips the whole older display projection, always archives the vintage, and invalidates the cache only for a display write. Sequential replay, including an unchanged newer refetch without a new archive row, is fixed. The plain existence check is not atomic with the unconditional upsert; the explicitly accepted overlap can still publish the original wrong curve. See residual below. |
| LAR-001 — Spot averages in transaction | **Fixed** for the requested transaction correction | `laravel/app/Console/Commands/FetchSpot.php:133-138` imports prices and calculates averages before returning from the store closure; `laravel/app/Console/Concerns/StagedImport.php:100-112` commits that closure before invoking after-commit work. An average failure now rolls back the raw prices and preceding averages together. Only logging/cache warming remain afterward. Rolling date still comes from `Carbon::today('Europe/Helsinki')` at `laravel/app/Services/SpotPriceAverageService.php:233-241`, deliberately retained by `tasks/staged-api-imports/decisions.md:109`; this is current-database recalculation, not fetch-vintage evidence. That documented date policy is not reopened. |
| Average-row lookup on MySQL | **Pass — no stored pricing identity/value change found** | All five writers preserve region, period type, period start/end, hour selection and numerical fields (`laravel/app/Services/SpotPriceAverageService.php:84-102,145-163,205-223,260-278,301-319`). The date moves from lookup attributes into update/create values while the lookup gains `whereDate`. See MySQL assessment below. |

Inspected, not executed: sequential contract refusal/idempotency regression at `laravel/tests/Feature/FetchContractsCommandTest.php:1278-1307`; forecast display/provenance regressions at `laravel/tests/Feature/FetchSpotForecastCommandTest.php:259-324`; real-service Spot parity/rollback/replay cases at `laravel/tests/Feature/FetchSpotStagedImportTest.php:98-205`. These do not establish concurrent MySQL ordering.

## Remaining parts of the original findings

### PRC-001 — freshness precheck can precede a newer committed import
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S5/S8; source-evidence/current-pricing safeguards. The refusal policy is recorded at `tasks/staged-api-imports/decisions.md:110`; the lack of serialization is explicitly documented at `laravel/app/Services/ContractImport/AGENTS.md:21`.
- **Evidence**: `laravel/app/Console/Commands/FetchContracts.php:196-207,231-237,271,342-348`; `laravel/app/Services/ContractImport/ContractImporter.php:62-70,289-315,335-339`; `laravel/app/Services/ContractInterpretation/ContractInterpretationDispatcher.php:160-180`.
- **What remains wrong**: the aggregate freshness read protects only evidence already committed at that read. Contract row locks later serialize writes but do not compare source times. Worked interleaving, same Helsinki day:
  1. Replay A (06:00, fully known 12-month price **8 c/kWh**, no fees) passes the precheck while newest committed evidence is still A; it pauses before importer entry, for example during payload reconstruction.
  2. A newer scoped store B (10:00, **12 c/kWh**) commits.
  3. A resumes, takes its replay checkpoint if full-scope, imports without another freshness check and points the contract back to A. Reusable validated A output can publish synchronously.

  Expected at 5,000 kWh: B, **€600/year**. Permitted actual: A, **€400/year**, plus a false recurrence dated 06:00. Full A can also restore superseded availability. This is the concurrent remainder of PRC-001, not a new unrelated finding.
- **User impact / blast radius**: current source pointers and observations, same-day components and membership, followed by today's statistics and verified replacement cache generation. Cards, detail/API and rankings can expose the €400 price. Checkpoint ownership does not repair source ordering when A claims after B.
- **Fix shape**: serialize the freshness check and authoritative store across full and scoped imports, with the comparison performed after acquiring that serialization and before any mutation. Merely adding a second unlocked read or taking maximum timestamps does not protect price/membership writes:
  ```php
  // Under shared contract-store serialization, excluding fetch/network work:
  $this->refuseSupersededPayload($payload->fetchedAt());
  // Claim replay owner, then perform the existing authoritative import transaction.
  ```
  Add a controlled A-check → B-commit → A-write regression. No annual-method/schema bump is needed. Any already affected rows/cache generations would require separately approved investigation/repair.

### PRC-002 — accepted overlap still permits the stale mixed-vintage public curve
- **Severity**: High
- **Likelihood**: Low
- **Class**: blocking
- **Spec rule**: S8; exact published figures. `tasks/staged-api-imports/decisions.md:111` explicitly accepts an interleaving scheduled store/manual replay to avoid gap-lock deadlocks. This reports its concrete numerical consequence rather than reopening the general locking trade-off.
- **Evidence**: `laravel/app/Console/Commands/FetchSpotForecast.php:97-119,126-131,149-159`; `laravel/app/Console/Concerns/StagedImport.php:102`; public readers `laravel/app/Livewire/SpotPrice.php:759-796` and `laravel/app/Http/Controllers/SpotPriceCsvController.php:76-94`.
- **What remains wrong**: a transaction does not make the nonlocking existence read an atomic condition on a later upsert. Worked interleaving:
  1. Old A (04:00, H = **8 c/kWh**) sees no newer stored feed and sets `displayCurrent=true`.
  2. B (10:00) stores H = **11**, H+1 = **12**, and commits.
  3. A's unconditional upsert runs afterward, replacing H with 8 and its old provenance; H+1 stays 12.

  Expected latest curve **[11, 12]**; actual **[8, 12]**. The page independently selects maximum `fetched_at`, so its update label can still be 10:00. InnoDB row locking of the upsert does not re-evaluate the earlier PHP boolean. This needs a narrow overlap, hence Low likelihood, but it reproduces the original wrong published figures.
- **User impact / blast radius**: `/spot-price` and its CSV consume the regressed display rows. A invalidates `spot_price:forecast:nordpool_predict_fi:v1`, making the mixed curve visible immediately. Both append-only vintages remain correct; official prices/averages are unaffected.
- **Fix shape**: serialize the source/region display promotion and perform a fresh recency comparison within that protected boundary, or use an equivalent atomic monotonic database projection. A serialization alternative need not gap-lock both tables:
  ```php
  // Under source/region store serialization:
  if (! $this->newerFeedStored($records[0])) {
      // Existing display projection and its provenance together.
  }
  // Retain the append-only vintage independently.
  ```
  Preserve fetch times and cache invalidation-after-commit. Add an interleaving regression. No archive rewrite/schema bump is required; any regressed display requires latest-vintage reconstruction and cache invalidation.

## MySQL average identity/value assessment

**No semantic change on the declared MySQL schema.** `period_start` is a MySQL `DATE`, with unique identity `(region, period_type, period_start)` (`laravel/database/migrations/2026_01_20_043838_create_spot_price_averages_table.php:20,42-43`). Therefore direct equality to calendar date D and `DATE(period_start) = D` identify the same row. Rolling inputs are normalized to Helsinki midnight before lookup (`SpotPriceAverageService.php:246-248,287-289`); Laravel formats the `whereDate` Carbon binding as `Y-m-d` (`laravel/vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:1862-1881`).

`updateOrCreate` retains the pre-existing query constraint during lookup, merges attributes and values for insertion, and fills values on update (`laravel/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:753-794`). Thus moving `period_start` into values neither omits it on insert nor permits another date/region/type to be updated. The model retains its date casts (`laravel/app/Models/SpotPriceAverage.php:52-53`). Existing row IDs and financial values remain the same for the same stored hour set; normal bookkeeping timestamp behavior is not a pricing identity change. SQLite's datetime-text mismatch is repaired. Query performance/locking is not certified by this value-equivalence assessment; no MySQL server was accessed.

## New findings

Findings: none beyond the remaining portions of PRC-001 and PRC-002 above.

## Files inspected

- Requested four-file fix diff; shared `StagedImport.php`; relevant importer/interpretation-dispatcher sections; `SpotPriceAverage.php`; Spot average migration; forecast page/CSV readers; relevant Laravel vendor query/Eloquent implementations.
- The three regression-test files cited above (read only).
- Task `spec.md`, `tasks.json`, `decisions.md` review-round section, `design.md` §7a, original pricing/Laravel reports and verification prompt; spec-review instructions.
- Root/Laravel and nearest import, forecast, official Spot, canonical pricing, statistics, caching, retail-premium and card contexts.
