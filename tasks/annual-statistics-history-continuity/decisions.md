# Decisions

## 2026-09-21 — Review result

- The gaps are not caused by a missing history recalculation. Historical v2 exists for all 233
  evidence dates. The gaps come from a method difference between the historical and the current
  calculation, one one-day defect (Jul 23), and one current-method release without a rebuild
  (Sep 17). Only the Spot transition on May 1 comes from the real FI futures data gap.
- Evidence source: the public statistics page (daily and weekly views) and the public CSV
  `basis_counts`. Production was not queried. The local SQLite snapshot is from 2026-08-25, before
  the v2 application, so it cannot reproduce stored v2 rows. Run
  `scripts/sync-production-database.sh` before local investigation.
- The Jul 23 cause in `spec.md` is a hypothesis. Confirm it before a correction.

## 2026-09-21 — Initial process block (superseded by the follow-up below)

See [investigation.md](investigation.md) for code conditions, checks, and the exact
remaining evidence procedure. Active PHP built-in servers in another local project
prevented the required all-processes-stopped precondition. No process was stopped;
the approved snapshot sync was not run. The July 23 production cause remains
unconfirmed, and the investigation task is `blocked`, not completed.

Code confirms that covering immutable observations close the dedicated historical
path even when no timely valid interpretation exists. Dedicated historical output
can have later completion; immutable output cannot. Seller observation and
interpretation completion are separate dates. Accepting a later interpretation of
the same episode would change the stated no-look-ahead rule and needs an explicit
decision. No such rule was selected. Stored v1/v2, observed evidence, application
code, and the active method remain unchanged. Existing isolated tests passed:
41 tests, 357 assertions.

## 2026-09-21 — Fresh snapshot investigation complete

The earlier process block was too broad. Bounded command, cwd, parent, open-file,
and application-path checks established that the five PHP servers belong to another
project. No Voltikka Laravel/queue/database process or SQLite file user was found.
No process was stopped. The unchanged approved wrapper completed its read-only
production copy and all validation/backup/replacement checks.

[Investigation](investigation.md), [omission records](july-23-omissions.csv), and
[stored aggregates](july-22-24-aggregates.csv) contain the exact evidence. On July 23,
at 5,000 kWh, all 97 relational contributors across OpenEnded/Hybrid/Quarterly are
accounted for: 88 have their first valid same-source output after Helsinki midnight;
nine have no valid output for that source even in the fresh snapshot. Ten of the
97 have timely failed interpretations; the other 87 have no timely interpretation.
July 24 uses different source snapshots for the nine without valid output.
The covering source observation closes the dedicated retrospective path. Earlier
historical episodes stop at July 22 and accept interpretations completed in August.

The source was observed July 23 at 19:15:12 UTC (22:15:12 Helsinki). That does not
make output completed after 20:59:59 UTC timely July 23 knowledge. A same-source
retrospective exception requires an explicit policy decision. Legacy null analysis
observation IDs also require care: source identity is not explicit episode binding.
The proposed minimal options are documented, not selected: retain the strict
knowledge gap, or approve a separate exact-source retrospective rule under a new
method, with remaining missing/failed-source cases kept explicit. Do not extend the
cutoff alone, remove the completion filter globally, or overwrite v2.

Evidence checks passed for 27 aggregate counts/medians/three-basis maps; the resolver's
omission flags match stored provenance. The isolated diagnostic SQLite file's full
SHA-256 stayed unchanged. The evidence task is completed; correction, chronology,
and v3 implementation remain pending. No application code or active method changed.

## 2026-09-21 — Approved exact-source reconstruction; first v3 unit

The user explicitly approved a later validated interpretation of the EXACT source available on
that historical date, with retrospective provenance. This resolves the chronology blocker and
selects new `annual_cost_as_of_v3`, not an in-place v2 rewrite. Latest methods must never import
later seller facts/prices, current peers, or future curves. Missing interpretations stay unresolved.
Earlier investigation statements that no option was selected describe the state before approval.

