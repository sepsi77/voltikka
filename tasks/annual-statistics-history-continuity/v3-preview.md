# Full-history v3 preview — 2026-09-21

## Result and acceptance

**Preview complete. Release acceptance failed. No apply or active-method change.**

All **242 evidence dates**, 2026-01-21 through **2026-09-20** (yesterday in Helsinki), completed with exit 0. February 12 has no evidence date. The preview fixes the July 23 dominant-method anomaly at 5,000 and 18,000 kWh, but **July 24 still changes the OpenEnded display regime at 2,000 kWh**. Some causes are interpretation revisions of the same source, not missing seller prices. Do not certify all remaining transitions as real economic evidence gaps.

| Acceptance item | Result |
|---|---|
| Full-history isolated preview | Pass: 242 dates, zero failed dates |
| July 22–24 no longer breaks any requested series | **Fail**: OpenEnded 2,000 kWh changes to a tie on July 24 |
| No unexplained post-May-1 dominant transition | **Not accepted**: 13 segment/consumption transitions on five dates; causes below |
| September 11/12 and 16/17 inside the reconstructed v3 series | Pass for dominant methods in all three requested segments/consumptions |
| Historical-to-current producer parity | **Not verified; release blocker**. Current writer still writes v2 |
| Date safety of retained supplied evidence | Pass for measured invariants; not a replacement for leakage tests |
| V1/v2 and observed evidence preservation | Pass for preview; no apply preservation claim |
| Production release/apply | Not authorized or performed |

## Isolation and command

Input: the already-synced local snapshot. No Railway or other production operation ran. Python SQLite backup copied a `mode=ro` source connection from `laravel/database/database.sqlite` to **`/tmp/annual-v3-full-preview/snapshot.sqlite`**. This was a distinct file, not a link.

The temporary `/tmp/annual-v3-full-preview/run.php` invoked the existing Laravel console command once per date:

```text
contracts:rebuild-annual-cost-statistics --date=YYYY-MM-DD
  --method=annual_cost_as_of_v3 --baseline=annual_cost_as_of_v2 --stop-on-error
```

There is no export option. A temporary subclass only captured the result of `parent::calculate()`; it added no calculation. The normal command called the existing calculator and writer preview. The diagnostic then reused those results and the same writer preview for aggregate medians. CSV processing only counted and compared results. It did not calculate contract prices.

Before each command, the wrapper pinned and printed the effective SQLite path/driver, absent config cache, array cache, and **`PRAGMA query_only=ON`**. Pins included empty `DB_URL` and `DATABASE_URL`, local environment, a nonexistent `/tmp` config-cache path, `CACHE_STORE=array`, `CACHE_DRIVER=array`, stderr logging, empty Sentry DSNs, canonical/reset flags enabled, reset beta 1.0, and active method **v2**. No cache clear ran. See `v3-preview-run-proofs.csv` for all 242 retained proofs. All summaries have `applied=false` and zero persisted rows.

Processes had a **512 MiB PHP limit** and one date each. At most four date processes ran together on a 32 GiB host. The date lanes repeated 12 date invocations while they caught up; all repeated commands also exited 0. There were 253 batch calls, one initial July 23 call, and eight parser-fix verification calls: **262 successful command invocations**. The final CSVs retain exactly one result per evidence date.

Measured wall time from the first pilot log to the last completed command: **1,410.24 seconds (23m30s)**. The 242 retained command durations sum to **4,314.26 seconds**; this is not parallel wall time or the sum of repeated calls. Per-date duration: **4.09–27.95 seconds**. Maximum retained PHP allocated-memory peak: **151,519,232 bytes (144.5 MiB)**. OS RSS was not measured. No unbounded multi-date calculator process ran.

## Coverage and financial changes

A *pair-day* is one contract, consumption, and date. All three consumptions are 2,000 / 5,000 / 18,000 kWh.

| Measure | Count |
|---|---:|
| Candidate evidence pair-days | 325,491 |
| Stored v2 contributors through yesterday | 220,706 |
| Available v3 contributors | 221,303 |
| Matched contributors | 219,830 |
| Changed matched contributors | 35,209 |
| Unchanged matched contributors | 184,621 |
| Lost contributors | 876 |
| New contributors | 1,473 |
| Unavailable v3 results | 104,188 |
| Matched aggregates | 7,275 |
| Lost aggregates | 613 |
| New aggregates | 0 |

