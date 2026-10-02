# Complete local city GET measurement

## Scope

Research agent measured complete Laravel HTTP-kernel GET handle and terminate for Lapinjarvi and Mikkeli, with their actual local-contract cards. Manager read the full findings, summary and request harness and verified the relevant source call sites. This is not browser/socket/asset timing: safe console bootstrap occurs before the request timer. Local PHP 8.5.11/SQLite and September 22 comparison date use the older 378-contract snapshot. Baseline loads HEAD copies of only PhaseTimelineBuilder and WindowSegment; other current worktree code remains identical. It is not a full deployed-code replay.

Safety: mode=ro SQLite plus query_only, all recorded queries SELECT/WITH; isolated temporary file caches/locks/views/manifests, array sessions, disabled Sentry/network logging and blocked Laravel external HTTP. Every cold scenario gets a new temporary directory; concurrent workers share that directory. No production requests, database writes, repository code edits or active cache flushes.

## Measured successful responses

| Scenario | Current local code | HEAD copies of two changed classes |
|---|---|---|
| Lapinjarvi sequential cold median, 3 requests | 4.320 s | 6.392 s |
| Mikkeli sequential cold median, 3 requests | 4.126 s | 6.015 s |
| Two concurrent, successful responses | 3.658–4.163 s | 6.240–7.381 s |
| Four concurrent, successful responses | 4.369–4.549 s | 7.139–7.651 s |
| Eight concurrent | 6.324–6.585 s | 10.586–11.200 s |

Sequential requests used 87/86 queries; current median SQL time was 0.845/0.742 s. All sequential and initial eight-worker responses were HTTP 200. Two initial current-code responses were HTTP 500 (two-worker Lapinjarvi repetition 2 and four-worker Mikkeli worker 1). Original error pages/records remain; initial exception capture was absent, so their causes are unresolved. A repeat with exception capture returned HTTP 200 throughout at two/four/eight workers. Do not label these unknown HTTP 500s as reproduced production timeouts, discard them or claim all concurrency tests passed.

The two-worker case has three runs; four/eight-worker groups each have one run. Safe bounded local concurrency is not the measured production burst of 367 requests/minute. Uncontrolled OS cache/local activity affects timing. Peak worker PHP memory was at most 88.5 MiB on a 32 GiB machine.

## Confirmed repeated work

- Every simultaneous worker built the entire 378-contract shared metric set: 2/2, 4/4 and 8/8. Cold read/get→build→write does not combine simultaneous calculations. Existing lifecycle write locks protect publication, not calculation.
- `LocalContractsService::processContracts()` unconditionally calls `ContractListingPipeline::enrichAndSortAnnual(..., loadLegacyCardPrices: true)`.
- The pipeline tries `applyCachedMetrics()` only when that argument is false. Consequently canonical local cards bypass the existing verified metric set and calculate separately even though canonical mode never needs legacy price components.
- Each cold city page loaded the donor universe twice: once for shared pricing, once for local pricing. `CanonicalContractPricingService` is transient; separate instances have separate premium-loader memos. The local pass repeated donor/episode/timeline preparation.
- Shared build: 1,869 timelines. Local pass added 818 for Lapinjarvi and 711 for Mikkeli. Current local service medians were 1.109 / 1.272 s. Stage times are inclusive, not additive.
- With shared metrics already warm, a new request still recalculated local pricing: Lapinjarvi 1.164 s / 43 queries; Mikkeli 0.966 s / 42 queries. City prepared-page caching is explicitly disabled.
- Scoped EEX memoization already works. Local preparation after a cold shared build performed zero additional futures SQL queries. A warm-shared request starts a new provider and its local preparation needs 13 futures queries. Do not blame duplicate local cold-pass futures SQL that was not observed.
- Lapinjarvi rendered all 58 listed local cards (59 input candidates), with about 1.15 MB of HTML. Mikkeli rendered all eight local cards, with about 0.58 MB of HTML. Alpine hides cards after ten only in the browser; it does not defer PHP rendering.

## Next change to consider — not implemented or authorized as a release

Reuse the existing shared `ContractMetricSet` path for canonical local enrichment, while preserving legacy latest-component loading, postcode/municipality/consumption eligibility, distance facts, exclusions and source/generation guards. Verify local membership, financial values, provenance and cold/warm/concurrent HTTP results. Reusing retained shared metrics can change the calculation-time boundary relative to the current direct path; this must be an explicit reviewed consistency decision, not hidden inside a performance claim.

If needed, design combining simultaneous cold shared builds separately with existing invalidation/publication safety intact. Do not remove safety invalidation or raise PHP's execution timeout. The measured crawler burst and repeated calculation strongly support concurrent expensive work, but the 30-second production timeout is not reproduced or linked to individual Railway request IDs.

## Reproducible artifacts

- `/tmp/voltikka-city-http-bench/findings.md`
- `/tmp/voltikka-city-http-bench/summary.md`
- `/tmp/voltikka-city-http-bench/results.json`
- `/tmp/voltikka-city-http-bench/request.php`
- `/tmp/voltikka-city-http-bench/run.py`
- `/tmp/voltikka-city-http-bench/instrument.php`
- `/tmp/voltikka-city-http-bench/provenance.json`
- Per-request JSON/HTML and follow-up exception-capture records are in the same directory.

Reproduction: `python3 /tmp/voltikka-city-http-bench/run.py /tmp/voltikka-city-http-new-empty-directory`. The destination must be empty. The script reads the current local SQLite file; it does not access production.
