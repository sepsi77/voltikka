# July 23 seam investigation — 2026-09-21

## Result: evidence investigation complete; correction not selected

Code reviewed at `e0c3161`. A fresh production snapshot confirms the principal cause:
**the start of immutable source coverage closes the retrospective historical path,
while most valid source interpretations complete after July 23 Helsinki midnight.**
Some sources have no valid interpretation at all. Thus the seam is not solely a
delayed-output defect. A strict completion-date rule and a gap-free reconstruction
cannot both be promised from this evidence.

No application code, chronology rule, active method, or stored annual method was
changed. No production write, deployment, commit, push, or process stop occurred.
The approved wrapper replaced the stale local snapshot. All subsequent diagnostics
used an isolated copy with SQLite `query_only=ON` or Python SQLite `mode=ro`.

## Process check and snapshot provenance

The initial block was too broad: an unrelated PHP server is not automatically a
Voltikka database user. The follow-up checked command, working directory, parent,
open files, and application paths, without printing secrets:

- PIDs 47093–47097 run `php -S 127.0.0.1:8000
  ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`.
  Their cwd and parent bash PID 47092 cwd are
  `/Users/seppo/Documents/cb-protos/writing-evaluator/public`.
- Their bounded open-file listing had no Voltikka path or database file. That
  project's index, router, bootstrap, database config, `.env`, and SQLite file
  resolve inside that other project. A boolean-only content check found no
  Voltikka reference; no cached config exists there. No secret values were output.
- Process checks found no Voltikka PHP/Laravel server, queue, scheduler, MySQL,
  SQLite CLI, or database GUI. Processes with Voltikka cwd were shells, Pi,
  browser helpers, editor helpers, and Ruff, not application/database workers.
  The active SQLite main file and sidecars had no open user.
- No relevant process was started during sync. The unchanged wrapper retained all
  of its repeated file-use checks. The unrelated server processes stayed running.

`scripts/sync-production-database.sh --yes` completed successfully. Its log is
`/tmp/annual-continuity-sync.log`; prior local backup:
`/tmp/voltikka-database-20260921-132725-47852.sqlite`. The wrapper reported all
checks passed before replacement. Production observation rows were copied, not
reconstructed: 2,787 observations, 2,787 sources, 4,150 source interpretations,
6,534 historical episodes and 6,534 historical interpretations. It copied 417,721
annual rows and 29,604 daily statistics. Annual methods: v1 196,256; v2 221,465.

`sqlite3 laravel/database/database.sqlite ".backup '/tmp/annual-continuity-diagnostic.sqlite'"`
created the diagnostic copy. A full-file SHA-256 before and after diagnostics matched.
Thus its stored v1/v2, observations, and all other data stayed byte-identical.
The original production snapshot was not used for diagnostic calculation writes.

## Stored evidence: three distinct dimensions

[July 22–24 aggregates](july-22-24-aggregates.csv) contains all 27 segment/date/
consumption combinations. Counts and medians were independently reconstructed from
stored v2 annual rows. All three basis maps matched the aggregates exactly.
At 5,000 kWh:

| Date | Segment | Count | Median EUR | Canonical / relational count | Dominant estimate method (count) |
|---|---|---:|---:|---:|---|
| Jul 22 | open_ended | 61 | 638.3735 | 58 / 3 | hold_current_supplier_price (35) |
| Jul 23 | open_ended | 62 | 625.3125 | 8 / 54 | none (57) |
| Jul 24 | open_ended | 58 | 653.3694 | 58 / 0 | hold_current_supplier_price (38) |
| Jul 22 | hybrid | 38 | 506.0102 | 36 / 2 | hybrid_base_only (33) |
| Jul 23 | hybrid | 41 | 509.2400 | 6 / 35 | none (36) |
| Jul 24 | hybrid | 39 | 510.5000 | 39 / 0 | hybrid_base_only (37) |
| Jul 22 | quarterly | 13 | 581.8204 | 13 / 0 | recurring_forward_curve_shift (13) |
| Jul 23 | quarterly | 12 | 533.1337 | 4 / 8 | none (8) |
| Jul 24 | quarterly | 13 | 604.1108 | 13 / 0 | recurring_forward_curve_shift (13) |