The first code unit implements method-aware source selection, typed completion/source/episode
provenance, all v2 safety branches, and explicit method-scoped preview/apply. Timely valid output
wins; otherwise the first valid later exact-source output wins. Selected-time ties and explicit
wrong-episode bindings fail closed. Legacy NULL bindings carry an explicit flag and prove only
source identity, not episode completion. V1/v2 behavior and stored rows remain unchanged.

This is a partial implementation, NOT release readiness. As-of premium/anchor integration is
pending. Shared estimators/loaders, active/public method, current v2 producer, schedules, and
production remain unchanged. No commit, push, deployment, or production access is authorized
by this unit.

## First-unit verification

See [v3-first-unit.md](v3-first-unit.md) for the exact isolated July 23 preview and boundaries.
The explicit-v3 dry run exited 0: 1,317 identities, 864 available, 453 unavailable; 30 matched
aggregates, 29 lost contract-consumption pairs, median deltas approximately 0 to +238.99 EUR.
The isolated SQLite copy SHA-256 remained unchanged. No apply ran outside in-memory tests.
These partial results require review and do not establish continuous history or release readiness.

Final focused command:

```sh
cd laravel
php artisan test --filter='AsOfAnnualCostCalculatorTest|AsOfHistoricalInterpretationIntegrationTest|AnnualCostStatisticsWriterTest|RebuildAnnualCostStatisticsCommandTest|ContractAnnualCostPersistenceTest|CurrentAnnualCostStatisticsIntegrationTest|CurrentAsOfAnnualCostParityTest'
```

Result: 82 tests passed, 829 assertions (1.74 seconds). Initial four-class run passed 64 tests /
522 assertions; expanded run before the final source-guard test passed 81 / 826. No test failures.
`vendor/bin/pint --dirty` and `vendor/bin/pint --dirty --test` passed; `git diff --check` passed.
Context mirrors are symlinks and remain byte-identical. Vendor PHP 8.5 deprecation notices are
unrelated. No CSS/JS changed, so no frontend build was needed.

## Open decisions

- Display of the first point after a regime transition.
- Earlier donor and anchor questions are resolved by the approved current-method policy and the
  dated integration below: validated dedicated episodes are eligible donors; left-censored anchors
  retain uncertainty. Their date safety, not their availability in principle, is the constraint.

## 2026-09-21 — Policy reversed: history follows the current method

The user decided that the context files must not prevent a history recalculation. The rule is now
the opposite: when the calculation method changes, recalculate the history to get as complete a
series as possible.

- The canonical policy text is "History follows the current method" in
  `laravel/app/Services/ContractStatistics/AGENTS.md`. The root `AGENTS.md` has it as a mandatory
  principle.
- Replaced: "this policy does not authorize historical rewrites" in
  `laravel/app/Services/CanonicalPricing/AGENTS.md`.
- Reframed as implementation state and known gaps, not rules: the statements that Historical is
  "unchanged", "strict", "retained", or "never reads current peers" in the `CanonicalPricing`,
  `SupplierAdjusted`, `MarketReset`, and `ForwardPremium` context files. The individual sentences
  stay, because they describe the code correctly until v3 exists.
- Kept, because they protect evidence and do not prevent a rebuild: no look-ahead, no rewrite of
  observed evidence, retained earlier method rows, no automatic rebuild on deployment, and explicit
  approval plus a verified backup for a production apply.
- Effect on the open decisions above: left-censored anchors and as-of premium evidence are now the
  expected direction. The remaining question for each is how to make it date-safe, not whether to
  do it.

## Second bounded unit — dated v3 anchors (2026-09-21)

