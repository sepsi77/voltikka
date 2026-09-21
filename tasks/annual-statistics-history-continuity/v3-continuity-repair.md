# V3 latest exact-source continuity repair — 2026-09-21

## Result

The July processing seam is repaired in the bounded rerun. **Release remains blocked.**
The original `v3-preview.md` and its failed-acceptance artifacts remain unchanged. This report
is a new July 22–24 preview, not a replacement full-history pass or an apply.

All three dates completed with exit 0 on a new SQLite backup. All 2,000/5,000/18,000 kWh
series keep the same dominant method across July 22–24: OpenEnded uses supplier premium,
Hybrid uses Hybrid base, and Quarterly uses reset forward shift. The OpenEnded 2,000 kWh
premium/shift counts are **25/13 → 22/14 → 22/18**. There is no July 24 tie.

## Selection guarantee

V3 orders eligible interpretations of the exact covering source by completion time, newest
first. Completion before/after the target is provenance only, never a selection boundary.
Every candidate must retain source/contract ownership, permitted status, empty stored errors,
parser success, and a NULL or exact covering observation binding. Different recurrent episodes
and different sources cannot repair it. A tie at the newest valid completion second fails
closed; older ties do not override a unique newer valid reconstruction. V1/v2 remain strict.

The full existing InputBuilder and ContractInterpretationValidator run with the exact source
payload, target `analysis_date`, and registered stored profile. Active discounts and their
normal continuation must survive. Expired phases cannot be reused as current terms. Rejected
candidate IDs and target-validation flags remain in private provenance. Unsupported profiles
and unsupported legacy temporal proof have separate flags. Completion, target, selected IDs,
retrospective use, and legacy NULL binding remain explicit.

The retained legacy validators do not prove every prose-derived date. A bounded extra guard
therefore accepts exact calendar dates in validated phase/schedule citations, scoped structured
discount boundaries, and exact source Fixed6/Fixed12/Fixed24 term boundaries. Active UntilDate
coverage can prove applicability of an earlier phase start at the exact target. Unknown
boundaries and zero-month starts retain shared timeline semantics; they add no calendar date.
Other date assumptions stay unresolved. No current pointer, later seller payload, publication,
source interval extension, new economic data, or borrowed July 24 source is used.

Across **285 continuing exact-source contract identities** on July 23/24, **zero selected IDs
change**, including null selections. The four reported sources now select the following IDs
on both dates:

| Source | Contract | Selected interpretation |
|---:|---|---:|
| 16 | Keuruun Yösähkö | 1566 |
| 377 | Helen Perussähkö | 1832 |
| 234 | Imatran Huoleton | 1734 |
| 380 | Fortum Kesto | 1835 |

Surffari source **162** selects **1686** on both days. Full target validation rejects **163**,
which omitted the active campaign. The unchanged exact payload supplies the 0.20 c/kWh margin
through August 31 and 0.60 from September 1. Tests also put the campaign-omitting output at the
newest completion: it is rejected in July, accepted after expiry, and never supplies September
pricing early. Tests reject an unsupported inserted September 2 date.

## Counts and prices

Counts are contract-consumption-date pairs, not distinct contracts.

| Date | Evidence | Available | Unavailable | Stored v2 available | Lost v2 | New v2 |
|---|---:|---:|---:|---:|---:|---:|
| Jul 22 | 1,305 | 888 | 417 | 882 | 0 | 6 |
| Jul 23 | 1,317 | 874 | 443 | 893 | 22 | 3 |
| Jul 24 | 1,275 | 862 | 413 | 867 | 9 | 4 |
| Total | 3,897 | 2,624 | 1,273 | 2,642 | 31 | 13 |

Against the initial failed v3 preview, July 22 is identical. July 23 gains 22 pairs and loses
nine; July 24 gains seven and loses three. Matched changed totals are 53 and 98 respectively
(absolute difference above EUR 0.0001). These are method/proof changes, not proven seller repricing.

