# Local SQL diagnosis — 2026-09-16

## Result and limits

The large measured SQL cost is a SQLite export optimizer artifact. The same parameterized
historical-tariff query runs twice. Each execution costs about 25 seconds without statistics.
`ANALYZE` on a new copy reduces an identical query to about 125 ms without any row change.
No application change is justified by this measurement. This is not a production MySQL latency
measurement. No financial acceptance follows from these runs. The manager must repeat the final
comparison after all application units finish, including the NULL-reader and V5 metadata units.

Only local scripts and documentation changed. No network, Railway, real LLM, migration, commit,
push, active-local-database write, original snapshot write, or original result replacement occurred.
All model runs started in fresh private processes with the network-denying macOS sandbox:

```sh
sandbox-exec -p '(version 1)(allow default)(deny network*)' php <runner> ...
```

The model uses PDO READONLY, the zero-row read-only probe, query_only, private array cache and
no kernel boot. The diagnostic SQL connections also use PDO READONLY and query_only. Variant
creation writes only new throwaway files. No variant has a forged completed-export manifest.
`report.php` still rejects differing database hashes. The smoke regression proves this rejection.

## Private evidence and code identity

All new evidence is in `/tmp/voltikka-query-profile-20260916/` (0700):

- `candidate-profile.json`: first diagnostic pass, finished 11:59:05 UTC. This pass used the live
  working tree; do not use it for financial acceptance. Runner hash
  `a3c217201f18614e18da2351c82f3f2d1d618efbbfc0964eabd381b260d1cf02`.
- `candidate-profile-pinned.json`: frozen app/config pass, finished 12:01:58 UTC. All 82 loaded
  application/config hashes were checked against the private `pinned/laravel` tree after the run.
  Full hashes are in `loaded_code_sha256`. The SHA-256 of that sorted JSON map, with compact
  Python separators, is `90a42f6a21ece934ff48f361d988689d5ed6348a5a860bdd7688214ffd3c5abe`.
- `baseline-profile.json`: production archive `917de212fdc4ef4862903268c3d33ba5cd886e73`, finished
  12:00:44 UTC. It records 65 loaded file hashes. Baseline cold: 23 SQL, 10.40 ms SQL,
  1648.70 ms wall time. Full profile coverage: 26 queries including load/audit.
- Both later passes use runner hash
  `ad7c103a646dff0b37fdda4c5f0347c34238e9dc30d039762d67bda1daeb1da2`.
- Frozen resolver `app/Services/CanonicalPricing/SupplierAdjusted/CurrentPriceEpisodeResolver.php`:
  `03fbde47253cc3dd012f3d5af296a6e050407d78dcafb00d8a75407eebea310d`.
  These hashes, not an assumption about agent completion, define this measurement.
- `run.php` and `run-pinned.php`: private replay callers. Bindings exist only in process memory.
  These callers do not store SQL result values. They retain timings, plans, row counts and hashes.
- `row-proof.json`: business-table row counts/digests and variant file hashes.
- `analyze.sqlite`, `safe_unique.sqlite`, `safe_unique_analyze.sqlite`: diagnostic variants only.
- `pinned-full/laravel`, `frozen-files-before.json`, `candidate-profile-clean.json`: additional
  frozen app/config/resources run without replay callbacks. Final measurements are below.

The original snapshot still hashes to
`2e40935f04e2ade5d79b20775321371ad5db11b93a511b344397bea0e7f05524`.
Original manifest: `fc1758b64394ef116d329dc71a7676a9d4a0403872f6c09b41dc6d1a7b70dce0`.
Original baseline/candidate/comparison remain at their original paths; no writes targeted them.

## Exact query source and shape

`CurrentPriceEpisodeResolver::historicalTariffs()` reads the complete multi-rate history for
requested replacement lineages. In the frozen run, query sequence 8 and sequence 26 have the same
shape SHA-256 `bcb3916c7c4a530d170628f5ceccae14ca493c7fd9922a7497492f53505db1a7`.
The full exact parameterized string is retained in each private result. Readable shape:

```sql
SELECT components.electricity_contract_id, components.price_date,
       components.price_component_type, components.price, components.payment_unit,
       components.has_discount, components.discount_value,
       components.discount_is_percentage, components.discount_type,
       components.discount_discount_n_first_kwh,
       components.discount_discount_n_first_months,
       components.discount_discount_until_date,
       identity.metering, identity.pricing_model, identity.contract_type
FROM price_components AS components
LEFT JOIN contract_price_snapshots AS identity
  ON identity.contract_id = components.electricity_contract_id
 AND identity.snapshot_date = components.price_date
 AND identity.pricing_basis = ?
WHERE components.price_date <= ?
  AND (
    (components.electricity_contract_id = ? AND components.price_date < ?)
    OR (components.electricity_contract_id = ?)
    -- 32 OR branches total: 26 with a first-observation cutoff, 6 without.
  )
  AND components.price_component_type IN (?, ?, ?, ?, ?, ?)
ORDER BY components.price_date ASC
```