`canonical_outcome` is a **calculation basis**. The OpenEnded conservative hold is
an **estimate basis**, not an estimate method. Its relational method is `none`.
The old spec conflates these labels; its 638 → 653 comparison also skips the actual
July 23 median, 625.3125. The table above supersedes that shorthand, not the retained
raw evidence. All three series have a real dominant-method transition to `none`
and back under the stored calculation.

There are also contributor changes, not just price changes. At 5,000 kWh, adjacent
matched/lost/new identities are respectively:

| Dates | open_ended | hybrid | quarterly |
|---|---|---|---|
| Jul 22 → 23 | 60 / 1 / 2 | 33 / 5 / 8 | 12 / 1 / 0 |
| Jul 23 → 24 | 57 / 5 / 1 | 39 / 2 / 0 | 12 / 0 / 1 |

## Exact omission accounting

The real resolver read 1,299 date-contract identities across the three dates.
Its omission flags matched stored v2 contributor provenance for all 27 groups.
At July 23, the three segments contain 70 / 45 / 13 evidence identities, of which
60 / 39 / 8 lack canonical data. [July 23 omissions](july-23-omissions.csv) records
all **107** identities, consumption availability, exact source/observation IDs,
first and last observation UTC times, timely failed outputs, first later valid
source output, July 22 dedicated episode bounds, and July 24 selected source/output.
Empty analysis-observation IDs in that file are actual legacy NULL values.

At 5,000 kWh, every one of the **97 stored relational contributors** is accounted for:

| Segment | No interpretation by cutoff | Timely invalid interpretation | Later valid same-source output | No valid same-source output at snapshot |
|---|---:|---:|---:|---:|
| open_ended | 47 | 7 | 51 | 3 |
| hybrid | 32 | 3 | 29 | 6 |
| quarterly | 8 | 0 | 8 | 0 |

The first two columns partition the 97 contributors; the last two independently
partition them. All ten timely invalid rows are `failed` with nonempty validation
errors, not parser failures. No covering-source ambiguity or completion tie causes
these 97 omissions. The first later valid completion ranges (UTC) are:

- OpenEnded: July 23 22:39:59 through July 24 15:53:59.
- Hybrid: July 23 22:23:07 through July 24 06:27:09.
- Quarterly: July 23 23:25:18 through July 24 04:21:05.

All 106 covering observations among the 107 omitted identities start at
**2026-07-23 19:15:12 UTC = July 23 22:15:12 Helsinki**. This is also the minimum
observation timestamp in the entire copied table. Current-builder dedicated episodes
end no later than July 22. July 23 Helsinki ends at **20:59:59 UTC** for the resolver's
second-precision SQL bound. A UTC July 23 completion at 22:51 is already July 24
in Helsinki; it is not timely evidence for July 23.

The one omitted identity without source coverage is
`4jxjsu-vihrea-alyenergia-oy-vakaa-valinta`. Dedicated episode 3506 ends July 22;
no dedicated episode covers July 23. It contributes only at 2,000 kWh on July 23,
not to the 97 contributors above, and has no July 24 evidence identity.

### Representative complete chains (timestamps UTC unless stated)

1. **Oomi Vakaa**, `2wzfba-oomi-oy-oomi-vakaa`:
   - July 22: dedicated episode/interpretation 3422, bounds February 28–July 22;
     interpretation completed **August 7 10:25:11**. Retrospective output accepted.
   - July 23: price snapshot 129245; source 352, observation 23 starts July 23
     19:15:12 and extends through September 21 03:00:30. No timely output.
     First valid interpretation **120 completed July 23 22:51:32** (July 24
     01:51:32 Helsinki). Stored 5,000-kWh v2 row 484255 holds relational 754.52 EUR,
     `none` / `relational_open_ended_conservative_hold_flat`.
   - July 24: the same observation/source, selected latest valid interpretation
     **1029 completed July 24 14:01:32**. Canonical data accepted.
2. **Keuruu Yösähkö**, `68g91h-keuruun-sahko-oy-yosahko`:
   - Episode/interpretation 3616 covers February 13–July 22; completed August 7
     11:53:52. July 23 source 16 / observation 75 starts at the launch timestamp.
   - Interpretation **16 failed July 23 19:31:08**, with errors. First valid
     **122 completed July 23 22:52:29**, after Helsinki midnight. July 24 selects
     **1052 completed 14:07:42**, on the same source/observation.