Implemented explicit method selection in `HistoricalPriceEpisodeResolver`; the annual calculator
passes its selected method. V1/v2 and the default retain their prior strict rate-plus-fee rules.
V3 accepts first-observed, left-censored energy proxies, not a proved repricing or hedge date.
Fees do not change energy identity. Exact Time/Season bucket maps, metering, mechanism and VAT
use the shared candidate comparator, never weighted-average equality. Proof-only extraction uses
the pure Current candidate helper with the exact historical date, so redundant/fee-only phases
also retain energy identity. It reads no current pointers or peers. The annual calculator's target
candidate eligibility and estimator policy stay Historical.

One batch loads snapshot events, one loads component-date events, and one loads source interval
boundaries, all through the target. The shared dated evidence resolver then loads all selected
contracts/dates in batches. It has optional contract filtering and observed-snapshot priority for
this caller only; normal annual identity selection remains strict. No annual price calculation
is used as signature proof. The raw-only three-contract/three-date test executes seven queries,
not one query per contract/day, and never reads `electricity_contracts`.

Source-covered dates use the v3 exact-source resolver, including its approved first valid later
interpretation rule and explicit retrospective anchor flag. Before immutable coverage, validated
dedicated historical output supplies signatures and a dedicated-use flag. Missing/invalid covering
source output cannot become raw proof. A source gap after the first immutable observation cannot
reopen raw proof. With no dedicated episode before source coverage, an undiscounted dated General
singleton is sufficient. Raw Time/Season averages are not sufficient. Unknown, conflicting and
different signatures break the run; subsequent matching evidence starts a new uncertain proxy.
Missing calendar dates may retain a gap-flagged proxy, never invented observations.

Remaining boundaries are deliberate and documented:
- Exact contract only. No dated lineage trust is proved, so no current replacement links are read.
- A source boundary without dated snapshot identity is unknown. No current metadata repairs it.
- Pre-source Time/Season without valid full canonical bucket evidence remains unresolved. This
  unit does not add a second raw tariff normalizer or relax candidate eligibility.
- As-of premium evidence, current candidate parity, estimator method integration, full-history
  coverage/median preview, and release approval remain pending. V3 is not release-ready.

Verification:
- Initial anchor run exposed two fixture defects (source fingerprint field and duplicate SQL date
  identity); both were corrected. The next anchor run passed 9 tests / 32 assertions.
- Expanded related runs passed 93 / 871, then 94 / 875, then 95 / 877. The final fee-phase check passed 12 anchor tests / 43 assertions;
  the final related run passed 96 tests / 879 assertions.
  Final command: `php artisan test --filter='HistoricalPriceEpisodeResolverTest|AsOfAnnualCostCalculatorTest|AsOfHistoricalInterpretationIntegrationTest|AnnualCostStatisticsWriterTest|RebuildAnnualCostStatisticsCommandTest|ContractAnnualCostPersistenceTest|CurrentAnnualCostStatisticsIntegrationTest|CurrentAsOfAnnualCostParityTest'`.
- Pint on the six changed PHP files passed; `vendor/bin/pint --dirty --test` passed.
- `git diff --check` and AGENTS/CLAUDE mirror checks passed.
- Earlier uncommitted source-unit work was preserved. No deployment, commit, push, production
  access, or apply occurred. No CSS/JS changed; no frontend build was needed.

## Third bounded unit — shared Current policy over dated evidence (2026-09-21)

Explicit v3 now uses shared Current candidate/calculation semantics with an explicit target date.
V1/v2 retain Historical. `AsOfPremiumEvidenceAdapter` receives the already loaded v3 exact-date
universe, builds consumption-free supplier/reset candidates, resolves all supplier anchors once,
forms full normalized bucket spreads against dated audience-matched references, and invokes the
existing pure premium selector. All three consumptions reuse these date-local results. Missing
references return no donor, never zero. Current estimators retain own-reference priority, date and
finite guards, reset-flag behavior, short-term horizons, known energy/fees and Hybrid separation.