At 5,000 kWh, OpenEnded medians are **633.7024 / 661.4460 / 749.5891 EUR** with **61/57/56**
contributors. Hybrid medians are **506.0102 / 511.7087 / 511.8000**, with **38/40/38** contributors.
Quarterly medians are **581.8204 / 593.8174 / 597.5108**, with **13/13/13** contributors.
All 90 writer aggregates, including the 27 requested segment/date/consumption combinations,
are retained with contributor-only method counts. No global unavailable-method count is used
as a dominant contributor count.

## Concrete remaining limits

On July 23/24, **47/47 contracts** have at least one target-rejected candidate; **11/13** have
no accepted source reconstruction after those rejections. Within that group, **10/11** have
unsupported legacy temporal proof. These are processing/proof limits, not claims that seller
terms did not exist. Exact-date relational fallback remains explicit where it is safe.

Examples on July 23:
- Sources **59/353** (Kokkola Vuodenaika) give recurring day/month windows without an explicit
  year in the schedule citations. The legacy validator cannot prove the inserted 2026 window.
- Source **60** (Tyyni) has inferred monthly calendar windows; source **280** (Cheap Kvartaali)
  has a prose-derived first-month promotion and quarterly end. No unsupported date is guessed.
- Sources **8, 37, 181, 336** have relative promotion periods without the required scoped
  structured timing proof; source **25** has an 18-month text term outside the bounded source
  term support. Source **69** has a 24-month output for a 12-month-named product and no accepted
  full target proof. No contract-specific exception or new fact was added.

The remaining release checks are: review these proof/coverage losses, rerun the complete history
under the repaired rule, review all post-May transitions, prepare the v3 current producer and
verify identical dated evidence parity, obtain a verified full backup, then obtain separate
apply and active-method approval. V5, dated replacement trust and older absent donors remain
unsupported. The command warning now lists real release checks instead of claiming that anchors
and premiums are not implemented.

## Verification and preservation

- Focused final related suite: **357 tests / 3,012 assertions**, all passed. Filter includes
  exact-source validation, AsOf calculator/premium/anchor/historical/writer/command/persistence,
  current parity, ForwardPremium/CurrentPremium/SupplierAdjusted/MarketReset, canonical calculator,
  promotion, interpretation pipeline and profile tests. New real-validator tests cover registered
  legacy profiles, active/expired campaigns, dates, adjacent completions, new sources, recurrence,
  ambiguity and provenance. Older partial parser-fixture calculation tests explicitly mock only
  full validation; they are not source-proof tests.
- Final `cd laravel && php artisan test`: **2,871 tests / 21,815 assertions**, all passed in
  146.68 seconds. The earlier full run also passed; initial focused fixture failures were fixed.
- Explicit Pint on changed PHP, `vendor/bin/pint --dirty --test`, the new-file Pint test and
  `git diff --check` passed. No CSS/JS changed, so no frontend build was required.
- Reviewed `/tmp/annual-v3-full-preview/run.php` before reuse. New runner:
  `/tmp/annual-v3-continuity-final/run.php`, invoked separately for each date with 512 MiB PHP.
  It runs the existing command with explicit v3/v2, captures the actual calculator and writer
  preview, pins uncached SQLite/array cache, and enables `PRAGMA query_only=ON` before queries.
  Runtime was **20.24/18.21/21.62 seconds**, peak PHP allocated memory at most **128.66 MiB**.
- Active method in every proof remains v2. Source database and both isolated snapshot files
  retain their whole-file SHA-256. Resolver hash before/after all dates is
  `9334ed475fa6c30225e67537d473e29cf35fbe32dc6b5136eec77a56b070037a`.
  All 16 original failed-preview artifact hashes still match their original manifest.
- New files `v3-continuity-repair-{aggregates,counts,selections,runs,proofs,artifacts}.csv` retain
  the bounded diagnostics and hashes. Raw JSON/logs and diagnostic scripts remain separately
  in `/tmp/annual-v3-continuity-final/`. Earlier experimental local runs remain in their separate
  `/tmp/annual-v3-latest*-preview/` folders and are not the final evidence.

No production access or mutation, database apply, active-method change, migration, dependency,
commit, push, deployment or initial-artifact replacement occurred. Prior uncommitted work remains.