3. **Vaasa Perussähkö**, `1eh6re-vaasan-sahko-myynti-oy-perussahko-yleissahko`:
   - Episode/interpretation 3323 covers July 1–22; completed August 7 09:41:22.
   - Source 88 / observation 11 starts at launch. First valid **153 completed
     July 23 23:25:18** (July 24 02:25:18 Helsinki). July 24 selects **1024
     completed 14:00:21**. The same seller episode crosses the calculation seam.
4. **Voima Joustava 12 kk**, `jt9vxd-imatran-seudun-sahko-oy-voima-joustava-12-kk`:
   - Source 1 / observation 366 starts at launch. **1 failed July 23 19:25:01**.
     First valid **359 completed July 24 04:06:16**; July 24 selects 1206.
     This exact contract ID has no July 22 evidence identity; do not fabricate one.

The nine 5,000-kWh contributors without valid output for the July 23 source are
three Lammaisten contracts (sources **65, 245, 177**) and six Hybrid contracts
(sources **180, 38, 276, 303, 434, 253**). Source 38 has only failed interpretation
38, completed July 23 19:36:24; the other eight have no interpretation row. Each
observation ends at the launch timestamp. July 24 uses different sources
**436, 450, 443, 444, 435, 452, 456, 462, 451**, respectively. A later interpretation
of a *different* source cannot repair their July 23 evidence. Exact IDs are in the CSV.

## Resolver conditions confirmed in code

Paths are relative to `laravel/`.

`app/Services/ContractStatistics/AsOfAnnualCostEvidenceResolver.php`:

- Lines 115–148 load overlapping observations and interpretations with non-null
  completion no later than the latest requested day's UTC end.
- Lines 331–364 require first observation <= target end and last observation >=
  target start. Only zero covering observations opens the dedicated path. More
  than one distinct covering source fails closed. Invalid/missing interpretations
  never reopen the dedicated path. There is no hardcoded July 23 resolver branch.
- Lines 371–410 require completion by the individual day's end, contract ownership,
  status `published` or `superseded`, empty validation errors, and a parser pass
  with `withEnergyRules: false`. A non-null analysis observation ID must belong to
  the covering set. Legacy null IDs are accepted.
- Lines 412–425 select the latest valid completion; a tie at its second is ambiguous.
- Lines 434–547 require a current-builder dedicated episode, exact fingerprints,
  target snapshot/component identities and economic digest, one current validated
  analysis, and parser-valid output. Completion must exist but may be later than
  the target. Its later timestamp is explicitly recorded as retrospective evidence.

`AsOfAnnualCostCalculator.php:310–360` falls back to exact-date relational
components when canonical data is absent. Missing/unidentified energy is unavailable.
OpenEnded FixedPrice gets the conservative hold basis. It does not infer a canonical
adjustment mechanism from that relational price. The dedicated reconstruction cutoff
and all other pricing behavior are unchanged.

## Manager decision: minimal correction and chronology conflict

**Seller evidence date is not interpretation completion date.** The source payload
existed July 23; most usable canonical output did not exist until July 24. Accepting
the first valid later output changes the explicit completion-date no-look-ahead rule.
The existing pre-cutoff retrospective route already differs from that rule: its
August interpretations can price July. The new history policy must reconcile this
exception explicitly rather than silently extending it.

Minimal choices for the next task:

1. **Retain strict completion chronology:** keep July 23 relational output and record
   a genuine canonical-knowledge gap. No evidence-resolver code correction is needed
   for these rejected rows under that rule. A gap-free acceptance criterion must change.
2. **Approve retrospective source interpretation as a separate reconstruction rule:**
   in a new method only, consider a tightly bounded fallback to the first valid output
   derived from the exact July 23 source when no timely valid output exists. Keep
   ownership, parser, error, source ambiguity, target economics, and dated source
   checks; retain both seller and completion dates with explicit retrospective flags.
   Do not change the global completion filter or the stored v2 results. Such a rule
   can address the 88 later-valid contributors, but cannot promise the same estimates
   or fix the nine absent/failed-source contributors. Those need a separate exact-source
   historical interpretation decision or must retain relational evidence.

All first-valid later outputs in the omission set have legacy NULL analysis-observation
IDs. Source identity is proven; explicit episode binding is not. Four first valid
completions are after their observation's last-observed timestamp (sources 120, 237,
172, 204). Any proposed retrospective rule must handle this explicitly; do not claim
that same-source output automatically proves same-episode completion. Never borrow
July 24's different source, current canonical columns, or prices. Extending the
dedicated cutoff alone does not help because covering observations still close that path.