No current premium loader, current episode resolver, active/current pointer, current company or
RetailPremium query is used. Exact dated company names and exact contract IDs provide conservative
identity. Valid dedicated historical episodes are eligible through July 22, and approved later
interpretations of the exact immutable source are eligible. Completion is processing provenance,
not a later economic observation. Private annual provenance records supplied premium observations,
source/interpretation/episode IDs, completion, anchor flags and reference facts. Supplied evidence
is not necessarily used: the shared estimator can still prefer a valid own reference.

The source campaign extractor was made pure and shared without changing current validation.
V3 passes only its exact selected historical payload. A malformed selected payload fails closed.
Dedicated v4 episodes use their exact component/discount manifest, not later prose. V5/known-rule
selected output fails closed with `historical_energy_rule_source_validation_unavailable`, including
parser-invalid V5 output with no valid alternative. V5 rule parsing and full dated rule validation
are not implemented; no V5 parity claim is made.

Historical anchor events now include the day after an inclusive observation end if another
observation covers that day, matching the current event rule. The regression verifies the event
is queried and does not invent a signature when dated snapshot identity is missing.

Remaining limits: exact-date donors only (older absent donors are not carried forward); no dated
replacement trust; missing dated identities/full Time/Season proof and missing exact-source output
remain unresolved. FI references before April 8 remain a real gap. Full-history coverage/median
preview, seam review and release approval remain pending. No active method, command default,
production data, observed evidence, or earlier stored method is changed.

Verification for this unit:
- Initial targeted failures found a missing selected `schema_version` column; the bounded query
  was corrected. A new test used the wrong Season enum name, and a reset fixture initially used
  a cadence-derived June reference instead of the intended missing April reference. Both fixtures
  were corrected. No failures remain in the final focused gate.
- Final focused command: `cd laravel && php artisan test --filter='AsOfPremiumEvidenceAdapterTest|HistoricalPriceEpisodeResolverTest|AsOfAnnualCostCalculatorTest|AsOfHistoricalInterpretationIntegrationTest|AnnualCostStatisticsWriterTest|RebuildAnnualCostStatisticsCommandTest|ContractAnnualCostPersistenceTest|CurrentAnnualCostStatisticsIntegrationTest|CurrentAsOfAnnualCostParityTest|ForwardPremium|CurrentPremium|SupplierAdjusted|MarketReset|CanonicalContractPriceCalculatorTest|CurrentSourcePromotion'`.
  Result: 269 tests passed, 2,507 assertions.
- Full PHP suite before the final parser-invalid V5 safety regression: 2,863 tests passed,
  21,655 assertions (153.14 seconds).
- Final `cd laravel && php artisan test`: 2,863 tests passed, 21,656 assertions (167.86 seconds).
- Final `vendor/bin/pint --dirty --test` and explicit Pint test for the two new PHP files passed.
  `git diff --check` and all changed AGENTS/CLAUDE mirror checks passed.
- Pint was run on both new PHP files explicitly and on all dirty PHP files. Both passed.
- No CSS/JS changed. No build, preview, active-local-DB apply, production operation, commit or push
  was run. All prior uncommitted work remains in place.

## Full-history isolated preview and independent-review repair (2026-09-21)

See [v3-preview.md](v3-preview.md) and its bounded CSV artifacts. The existing rebuild command
completed all 242 evidence dates from January 21 through September 20 with explicit v3/v2
selection, zero failed dates, query-only SQLite, isolated config, and array cache. The diagnostic
captured the same calculator results and writer preview; it added no parallel price calculation.
No apply ran. Source and isolated-copy whole-file hashes and all protected logical table hashes
remained unchanged. Preview preservation is not a new full-snapshot apply test.

