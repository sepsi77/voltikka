# Production preflight — 2026-09-16

> Current-status supersession: see [audit-fixes.md](audit-fixes.md) for local repairs,
> intermediate replay, newly found anchor defects and remaining release gates. The original
> failed results below are preserved; they are not a description of the current repair state.

## Decision: complete, release gate FAILED

The user-approved read-only preflight is complete. **The staged default-V4 release is blocked**, not only V5 activation. A confirmed reader defect rejects valid immutable price anchors and removes donor evidence. Local repair, regression tests, repeat comparison and performance diagnosis are pending. The earlier local acceptance (2754 tests / 17909 assertions) remains valid historical evidence, not current release approval.

This report records evidence verified by the manager. This documentation unit did not repeat production access or engine runs. No source quotes or private export data are included.

## Production and safety boundary

- Explicit target: project `6d8cae01-1006-409f-8108-1d51f1abc676`, environment `9245cef8-41d0-486e-862f-193726511dba`, app `700d0624-fa96-4266-876c-e37640d220ea`, database `beb2ba12-4a7b-416b-b4b1-596434dc3215`.
- App deployment `13bfd4fa-c06f-4a27-b7a7-7e935823d903`: SUCCESS, commit `917de212fdc4ef4862903268c3d33ba5cd886e73`. MySQL: SUCCESS. Local HEAD `9770811` differs only by an AGENTS documentation change. We made no commit or push.
- Access used MCP read inspection, whitelisted cached-config PHP reads through SSH without Laravel boot, and pure PDO queries/export in a consistent READ ONLY transaction. The first SSH quoting attempt failed at shell parse; the corrected read-only call worked.
- No live application/LLM calls, production writes, migrations, cache warming, queue changes, activation, deployment, or active local SQLite replacement occurred.
- Safe aggregates: `/tmp/voltikka-energy-production-config.json` and `/tmp/voltikka-energy-production-summary.json`. Canonical/reset enabled; beta 1; seasonal history 4 years/minimum 2; maximum curve age 14; band 0..60; VAT 1.255/FI; annual v2; producer V4/v19/v17; cache schema 17. Queue empty; five historical failed jobs, latest August 8.
- 375 active contracts; 270 Household/Both/null; 245 national household. Publications: 374 V4 and one V3/v17/v14, no V5. All 375 source observations last seen September 16 at 03:00:27. No missing publication, ownership or snapshot mismatch. Latest FI Base: September 15; Spot hours cover September 16.

## Fresh export and isolated replay

Private artifacts are in `/tmp/voltikka-energy-preflight-20260916-a`: manifest, SQLite snapshot, `baseline.json`, `candidate.json`, `comparison.json`. Export took 35.68 seconds: 269442 source rows read, 165500319 serialized bytes. Snapshot: 132526080 bytes; SHA-256 `2e40935f04e2ade5d79b20775321371ad5db11b93a511b344397bea0e7f05524`. Manifest complete, integrity OK, scope and file hashes verified. The original SQLite SHA-256 still matches after both runs.

Export counts: 2193 contracts (active plus ancestors), 375 active, 1789 observations/snapshots, 2805 interpretations, 165790 price components, 54812 price snapshots, 2279 FI Base futures, 1743 averages, 28341 hours and 2617 energy-statistics rows. Required graph targets, ownership and primary keys are valid; no cycles.

**Diagnostic correction:** the export summary's `950 current_publication_source_mismatches` label is misleading. All 950 cases have legacy NULL `analysis_source_observation_id` (375 active, 575 inactive). There are zero snapshot mismatches and zero differing non-NULL analysis pointers. The sealed export artifact is unchanged.

The baseline archive at `/tmp/voltikka-energy-baseline-f36f_nni/laravel` uses the exact production SHA. Four reflection hashes match Git objects; the namespace guard checked every loaded App class. Composer manifests match. Separate baseline/candidate processes used the same read-only SQLite input and verified flags, a network-denying sandbox, forced array cache, no logging/Sentry/dispatch and no application kernel. These were engine computations, not live cache or listing-rank checks.