At investigation time no option was selected and no counterfactual calculation was fact.
The user subsequently approved exact-source retrospective reconstruction under v3. The first
code unit and an isolated July 23 preview are recorded in [v3-first-unit.md](v3-first-unit.md).
The chronology blocker is resolved. Full v3 premium/anchor integration remains pending;
missing/failed exact-source outputs stay unresolved and earlier versions are preserved.

## Reproduction and checks

Artifacts retained locally:

- `/tmp/annual-continuity-resolve.php` — booted resolver, explicit target-path/driver
  assertion, `PRAGMA query_only=ON`; parses every interpretation of all selected
  source IDs, including completions excluded by the normal date filter.
- `/tmp/annual-continuity-resolved.json`, `-interpretations.json`, `-cases.json`,
  `-analysis.txt`, and `/tmp/annual-continuity-analyze.py` — raw bounded diagnostic
  results and grouping. Raw source prose and interpretation output are not published.
- `/tmp/annual-continuity-copy-before.sha256` — full-copy preservation check.

Resolver invocation (after making the isolated copy):

```sh
cd laravel
APP_ENV=local APP_CONFIG_CACHE=/tmp/annual-continuity-no-config.php \
DB_CONNECTION=sqlite DB_DATABASE=/tmp/annual-continuity-diagnostic.sqlite DB_URL= \
CACHE_STORE=array LOG_CHANNEL=single \
php -d error_reporting=22527 /tmp/annual-continuity-resolve.php
```

Its central read is:

```php
$resolved = app(\App\Services\ContractStatistics\AsOfAnnualCostEvidenceResolver::class)
    ->resolveForDates(['2026-07-22', '2026-07-23', '2026-07-24']);
```

For durable reproduction, query stored annual rows by `method_version =
'annual_cost_as_of_v2'`, the three `DATE(snapshot_date)` values, the three segment
keys, and each consumption. Group each basis dimension separately; compare counts
and the ordinary median with `contract_price_daily_statistics` for `metric_key =
'annual_cost'`. Stored `provenance.flags` contains the omission reasons. The CSV
identifies all sources and historical episodes needed for bounded follow-up reads.
Read later interpretations too, not only those loaded by the resolver:

```sql
SELECT o.contract_id, o.id AS observation_id, o.source_snapshot_id,
       o.first_observed_at, o.last_observed_at,
       i.id AS interpretation_id, i.analysis_source_observation_id,
       i.status, i.completed_at, i.validation_errors
FROM contract_source_observations o
LEFT JOIN contract_interpretations i ON i.source_snapshot_id = o.source_snapshot_id
WHERE o.first_observed_at <= '2026-07-23 20:59:59'
  AND o.last_observed_at >= '2026-07-22 21:00:00'
ORDER BY o.contract_id, o.id, i.completed_at, i.id;
```

Apply all resolver ownership/status/error/parser/binding conditions before selecting
the first later valid row. A simple minimum completion is insufficient. Historical
rows join by the CSV's episode/interpretation IDs; their completion is not seller time.

Verification:

- Approved sync: exit 0; row-count, foreign-key, integrity, backup and replacement
  checks passed. No wrapper guard was removed.
- Resolver: 1,299 date-contract identities; stored vs current omission flags match.
- Independent stored-row checks: all 27 counts, medians, and all three basis maps pass.
- Copy SHA-256: unchanged after all read-only diagnostics.
- `cd laravel && php artisan test --filter='AsOfHistoricalInterpretationIntegrationTest|AsOfAnnualCostCalculatorTest'`:
  41 tests passed, 357 assertions, using forced SQLite `:memory:`; repeated after
  snapshot investigation with the same result (1.11 seconds).
- Diagnostic corrections: the first path assertion rejected macOS `/private/tmp`
  versus `/tmp` before analysis; it now compares `realpath`. A diagnostic source-ID
  collection initially overwrote associative contract keys across dates; adding
  `values()` preserved every source and the chronology report was regenerated.
  Neither correction changed application code or database data.
- Vendor PDO constant deprecation notices occurred under PHP 8.5; unrelated to the
  result. No dependency or application fix was made.
