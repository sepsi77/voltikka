# Repaired v3 full-history validation — 2026-09-21

## Acceptance

**The historical continuity check passes with documented evidence limits.** All 242 evidence
dates, January 21–September 20, pass at 2,000/5,000/18,000 kWh. There is no February 12 evidence
date. The July processing gap is gone in every requested series. Across 14,978 continuing
exact-source contract identities, zero selected interpretation IDs change. Full target validation
remains in use; no guard was removed to obtain continuity.

All 11 remaining post-May-1 dominant-method transitions have dated reference or membership
causes, listed below. None is an unexplained processing-date transition. September 12 and 17
are continuous inside v3 at all three consumptions. On September 20, all nine requested
segment/consumption dominant methods also match the stored current-produced v2 endpoint.
This is dominance parity, not identical amounts or a new current-v3 database replay.

The coverage and amount changes are quantified and classified. They are **not proof of better
forecasts**. In particular, 703 lost pair-days are explicit legacy temporal-proof exclusions for
seven contracts, not proof that seller terms did not exist. The current bounded validator cannot
recover them safely. Broader legacy date proof is a concrete possible follow-up, not a reason to
weaken the current guard or fabricate prices. V5 and dated replacement trust remain unsupported.

**This report gives no production approval.** A verified production backup and separate apply,
deployment and active-method approvals remain necessary. Same-evidence current/calculator tests
pass, but equality between unequal current and historical evidence stores is not claimed.

## Execution, pause and source equivalence

The original `v3-preview.md` and its 16 manifest-listed CSV artifacts remain unchanged. The
bounded `v3-continuity-repair.md` also remains. This report uses distinct `v3-repaired-preview-*`
artifacts and `/tmp/annual-v3-repaired-preview/` raw output.

Before the first date, application/config PHP hashes, source/copy file hashes and protected
logical hashes were recorded. A fresh SQLite backup of the existing local production snapshot
passed `PRAGMA integrity_check`. No production sync or other production operation ran.

The executor initially chose eight workers without an approved CPU budget. The user reported
excessive CPU use. The manager stopped and later killed the owned process trees. This was an
operational error by the executor. The 56 completed dates through March 18 were preserved.
No stopped process or the old `batch.py` was restarted. The user then approved exactly one
low-priority worker. Tests completed first; no preview or apply PHP ran concurrently.

The new `resume.py` is a simple sequential loop. Each of its 186 PHP children uses
`nice -n 15`, a 512 MiB limit, and a 600-second date timeout. It starts no parallel pool or queued
apply. It preserves completed JSON and moves interrupted logs aside before retry.

Initial/resume source manifests differ only in `ContractPriceStatisticsService.php` and
`CurrentCanonicalAnnualCostResultFactory.php`, the independently completed current-writer
changes. Config and historical sources are unchanged. Read call paths show that historical
pricing does not call these changed current entry points. An initial diagnostic incorrectly
treated loaded classes as invoked methods: Laravel loads them during command registration.
That March 19 diagnostic stopped after calculation; its JSON/log/runner remain in
`failed-loaded-file-guard/`. The corrected runner binds fail-on-call subclasses for those two
entry points before bootstrap. All 186 dates complete without invoking them. No pricing
formula, resolver, adapter or validation rule changed. The incomplete March 19 diagnostic alone
was retried; the 56 complete dates were not recalculated.

Every resumed process has source-manifest checks before and after. All match the resume manifest
SHA-256 `5ae66e90ef012dc16f0f95b607fab96ef48b7a8122d56599fde7a1152ae24bf4`.
The final complete application/config file set also matches. The two reviewed current files
alone are excluded from initial historical-source equivalence; they are not excluded from
resume/final equivalence. The new current producer only chooses the output version; it does
not change canonical prices. See `v3-current-writer.md`.

### Effective isolation

The reviewed runner invokes the existing `contracts:rebuild-annual-cost-statistics` command with
explicit target `annual_cost_as_of_v3`, baseline `annual_cost_as_of_v2`, one historical date,
and no apply option. It captures the actual calculator results and writer preview. No parallel
price calculation is used. Every proof has:

- Exact `/tmp/annual-v3-repaired-preview/snapshot.sqlite` target and SQLite driver.
- Uncached config, a nonexistent isolated config-cache path, array cache, empty database URLs.
- `PRAGMA query_only=ON` before preview queries.
- Canonical pricing and reset shift enabled, beta 1.0, active method still v2.
- A complete result JSON, a runtime marker, exit 0, and `summary.applied=false`.

### Resource results