Results: 325,491 evidence pairs; 221,303 available; 104,188 unavailable. Against stored v2:
219,830 matched (35,209 changed), 876 lost, 1,473 new. All new pairs are six-month annualizations.
All loss classes and contributor transitions are quantified in the artifacts. Matched median
deltas span -563.2730 to +773.3223 EUR. These changes are not accepted forecast improvements.
Runtime: 23m30s wall; retained per-date runs 4.09–27.95 seconds; maximum PHP allocated peak
144.5 MiB. Processes were date-isolated with 512 MiB limits and at most four concurrent dates.

**Release acceptance failed.** July 23 no longer breaks the 5,000/18,000 series, but July 24
OpenEnded at 2,000 changes from supplier premium to a 20/20 premium/shift tie. Sources 16, 377,
234 and 380 retain source identity while selected interpretations change candidate or reset
mechanism proof. This is an unresolved interpretation-continuity issue, not proved new seller
prices. Remaining dominant boundaries also include July 1's real quarterly reference gap,
August 7's three missing dated members, and September 14/15's three ambiguous Lammaisten
source selections. Hybrid has no post-May-1 dominant change. September 12/17 are continuous
inside reconstructed v3, but the current writer still writes v2. No same-evidence dated current
producer parity was verified. Keep current-producer readiness and release approval pending.

The independent reviewer found malformed trade-date parsing in `AsOfPremiumEvidenceAdapter`.
A separate executor repaired it during the preview: strict `!Y-m-d`, exact round trip, and
`InvalidArgumentException`/`ValueError` handling skip invalid donors without losing valid ones.
The manager reported 270 tests / 2,578 assertions, Pint and diff checks passing, including 11
invalid dates and persisted three-consumption anchor flags. This executor changed no app or
context file. The manager can update the closest context separately.

The first recorded service-source checkpoint was during the run. Per-process hashes identified
five runs spanning the adapter edit; all five plus three reference dates were rerun on final
code. All eight full result JSON objects matched. All 31,343 futures rows have exact ISO trade
dates; the real reference provider returns `toDateString()`. The only service-manifest change
was this parser repair. The final dated audit found zero violations across 62,324 supplied
observation uses and 28,326 anchors. This is snapshot-specific equivalence, not a general
malformed-input claim. Details, hashes, timing, limitations, and the eight reruns are retained.

No production operation, active-local write, active-method change, migration, commit, push,
deployment, or frontend build was performed. The preview task is complete; acceptance and the
specific remaining continuity/current-producer work remain pending.

## Latest-source continuity repair (2026-09-21)

The approved latest-method reconstruction policy supersedes the first unit's timely/first-later
preference. That preference changed mechanism proof for unchanged sources when a target crossed
an analysis completion timestamp. This was a processing seam defect, not seller evidence.
V3 now selects the newest target-validated exact-source reconstruction, with selected-second
ambiguity rejection and unchanged ownership/observation binding. Completion remains provenance.
V1/v2 remain unchanged. No new seller fact, source interval or recurrent episode is admitted.

Every v3 source candidate now passes the existing InputBuilder and full validator with its
registered stored profile, exact payload and target date. Source campaign removal, invalid
pricing and unsupported target dates cause rejection and next-newest valid selection. Private
provenance retains rejected IDs and target-validation flags. Legacy validators lack general
prose-date proof, so unsupported calendar/relative assumptions retain an explicit unresolved
flag. Scoped structured discounts and exact source Fixed6/12/24 terms can prove supported
relative boundaries; no contract-specific exception was added. V5 remains excluded.

See `v3-continuity-repair.md` and its separate CSV artifacts. The new isolated read-only rerun
completed July 22/23/24 at all three consumptions, with 888/874/862 available pairs and zero
failed dates. All requested dominant methods remain continuous. OpenEnded at 2,000 kWh now has
premium/shift counts 25/13, 22/14, 22/18: the July 24 tie is gone. Across 285 continuing source
identities, zero selected interpretation IDs change. Sources 16/377/234/380 select
1566/1832/1734/1835 on both days. Surffari source 162 selects 1686; target validation rejects
163's missing July campaign. All original failed-preview artifacts and database hashes remain
unchanged. No full-history reacceptance is claimed.