No actual binding, contract identifier, date cutoff, pricing-basis string, or source prose appears
in this note. The SQL above abbreviates only the repeated OR branches; exact strings remain private.

## Plans and timing

Original plan:

```text
SEARCH components USING INDEX idx_price_components_price_components_price_component_type_index (price_component_type=?)
SEARCH identity USING INDEX idx_contract_price_snapshots_contract_price_snapshots_pricing_basis_index (pricing_basis=?) LEFT-JOIN
USE TEMP B-TREE FOR ORDER BY
```

The inner join lookup uses pricing basis alone, rather than the available date/contract index.
This repeats a broad search for each selected component row. Safe integer primary-key uniqueness
alone does not change that choice.

After ANALYZE:

```text
MULTI-INDEX OR
SEARCH components USING INDEX idx_price_components_price_components_latest_calc_idx
  (electricity_contract_id=? AND price_component_type=? AND price_date<?)
-- one search for each of the 32 branches
SEARCH identity USING INDEX idx_contract_price_snapshots_contract_price_snapshots_date_contract_unique
  (snapshot_date=? AND contract_id=?) LEFT-JOIN
USE TEMP B-TREE FOR ORDER BY
```

Frozen-run identical SQL replay, including full row fetch/hash cost:

| Variant | ms | Returned rows |
| --- | ---: | ---: |
| Original sealed file | 25348.10 | 6552 |
| ANALYZE only | 124.85 | 6552 |
| Seven safe integer unique indexes only | 24746.02 | 6552 |
| Safe integer unique indexes + ANALYZE | 123.77 | 6552 |

The seven unique indexes are existing source primary keys on averages, futures, source snapshots,
price snapshots, source observations, interpretations and daily statistics. No text-based or
composite collation-dependent uniqueness was invented. Other indexes stayed unchanged.

The model's own two slow-query times were 24534.97 and 24549.27 ms. Cold: 32 SQL,
49114.34 ms SQL. All 39 load/engine/audit queries were captured. Warm 5000/2000/18000 each had one
query (2.92/3.09/2.99 ms), with about 1.96–2.03 seconds wall time. These phases reuse one engine;
only the first phase is process-cold. This is not a cold OS-cache assertion.

The diagnostic callback adds replay time to cold wall time (103062.39 ms in the frozen pass).
Do not use that wall time as normal engine latency. SQL event time excludes the callback.
The earlier live-tree pass captured 39 queries too, with 49921.57 ms cold SQL; this independently
reproduced the large cost but does not establish a stable financial code version.

### Clean frozen-tree run, no replay callback

The extra process finished at 12:10:32 UTC with the same runner hash `ad7c103a...` recorded above.
All 549 app/config/resources files match their before-run hashes. The SHA-256 of
`frozen-files-before.json` is `a7f6865c20242426710f888d67248a76dd1fa952554c5b245468b19daba781b0`;
`frozen-files-after-proof.json` records the successful check. The private copy retains the earlier
app/config version; it is deliberately not the changing shared working tree or a final release tree.

All 375 active contracts were loaded. Cold: 32 SQL, **48048.17 ms SQL / 51626.19 ms wall**.
The two historical-tariff reads cost **23858.10 and 24157.17 ms**. Warm 5000/2000/18000:
1 SQL each, 2.89/2.84/2.90 ms SQL, 1935.41/1928.49/1958.61 ms wall. This run isolates the normal
cold cost from the diagnostic callback overhead. The three variant file hashes remained unchanged
after all READONLY replays. No financial comparison was generated.

After profiling completed, a small SQL-shape sanitizer correction made comment markers inside
string literals safe too. The synthetic smoke verifies that case. The real profiling queries had
no such literal/comment collision, so their recorded SQL shapes and timings remain valid. The
profile artifacts retain the prior runner hash rather than claiming the later runner was measured.

## Row identity and ordering caveat

All 12 business tables match the original row-by-row digest after each variant is closed.
The digest streams SQLite rows in rowid order as UTF-8 compact JSON, followed by a newline.
Counts include 375 active, 2193 contracts, 165790 components and 54812 price snapshots.
The private proof records each table's full hash. No data rows, ownership pointers, graph anomalies,
or financial facts were changed.

The query returns the same multiset of 6552 rows in all four variants:
`a7b22dc2e0c3dada766b7b982695d62d33f1a734d989a30503e0d19454acff78`.
This digest sorts per-row SHA-256 values and retains duplicates. The ordered stream hashes differ:
ORDER BY price_date does not specify the order within a date. This is expected plan-dependent tie
ordering, not a changed data row. No claim of unchanged financial output is made from this fact.
Final manager replay must still check financial outputs.

## Script changes and future-export restriction