The approved 186-date continuation took **3,234.18 seconds (53m54s)**. The 242 retained date
runtimes total **4,056.08 seconds**; that sum is not parallel wall time. Date runtimes are
7.66–23.21 seconds. Maximum PHP allocated peak is **151,519,232 bytes (144.5 MiB)**.
The first 56 dates used the earlier parallel scheduler and are not presented as nice-15 runs.
OS RSS and an unbounded multi-date command were not measured. The diagnostic peak exceeds
128 MiB, so this does not establish safety under a 128 MiB production worker limit. A normal
multi-date command can retain provider memoized data across dates; its memory cannot be inferred
from date-isolated runs. Any approved rollout needs explicit memory limits and sequential,
bounded execution. Do not restore concurrency to reduce elapsed time.

## Coverage and economic changes

A pair-day means one contract, consumption and date. Baseline is the actual stored v2, not a
recalculation of v2 with today's shared code.

| Measure | Count |
|---|---:|
| Evidence pair-days | 325,491 |
| Stored v2 contributors | 220,706 |
| Available v3 contributors | 220,628 |
| Unavailable v3 results | 104,863 |
| Matched contributors | 219,137 |
| Changed matched contributors | 36,340 |
| Unchanged matched contributors | 182,797 |
| Lost contributors | 1,569 |
| New contributors | 1,491 |
| Matched / lost / new aggregates | 7,275 / 613 / 0 |

A matched contributor is changed if cost differs by more than EUR 0.00011 or its segment,
method, estimate basis or calculation basis changes. Provenance/version-only changes do not
count. Net available coverage is 78 pairs lower than stored v2. The new results include 1,473
Fixed6 annualizations and 18 bounded canonical/relational recoveries: Cheap Fixed6 (3), Cheap
Quarterly (3), Kokkola Tyyni (9), and Helen Helpposähkö S at 2,000 kWh (3).

### All 1,569 losses

| Explicit class | Pair-days | Contracts | Reason |
|---|---:|---:|---|
| Unknown exact short-term duration | 664 | 12 | Current policy cannot annualize Below6/Between711 from a range alone |
| Unsupported legacy temporal proof | 703 | 7 | Exact-source outputs rejected; exact-date relational components also absent |
| Incomplete promotion terms | 156 | 5 | Shared Current promotion guard |
| Conflicting selected structured pricing | 18 | 2 | Vattenfall duplicate monthly fees; Panelia fixed-rate versus Spot mechanism conflict |
| Incomplete selected package evidence | 13 | 5 | Turku missing included kWh; Helen missing excess-use rule |
| Ambiguous energy mechanisms | 12 | 2 | Shared Current mechanism guard |
| Multiple covering source snapshots | 3 | 1 | September 14 source ambiguity |

The 703 temporal-proof losses cover July 25–September 20 across 58 dates: Vihreä
`0uohci`, `djobdk`, `kvwny9`; Cheap `1ucmby`; Kokkola `6e4ly6`; Aalto `osszso`;
and Sähkötytöt `xlclt2`. Full IDs, consumption counts and date ranges are in the loss/identity
CSVs. These are validation capability limits, not a claim of missing seller prices. Do not
borrow a different source, later interval or current pointer to restore them.

The 31 conflicts/package losses without a detailed result flag were inspected against their
actual selected interpretation output. `other-loss-cases.csv` retains every pair, selected ID,
structured/calculation status and missing facts. Example: Vattenfall interpretation 152 records
conflicting EUR 3.95 and EUR 4.90 monthly fees. Turku interpretations 1597/1837/1735/1560 lack
the included package kWh. Helen 1386 lacks the price/rule above 2,400 kWh. This is source-proof
classification, not an independent seller-prose validation claim.

### Large median changes: complete classification and bounded actual cases

Matched median changes range from **EUR -563.2730 to +720.4288**. A large change is defined
here as absolute change of at least EUR 100. There are **420** such aggregates: 375 OpenEnded
and 45 Quarterly. Every large aggregate is included in 26 old/new central-contributor method
classes, with counts, amount ranges and lost/new membership. The full median CSV retains all
7,275 deltas and percentages. The bounded case sample has **40 aggregates / 98 actual central
contributor rows**, including monthly extremes and requested seams. These are actual ranked
contributors, not representative hypothetical prices.

- **Early OpenEnded decreases:** dated left-censored anchors permit the shared seasonal method
  instead of v2 flat holding. January 23, 18,000 kWh: median EUR 2,043.36 → 1,480.0870
  (-563.2730; -27.57%). Sallila SE Yö changes 2,043.36 → 1,379.2590; Parikkala Q-Valo becomes
  the central contributor at 1,480.0870, from 2,194.20. Both use dedicated evidence and a January
  21 observed proxy anchor; neither has a futures donor. This is method/rank change, not an
  observed seller reduction.