## Price impact and confirmed cause

All 375 contracts were evaluated at 5000 kWh cold and warm, then 2000 and 18000 kWh. Cache schema changes from 17 to 19; cold/warm values match. At each consumption: 48 displayed-total states change (47 paired numeric changes and one new exclusion); listable 368 → 367, excluded 7 → 8. Of existing supplier projections, 46 change to `hold_current_supplier_price`: 26 previously forward-curve and 20 seasonal.

Median absolute delta across paired totals is zero. Maximum absolute deltas: €325.238479 at 5000 kWh, €130.095392 at 2000, €1170.858525 at 18000. Examples at 5000 kWh:

| Contract (ID prefix) | Baseline € | Candidate € | Delta € |
| --- | ---: | ---: | ---: |
| Vihreä Älyenergia Vakaa Valinta (`hbdxeg…`) | 1079.618480 | 754.380000 | −325.238480 |
| Äänekosken household Kausisähkö (`v7juwr…`) | 464.102843 | 616.379167 | +152.276324 |
| Nurmijärven Aikasähkö (`onnuwd…`) | 1036.392106 | 1340.025000 | +303.632894 |

These are lost-anchor effects, not evidence of better forecasts. `CurrentPriceEpisodeResolver::sourceCandidate` (about line 298) requires `json_decode((string) $row->validation_errors, true) === []`. All 375 current published rows store SQL NULL. The normal success writer in `AnalyzeContractSourceSnapshot` (line 124) explicitly stores `$errors === [] ? null : $errors`. The reader thus rejects valid immutable anchors and donor evidence and incorrectly holds prices flat. The candidate audit's `CurrentSourcePromotionEvidence` accepts all 375 rows as valid legacy source proof; `energy_rules_valid=false` and `energy_rules_required=false` for all 375. The separate episode resolver rejects them solely at its NULL-errors guard. The manager confirmed the success writer at line 124: this is not a source-data or missing-V5 problem. **No repair was made in this unit.** A local repair must accept the valid success representation while malformed or nonempty errors still fail closed.

Separate intended changes: Hehku JATKUVA (`xam9ev…`) is newly excluded for `insufficient_promotion_terms`. Vaasan Kiinteä 6 kk (yösähkö, `aqjvba…`) is now term-annualized: €876.50 → €868.41828. Its top-level annual-equivalent saving changes €5.90 → €11.80, but the **real six-month benefit remains €5.90** in `contract_term`. The helper's `changed_benefit_count=2` counts transport fields, not two changed real offers or doubled real customer savings.

## Performance limits and next gates

| Local SQLite, 5000 kWh | Baseline | Candidate |
| --- | --- | --- |
| Cold elapsed / SQL count | 1.6086 s / 23 | 53.0178 s / 26 |
| Cold SQL time / peak memory | 7.71 ms / 30.5 MiB | 50.693 s / 48.5 MiB |
| Warm elapsed / SQL count | 1.4908 s / 0 | 1.9153 s / 1 (~2.96 ms SQL) |

At 2000/18000 kWh, warm candidate runs took about 1.94 s versus 1.49 s baseline. **These are not production latency measurements.** Exported nonunique indexes and no ANALYZE may affect the SQLite query plan. Query profiling is pending before attributing the cost to application code or choosing an optimization.

Required next work: local NULL-success repair and negative regressions; repeat the isolated three-consumption comparison; query profiling and performance review; renewed manager release acceptance. Remotion lint, TypeScript and build passed previously; visual rendering is still pending. No V5 records exist, so prospective V5 producer and price coverage remain unmeasured. A V5 pilot, activation and reanalysis need separate approval. Deployment, production mutation, commit and push remain unapproved.