Remaining limits are explicit: July 23/24 have 11/13 contracts with no accepted source output
after target rejection, including 10/11 with unsupported legacy temporal proof. Safe relational
fallback remains labelled; these are not proven seller-evidence gaps. Full-history review,
current-producer dated parity, verified backup and separate apply/active-method approval stay
pending. The command warning now lists these real checks. The nearest statistics context also
records the earlier strict ISO premium-trade-date repair and skip-only-the-bad-donor behavior.

Final related tests: 357 passed / 3,012 assertions. Final full PHP suite: 2,871 passed / 21,815
assertions (146.68 seconds). Pint, diff, mirror and protected-hash checks pass. Initial focused
failures came from partial test fixtures and superseded selection expectations; these were fixed,
with seven new real-validator tests supplying the full source-proof gate. No CSS/JS build was
needed. No production access, apply, active-method change, commit, push or deployment occurred.
Prior uncommitted implementation and preview files remain intact.

## Repaired full-history replay paused for CPU use (2026-09-21)

See `v3-repaired-preview.md`. The executor selected eight concurrent PHP workers without
user approval for that resource level. The user reported excessive CPU use. The manager
stopped the main scheduler and eight workers. The executor will not resume them, start PHP,
or send process signals without instruction. Further calculation requires explicit approval
for one low-priority worker. At the read-only check, 56 completed date JSON files remained
through March 18. The 242-date acceptance review is incomplete, not passed.

A separate sequential disposable-copy apply job had already started. The executor reported
its active parent/child process IDs to the manager for a separate pause. Full-copy table and
schema equality was checked before apply, but final protected v1/v2 equality is pending.
The first path guard blocked all attempts because macOS resolves `/tmp` to `/private/tmp`;
those logs remain separate. Their zero shell exits are not success evidence. The corrected
controller also requires JSON and runtime output. No production or active-local apply ran.
All previous failed-preview and continuity-repair artifacts remain in place.

### Approved sequential continuation

The user approved one PHP worker at a time, started with `nice -n 15`. The manager killed
all stopped owned process trees. The executor did not restart the old parallel scheduler.
The complete PHPUnit suite ran first, with no concurrent preview/apply: 2,878 tests and
21,930 assertions passed, exit 0, 149.757 seconds, 205.00 MB reported memory.
Direct PHPUnit avoids a second idle Artisan PHP process. The current-producer full-suite
gate is complete. The repaired preview then resumed with a guarded sequential loop.
The 56 completed dates are retained because historical/config sources and database hashes
match; only the two reviewed, nonhistorical current-producer source files differ.

## Repaired full-history acceptance and sequential apply proof — 2026-09-21

See `v3-repaired-preview.md` and its distinct CSV artifacts. The complete 242-date replay passes
at all three consumptions. The original failed preview remains unchanged. July's processing seam
is repaired globally: 14,978 continuing exact-source identities have zero selected-ID changes.
All 11 remaining dominant boundaries after May 1 have dated evidence causes: July 1 Quarterly
reference availability; August 8 Iin membership; September 1 Seinäjoki/Vimpeli membership;
September 8 Äänekoski identity turnover; September 10 Parikkala missing identities. September
12/17 are continuous. All nine requested September 20 dominant methods match stored current-v2.
Retain the strict chart rule at these real evidence boundaries; do not hide them by changing
compatibility or carrying missing members forward.

Local historical continuity is accepted within explicit evidence limits. No production approval
is implied. The current producer is ready under explicit v3 configuration, with no default switch.
Four-family/all-three-consumption same-input fixture parity passes. No full current-v3 database
replay or equality between unequal current/historical evidence stores is claimed. The endpoint
comparison proves dominance only; OpenEnded 5,000 differs by EUR 5.3753 on September 20.