- **Later OpenEnded premium decreases and increases:** the same Current method now uses exact-date
  supplied donors when an own reference is absent. June 25, 18,000 kWh: Keuruu Yösähkö, the
  central contributor, changes 2,145.21 → 1,744.5776 (-400.6324), with two supplied donors and
  the January 21 proxy anchor. August 11's median instead rises by 472.4724: Äänekoski Aikasähkö
  changes 2,190.90 → 2,701.4059, while Vattenfall Kesto becomes central at 2,744.1474 from
  2,567.40. Donor counts describe supplied evidence, not necessarily the selected estimator rung.
- **Largest increase:** September 12, OpenEnded 18,000 kWh, +720.4288. Old central Oomi
  Toimitusvelvollinen changes 2,181.4786 → 2,876.3198 (seasonal → premium); new central
  contributors are Nurmijärvi Yleissähkö at 2,893.8148 and the unchanged Vaasa M package at
  2,910.00. The new median is 2,901.9074. Eleven dated donors were supplied to the premium
  results. This combines method, rank and safety-exclusion effects; it is not measured accuracy.
- **Quarterly April:** 44 large changes occur April 9–30. The required pre-Q2 FI reference does
  not exist: FI futures start April 8. V3 uses seasonal fallback under Current reference safety,
  not stored v2's forward-shift result. April 21, 18,000 kWh: the same Vaasa Tuulisähkö
  Yleissähkö central contributor changes 1,883.4813 → 2,455.8743 (+572.3930). No membership
  changes occur in these 44 aggregates. Quarterly's other large change is July 23, +227.1063
  at 18,000 kWh, from restored dated canonical methods and one additional contributor.

Other segment median ranges are bounded: Fixed6 -74.6413 to +41.9900; Below6 up to +55.1750;
Hybrid -6.00 to +33.7785; MarketReset -6.2201 to +15.2401; Spot up to +3.4638. Other fixed-term
segments differ by at most EUR 0.40 or storage rounding. No large median change is left as an
unquantified generic review item. Classification explains the method and evidence used; it does
not establish that the new annual forecasts are more accurate.

## Continuity and dominant transitions

Contributor-only histograms determine dominance, including sorted ties. Unavailable command
method counts do not enter this check.

July 22/23/24 OpenEnded at 2,000 kWh has premium/shift counts **25/13 → 22/14 → 22/18**.
There is no July 24 tie. OpenEnded stays supplier premium, Hybrid stays Hybrid base, and
Quarterly stays reset shift at all consumptions. At 5,000 kWh:

| Segment | July 22 count / median | July 23 count / median | July 24 count / median |
|---|---|---|---|
| OpenEnded | 61 / 633.7024 | 57 / 661.4460 | 56 / 749.5891 |
| Hybrid | 38 / 506.0102 | 40 / 511.7087 | 38 / 511.8000 |
| Quarterly | 13 / 581.8204 | 13 / 593.8174 | 13 / 597.5108 |

All post-May-1 boundaries, with all changed/lost/new member IDs retained:

1. **July 1, Quarterly, all three consumptions (3 rows):** Q2 lacks a pre-period FI reference;
   Q3 has one. Eight continuing contracts switch seasonal → forward shift. Five Vaasa identities
   leave and five enter. This is a dated reference boundary, not interpretation completion.
2. **August 8, OpenEnded, 2,000 (1 row):** new Iin `bcztkj` adds one own-reference shift
   contributor. Premium/shift 19/18 becomes a 19/19 tie.
3. **September 1, OpenEnded, 2,000 (1 row):** three Seinäjoki identities and Vimpeli `icbxvy`
   leave; three new Seinäjoki identities enter. Shift count falls 19 → 18; premium stays 19.
4. **September 8, OpenEnded, all three (3 rows):** Äänekoski premium member `qvnrc1` disappears;
   new `r25sux` and `yzrni3` enter with own-reference shift. This changes member counts and
   reference availability. No retrospective replacement-chain identity is invented.
5. **September 10, OpenEnded, all three (3 rows):** Parikkala `ggpwbi/gxeryx/jrrlvh` no longer
   have exact-date identity. Three shift contributors leave; premium becomes dominant.

Hybrid has no post-May-1 transition. There is no unexplained dominant processing transition.
Absent exact-date members are not carried forward, and this check does not prove why a seller
removed or replaced an offer. The existing strict display rule should retain these real evidence
boundaries; no display guard was weakened.

At September 11/12/17, OpenEnded 5,000 kWh medians are 850.9426 / 840.7737 / 782.2826,
with 38 contributors each and supplier premium dominant. Hybrid and Quarterly also stay in their
respective regimes at all consumptions. September 20 matches stored current-v2 dominance in
all nine combinations. Amount equality is not implied: OpenEnded 5,000 differs by EUR 5.3753
(789.4073 → 794.7826). Current/historical evidence stores and proxy anchors are not identical.