- `compare.php`: bounded private per-query timing, literal-free SQL shape, slow-query EXPLAIN,
  loaded file hashes/time, and a trusted PHP-only diagnostic callback. No bindings are serialized.
- `smoke.php`: timing/count coverage, SQL literal privacy and different-hash rejection regressions.
- `export.php`: faithful source unique indexes; unsupported prefix, expression, partial, missing,
  inconsistent and collation-sensitive unique semantics stop export. Source duplicate non-NULL
  unique groups are recorded and stop export. No deduplication or graph repair occurs.
  ANALYZE runs only on the new private database before sealing. Source metadata stays in manifest.
- Legacy NULL analysis pointers have a separate metadata key. Read-only evaluation of the corrected
  helper on the original snapshot gives zero mismatches and 950 NULL analysis pointers. A NULL
  analysis pointer does not hide a genuine snapshot mismatch.
- `export-smoke.php`: offline safe-unique, rejection, ANALYZE, NULL-pointer and graph tests.
- `comparison-safety.md`, `preflight/export-safety.md`, `preflight/AGENTS.md`: updated safety rules.
  `report.php` did not need a code change; its strict hash guard remains intact.

**Future full export is conservatively blocked by current MySQL unique text collations.** SQLite
BINARY/NOCASE cannot faithfully stand in for them. The exporter now stops instead of silently
weakening or inventing uniqueness. The manager must approve a faithful collation solution or a
clearly labelled partial-index-fidelity export policy before another complete export. This unit
adds no dependency and does not claim such a full export was run. The diagnosis does not need one:
ANALYZE alone proves the planner defect on unchanged rows.

No application SQL change is proposed. If a later read-only MySQL plan shows a similar defect,
review that actual plan first. Do not add an application index/hint or rewrite the 32-branch query
on the basis of this SQLite-only artifact.

## Final script identity

After the sanitizer regression and final index-metadata guard, the delivered script hashes are:

- compare.php: `9ac46e7087c0b874348dbc0b1ac8fb92bbe0e1e2469b1ca2a3f67408bd92d941`
- export.php: `88c4fd799c866913e61793388b7a763618a2fffbb8793f385a1190a264667e7d`
- smoke.php: `3225529ece8495800781114d857d8244a6d5979b93f5fe02866ea9fbe0044194`
- export-smoke.php: `ed2514bf2c90979aec9f527f7918e79ed893f93694204d1b2bd7c2a88de543a1`

These final script revisions passed the offline checks below. The measured run hashes remain
separate and explicit. Shared task status, root context and application/service files were not edited.

## Verification

- Network-denied `php preflight/smoke.php`: PASS, including all old guards and new query coverage,
  literal privacy and variant rejection. No source label appeared in output.
- Network-denied `php preflight/export-smoke.php`: PASS; safe uniqueness, eight unsupported cases,
  statistics with row equality, NULL/mismatch separation and graph anomalies.
- `php -l` on compare.php, smoke.php, export.php, export-smoke.php: PASS.
- `laravel/vendor/bin/pint` on those four files: PASS after exporter formatting.
- `git diff --check`: PASS. No application tests or JS build were needed for these script changes.
- Read-only original-pointer helper: zero mismatches, 950 NULL pointers. The first one-line command
  emitted PHP 8.5 deprecated PDO-constant warnings; the corrected Pdo\\Sqlite constants run was clean.
- Private SQL profiling and digest checks: completed as described above. No full pricing comparison
  was produced or accepted in this unit.

## Manager decision: restore original lookup indexes

This decision supersedes the future-export uniqueness restriction above, not its historical
measurements or artifact hashes. ANALYZE alone reduced the identical query from 25348.10 ms to
124.85 ms; uniqueness alone took 24746.02 ms. Optional unique-index preservation is unnecessary
and rejects ordinary MySQL unique text collations. The exporter therefore keeps the original
successful strategy: full-column NONUNIQUE SQLite lookup indexes for every source index. This
is not a new relaxation of data validation or a claim of MySQL constraint equivalence.

Original source index metadata, explicit primary-key duplicate reporting, graph/data checks,
bounded read-only source workflow, NULL-pointer diagnostic separation and all other guards remain.
Invalid index shapes still fail. Required metadata fields match the actual schema queries; optional
expression/partial metadata is not required. Collated text primary/unique keys and decimal unique
keys are supported. ANALYZE remains limited to the new private SQLite file before sealing.

Local cleanup verification: network-denied `export-smoke.php` passed (nonunique indexes, supported
text/decimal keys, explicit primary-key duplicates, twelve invalid index cases, ANALYZE row equality,
NULL/mismatch separation and graph anomalies). `php -l` passed for export.php and export-smoke.php;
`laravel/vendor/bin/pint --test` passed for both; `git diff --check` passed. No production export,
network request, application change, active SQLite access, commit or push occurred. No original
snapshot, diagnostic variant, prior result or measurement was changed. The script hashes above
identify the earlier revision, not this cleanup. Real financial acceptance remains separate.