Matched aggregate median deltas span **−563.2730 to +773.3223 EUR**. These are changes, not proof of improved forecasts. Large changes still need economic review. Most lost aggregates are sparse short-duration segments that the shared Current policy cannot price with an exact duration.

`v3-preview-contributor-groups.csv` quantifies **every group** by old/new segment, consumption, method, calculation basis, status, reason, date count, unique contracts, and amount-delta bounds: 111 changed groups, 42 lost groups, three new groups, plus unchanged/unavailable groups. A matched result is changed if its amount differs by more than EUR 0.00011 or its segment/method/estimate basis/calculation basis differs. Method-version and provenance-only changes do not count as financial changes. The tolerance excludes four-decimal storage rounding. `v3-preview-coverage-identities.csv` gives every new/lost contract-consumption identity and its date count.

### All lost/new contributor classes

| Class | Lost pair-days | Classification |
|---|---:|---|
| Unknown exact short-term duration | 664, 12 contracts | Shared Current safety policy and typed-duration limitation. **Not proof that seller text lacks duration**. Coarse Below6/Between711 ranges cannot establish the exact billing term |
| Insufficient promotion terms | 168, six contracts | Shared Current exclusion, including Hehku, Cheap quarterly, and Vimpelin. These require source-term review; do not call all of them irrecoverable missing seller facts |
| Ambiguous fixed energy plus Spot margin | 12, two Kerava identities | Shared Current mechanism safety guard, not a new v3 arithmetic failure |
| Other selected canonical output not listed | 29, 11 contracts, July 23 only | 18 conflicting-pricing pairs, eight incomplete-package pairs, three Surffari pairs with no phase applicable to July |
| Missing exact-date components | 3, one Spot identity, September 14 | Multiple covering source snapshots prevent canonical selection; no dated components exist for fallback |

All **1,473 new pairs** are six-month `term_price_annualized` results across **39 contract identities**. They use the real term under Current policy rather than require priceable continuation beyond that term. There is no new aggregate segment.

For the 29 July 23 losses, Surffari source **162**, interpretation **163**, contains only a phase starting September 1, although it completed on July 23. Its own selected output describes the earlier campaign as expired. This is an **interpretation/evidence-processing limitation**, not proof that July seller terms never existed. The preview correctly does not fill July with September pricing. Hehku adds three promotion-guard losses, so the complete July 23 loss is **32 pairs**, not the earlier partial unit's 29.

### Unavailable evidence, including pre-existing exclusions

The complete reason counts are: missing dated snapshot identity **92,058**; unknown consumption eligibility behind a null mask **5,887**; canonical outcome not listed **3,933**; consumption out of range **1,663**; legacy mask unavailable **607**; exact-date components unavailable **27**; cost above the safety ceiling **13**.

Missing dated identity, missing consumption proof, missing exact-source output, and source ambiguity are unresolved **input-evidence gaps under the approved selection rules**. Out-of-range and cost-ceiling cases are safety exclusions, not missing data. Canonical policy/output exclusions are not automatically true seller-evidence gaps. The remaining pre-existing canonical exclusions were not all revalidated against seller prose. The snapshot has schema-v2/v3/v4 output and no schema-v5 output; this run does not test real V5 readiness.

## Requested seams

The complete per-date/segment/consumption counts, writer medians, and contributor-only method histograms are in `v3-preview-aggregates.csv`. Dominance includes sorted ties, as the existing public compatibility rule does. Global command method counts include unavailable results and were **not** used as contributor histograms.

### July 22–24, 5,000 kWh

| Segment | Date | v2 → v3 count | v2 → v3 median EUR | v3 dominant |
|---|---|---:|---:|---|
| OpenEnded | Jul 22 | 61 → 61 | 638.3735 → 633.7024 | supplier premium |
| OpenEnded | Jul 23 | 62 → 59 | 625.3125 → 662.2952 | supplier premium |
| OpenEnded | Jul 24 | 58 → 56 | 653.3694 → 803.1770 | supplier premium |
| Hybrid | Jul 22 | 38 → 38 | 506.0102 → 506.0102 | Hybrid base |
| Hybrid | Jul 23 | 41 → 41 | 509.2400 → 510.5000 | Hybrid base |
| Hybrid | Jul 24 | 39 → 39 | 510.5000 → 510.5000 | Hybrid base |
| Quarterly | Jul 22 | 13 → 13 | 581.8204 → 581.8204 | reset forward shift |
| Quarterly | Jul 23 | 12 → 12 | 533.1337 → 597.1174 | reset forward shift |
| Quarterly | Jul 24 | 13 → 12 | 604.1108 → 600.8108 | reset forward shift |