## Date safety and preservation

The measured audits report zero violations:

- 63,446 supplied premium-observation uses have the exact target observation date and trades
  strictly before both target and pricing date.
- 28,332 anchors are not after target; 3,729 result pairs explicitly use retrospective source output.
- 296,879 exact-snapshot checks, 80,665 covering-observation checks and 78,571 exact-interpretation
  checks pass, including donor provenance. Contract/source ownership, observation overlap,
  NULL-or-exact observation binding and target dates agree.
- All 31,343 futures rows / 117 trade dates have exact ISO calendar dates. The result JSON does
  not expose every internal Spot curve vintage; full-suite leakage tests cover those provider
  boundaries. Do not expand the measured donor audit into an unsupported all-internal-input claim.

Source and preview-copy whole-file hashes remain equal to their before-run values. Protected
logical hashes use every column, ordered by ID, compact UTF-8 JSON per row and newline. Both
files preserve all observed/source/interpretation and dedicated historical tables, plus the whole
annual and aggregate tables during preview.

After the preview, a new independent disposable backup at
`/tmp/annual-v3-repaired-final-apply/snapshot.sqlite` passed complete **40-table content and schema
comparison** with the preview backup and SQLite integrity. Six sequential nice-15 applies used
explicit v3/v2 on January 21, April 8, July 23, July 24, September 12 and September 20. The exact
resolved disposable path is guarded, effective SQLite/config/cache are proved, and query-only is
disabled only on that disposable apply target. The existing writer scopes all replacement to the
selected method and date. No active-local write occurs.

All six applies have exit 0 and actual applied log summaries. They persist **5,221 v3 annual rows
and 180 aggregates**. Result JSON objects equal the corresponding read-only preview objects;
stored amounts/methods and aggregate counts match. All 196,256 v1 and 221,465 v2 annual rows,
and 6,639 v1 / 7,918 v2 aggregates retain byte-identical logical hashes. All other tables stay
unchanged except SQLite's insert sequence. Integrity remains `ok`.

The earlier standalone apply job also completed before the pause was fully settled. Its six
JSON/log/database results were now independently verified against this full preview and protected
hashes. It is not used as evidence of compliance with the later nice-15 instruction. Its initial
`/tmp` versus `/private/tmp` guard failures are retained separately; zero shell exits for those
exceptions were not treated as success. The final fresh apply is the authoritative sequential gate.

## Tests, artifacts and remaining limits

`cd laravel && nice -n 15 php vendor/bin/phpunit` passed **2,878 tests / 21,930 assertions**,
exit 0, 149.757 seconds, reported memory 205.00 MB. Direct PHPUnit runs the complete configured
suite without a second idle Artisan PHP process. It completed before preview. The separately
reported related producer suite passed 296 / 2,859. No app source changed during this executor's
validation. The JSON audit needed a camelCase/snake_case serialization correction; no price or
application code was changed.

The final CSV artifact manifest covers 28 other CSVs (4,753,395 bytes) with row counts, sizes
and SHA-256. Final output validation confirms all 242 JSON/runtime records, all 16 original
failed-preview artifact hashes, and valid task JSON. `git diff --check` passes. Final process
inspection found no remaining owned preview/apply PHP process. CSVs
cover aggregates; contributor/unavailable/coverage/loss groups; all median deltas, 26 large-change
classes and 98 bounded actual contributors; dominant transitions and identities; source-selection
continuity; date/source/trade audits; runtime, per-process proofs and manifests; full backup/apply
and protected hashes. Raw JSON/logs, interrupted diagnostics and scripts remain in `/tmp`.

Concrete limits and decisions:

- Legacy relative/calendar source-date proof is not general. Seven contracts account for 703
  lost pair-days; recovery needs bounded source-backed validation, not a broad exception.
- The 664 short-duration losses need exact source-backed duration before the Current annualizer
  can safely recover them. Other safety exclusions stay unavailable until the source is adequate.
- V5 dated rule validation, dated trusted replacements and older absent donor carry-forward are
  not implemented. The snapshot has no real V5 output. These are not passed parity cases.
- Current producer fixture parity covers four families at all three consumptions, with matched
  selected canonical evidence, target, tariffs, anchors, curve provider and dated donors. These
  fixtures are not full production-store parity. The latest stored current endpoint's nine
  dominant methods match, but no new current-v3 full-database writer replay ran here.
- Keep strict real-evidence display boundaries. Do not change statistics solely to remove them.
- Production release still needs reviewed safeguards, a current verified full backup and explicit
  approvals. No production action, active-method switch, migration, commit, push or deployment ran.