Coverage: 325,491 candidates, 220,628 available, 104,863 unavailable, 219,137 matched (36,340
changed), 1,569 lost and 1,491 new. Median deltas span EUR -563.2730 to +720.4288. All 420
changes of at least EUR 100 are classified into 26 central-method groups; 40 aggregate cases
retain 98 actual central contributor rows. These are method/rank changes, not forecast accuracy
claims. All loss classes are explicit: short-duration proof 664; legacy temporal proof 703;
promotion safety 156; structured conflict 18; incomplete package 13; ambiguous mechanism 12;
covering-source ambiguity 3. The 703 temporal-proof losses span seven named contracts over 58
dates, July 25–September 20. Recovery needs bounded source-backed date validation, not a guard
exception. V5, dated replacement trust and older absent donors remain unsupported.

After the approved full suite, the remaining 186 dates ran one PHP child at a time with
`nice -n 15`, preserving 56 complete earlier dates. Wall time was 3,234.18 seconds; all retained
date durations sum to 4,056.08 seconds, with 144.5 MiB maximum allocated PHP memory. This is
not proof that a 128 MiB worker or an unbounded multi-date command is safe. No concurrency was
restored. The initial loaded-file diagnostic was too strict because Laravel loads current
classes at command registration; fail-on-call guards prove their changed methods are not called.
Its failed March 19 diagnostic remains separately; no application calculation changed.

All source/config hashes match across the sequential continuation and final audit. Only the two
reviewed nonhistorical current-producer files differ from the original pre-run manifest. All
242 proofs retain explicit v3/v2, isolated SQLite/config/array cache, and query-only preview.
Date audits report zero violations for 63,446 supplied observations, 28,332 anchors, 296,879
snapshot checks, 80,665 covering-observation checks, and 78,571 exact-interpretation checks.
All futures trade-date strings are valid ISO. Internal Spot vintage completeness is bounded by
the test suite, not asserted from the donor-only output audit.

A fresh independent disposable backup proved equal schema and all 40 table contents, then ran
six nice-15 v3 applies after preview. It wrote 5,221 annual rows and 180 aggregates. Actual stored
amounts/methods and counts match preview. V1/v2 rows and observed/source/historical evidence
retain byte-identical logical hashes. Source and preview files retain whole-file hashes. The
earlier standalone apply's actual six outputs were also verified; its prior blocked path-check
logs are not success evidence. No active local database, production database or active config
was written. No commit, push, deployment or frontend build ran. Final full suite: 2,878 tests /
21,930 assertions, exit 0, 149.757 seconds. Production approvals and context cleanup remain
separate release tasks, not reasons to repeat this completed unmodified-data validation.

## Final local documentation cleanup — 2026-09-21

Updated the seven root/Laravel/statistics/canonical context files after reading implemented method
selection, target validation and dated premium code. Live policy now scopes the retained Historical
behavior to v1/v2. Explicit v3 uses shared Current semantics with dated sources, anchors and premiums;
latest exact-source selection requires full target validation. Dated old release records stay intact.
The current factory defaults to v2; the service selects v3 only for exact active-v3 configuration.
Default/public behavior is unchanged. The final preview and current-writer reports remain the
validation evidence; no PHP process, test, preview or build was started for this unit.

The local checks cover task JSON, the documentation diff and all seven AGENTS/CLAUDE symlinks.
V5/date-lineage/older absent donor limitations, seven-contract temporal-proof losses, short-duration
proof losses and strict real-evidence display boundaries remain explicit. Neither full-store amount
parity nor forecast improvement is claimed. Preview instructions require explicit 512 MiB and one
nice-15 worker per date; 144.5 MiB is not evidence of 128 MiB or unbounded-range safety.

Only local context cleanup is complete. Release prerequisites remain separate: explicit Git push
approval (main pushes deploy), a current verified full production backup, production apply approval,
and active-method switch approval. No automatic deployment-time rebuild, commit, push, production
operation or authorization is part of this unit.