At 2,000 kWh, OpenEnded changes from premium **20 versus shift 14** on July 23 to a **20/20 tie** on July 24. The strict chart rule therefore still creates a gap. Five contributors move from `none` to supplier shift, one reset becomes supplier shift, one supplier shift becomes reset, four members leave, and one joins. The transition CSV retains every identity.

Three of the `none` contributors are Lammaisten products with no valid July 23 exact-source output. Across OpenEnded/Hybrid/Quarterly at 5,000 kWh, **nine available July 23 contributors** still use relational fallback because valid exact-source output is missing (three Lammaisten, six Hybrid). No current source was substituted.

However, the full July transition is **not just a true missing-evidence gap**. The following same-source revisions change candidate/mechanism proof between July 23 and 24:

| Source | Contract | Interpretation Jul 23 → Jul 24 | Method change |
|---:|---|---|---|
| 16 | Keuruun Yösähkö | 122 → 1052 | none → supplier shift |
| 377 | Helen Perussähkö | 61 → 1414 | none → supplier shift |
| 234 | Imatran Huoleton | 246 → 1090 | reset shift → supplier shift |
| 380 | Fortum Kesto | 302 → 1149 | supplier shift → reset shift |

These follow the implemented first-valid-later versus latest-timely selection rule. They do **not** prove a seller repriced. `v3-preview-july-source-revisions.csv` records selected IDs, anchors, and amounts. This is an unresolved interpretation-continuity acceptance issue, not a command crash or permission to select later seller facts.

### All post-May-1 transitions

`v3-preview-transitions.csv` has 13 rows; `v3-preview-transition-contributors.csv` quantifies all changed/new/lost membership groups at those boundaries.

- **July 1, Quarterly, all three consumptions:** seasonal index → forward shift. Q2's required pre-April pricing reference is absent because FI futures begin April 8. Q3 has a usable prior reference. Eight continuing identities change method; five Vaasa identities leave and five enter. This is a real dated reference gap, with observed membership turnover.
- **July 24, OpenEnded, 2,000:** premium → premium/shift tie. Causes and unresolved same-source revisions are above.
- **August 7, OpenEnded, all three:** three premium contributors have no exact-date identity: Iin `v7syfz`, Alajärvi `yd7lgu` and `ydi2at`. Premium count falls 20 → 17 while shifts stay 20 at 2,000 and 19 at the other consumptions. This is observed membership loss, not a model release. No carry-forward is permitted.
- **September 14 and 15, OpenEnded, all three:** three Lammaisten products lose and regain canonical supplier proof. On September 14, each has four distinct covering source snapshots; the resolver correctly fails closed. For the General contract these observation/source IDs are **2420, 2459, 2480, 2501**, all observed that day. This is unresolved within-day source ambiguity, not permission to use a current pointer. The shift count falls 19 → 16 at 2,000 (tie) and 18 → 15 at 5,000/18,000 (premium becomes dominant), then recovers.

Hybrid has **no** dominant transition after May 1. Quarterly has only July 1. The evidence explains the measured boundaries, but July's same-source processing changes remain unaccepted as a real-economic-gap exception.

### September 11/12/17 and the current producer

At 5,000 kWh, OpenEnded reconstructed medians are **861.1801 / 850.7705 / 787.1262 EUR** on September 11/12/17, with **38 / 38 / 38** contributors. Supplier forward shift is dominant on all three dates. Hybrid medians are **583.34 / 593.20 / 598.91**, with Hybrid base dominant. Quarterly medians are **661.5916 / 651.5700 / 620.5090**, with reset forward shift dominant. All three consumptions avoid a reconstructed transition on September 12 or 17.

This does **not** prove historical-to-current parity. On September 17, stored current v2 OpenEnded has premium **19**, shift **15**; reconstructed v3 has premium **16**, shift **18** at 5,000 kWh. `ContractPriceStatisticsService` still calls the annual writer with **AsOfV2**. The current producer was not changed or replayed with explicitly identical dated inputs. Current replacement lineage and older absent donor carry-forward remain unsupported by v3. Do not activate v3 history and assume that current v2 collection continues that series.

## Parser repair during the run

The independent review found that `Carbon::parse()` could accept malformed/relative trade dates. Another executor changed **only the adapter behavior and its tests**: `createFromFormat('!Y-m-d', ...)`, exact round-trip validation, and `InvalidArgumentException`/`ValueError` handling skip a bad donor while preserving valid alternatives. The manager reported **270 tests / 2,578 assertions**, with Pint and diff checks passing; 11 invalid-date cases and persisted three-consumption anchor flags were covered. This preview executor did not rerun that suite or edit application/context files.

Initial adapter SHA-256: `5df3eea8747cd654b74d312cc05272627acfd4b42e9e7b3ad083c8711d9b3c10`.
Final: `07027acce167015e70137b5e19334936b3871aa06524143e64dac360edc9cdc1`.

The first full source checkpoint was recorded **during** the run at 08:38:42 UTC, not before the pilot. The 104-file service manifest differs only in this adapter; the post-repair and final manifests match. Per-process start/end hashes were added when the review notice arrived. Runs spanning the change were **February 25, May 9, May 10, July 7, September 6**; May 10 started on an intermediate edit. All five were rerun on final code. May 1, July 23, and September 1 were also rerun. **All eight full diagnostic JSON objects were identical**, including prices and provenance, before/after; see `v3-preview-parser-reruns.csv`.

All **31,343 stored futures rows / 117 distinct trade dates** pass exact ISO calendar-date validation. The real provider's reference payload uses `toDateString()`. The parser-only change therefore has no malformed reference input in this snapshot. Across the complete retained preview, **62,324 supplied observation uses** have the exact target observation date and reference trades strictly before target and pricing date. **28,326 anchors** are not after target. Zero measured violations. This establishes snapshot-specific equivalence, not general immunity to malformed future inputs.

## Preservation and artifacts

Protected logical hashes use every column, `ORDER BY id`, compact UTF-8 JSON per row, and a newline. Source and isolated-copy hashes agree; both remain unchanged afterward. Protected rows include **196,256 v1 annual rows, 221,465 v2 annual rows, 6,639 v1 aggregates, 7,918 v2 aggregates**, all snapshot/component/source/observation/interpretation rows, and both dedicated historical tables. These whole-table counts include dates outside the preview range.

Full-file SHA-256 before/after also matches:

- Active local source: `101ac73df9f86a7c9f452d36a5c249999d72c9652b6dd46706fc67282644d387`.
- Isolated backup: `a9cb5459af3ec66b37b1bcbc39421d04fd703e5fedb85d83652ae31aa7479c6f`.

SQLite backup file hashes differ from each other because backup can change physical layout; the logical protected contents are equal. Hash checkpoints were taken after the read-only pilot and before the full date batch; source equality and `query_only` also bound that pilot. No apply ran, so this is **preview preservation**, not a new full-snapshot apply test.

Retained bounded CSVs:

- `v3-preview-aggregates.csv`: 8,706 date/segment/consumption rows, including unavailable-only segments.
- `v3-preview-contributor-groups.csv`, `v3-preview-coverage-identities.csv`, `v3-preview-loss-classes.csv`: exhaustive change counts and eligibility classes.
- `v3-preview-unavailable-groups.csv`: bounded reason/flag groups, not a claim of full seller-text review.
- `v3-preview-seam-contributors.csv`: changed/new/lost pairs on July 22/23/24 and September 11/12/17/20.
- Transition and July-source-revision CSVs: all remaining dominant boundaries and concrete selected-source examples.
- Runtime, run-proofs, source-hashes, protected-hashes, parser-reruns, trade-date-audit, and date-audit CSVs: reproducibility and safety evidence.

`v3-preview-artifacts.csv` records row counts, byte sizes, and SHA-256 for the 16 other CSVs (2,568,454 bytes total). Final validation passed: all 242 date JSON files parse, all retained command exits are zero, all summaries are preview-only, aggregate identities are unique, contributor sums reconcile, and all eight repair comparisons match. `git diff --check` passed. `shasum -a 256 -c /tmp/annual-v3-full-preview/file-before.sha256` returned OK for both database files. No application tests were rerun by this documentation-only executor.

Raw per-date JSON/logs and temporary diagnostic scripts remain in `/tmp/annual-v3-full-preview/`; they are not repository application code. No migration, dependency, production operation, commit, push, deployment, active-method switch, or CSS/JS change was made. No frontend build was needed.
