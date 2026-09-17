# Final verification

## Current release gate — default-V4 technically ready for user deployment approval, 2026-09-16

See [release-readiness.md](release-readiness.md) for current evidence and limits. Manager job 235
passed with network denied: `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-release-no-config php artisan test`,
**2812 tests / 21214 assertions / 106.56 seconds** (`/tmp/voltikka-release-final-php.log`). Preflight
smoke and export smoke passed; logs `/tmp/voltikka-release-final-{preflight-smoke,export-smoke}.log`.
Runtime and fixture repairs are complete. The manager accepts all 27 independently checked numeric
changes as policy-conformant, not better forecasts.
The fresh candidate passes all 375 full public payloads in all four phases before prose removal;
366 are listed, with 29 changed total/states including the new Kerava ambiguity exclusion.
Final UI job 228 passes (12 JS tests, Laravel build, Remotion lint/TypeScript/build). Full synthetic
video/template review is accepted with brief transition overlap and the stated real-feed/device
limits. Laravel build took 785 ms; only nonblocking Browserslist/Node deprecation warnings remain.
The manager accepts default-V4 technical readiness with no remaining technical conditions.
Job 237: Pint passed for 140 changed/new Laravel PHP files and all five preflight PHP files;
`git diff --check` passed. Subsequent manager checks passed: 22 context pairs, both task JSONs,
whitespace, all 84 loaded-source hashes and both unchanged sealed SQLite hashes. V4 assets match
HEAD; current producer config is unchanged. See `release-readiness.md` for complete results. No runtime blockers remain. User deployment approval
is still required; no commit/push/deploy occurred. Full HTTP/live-cache/ranking behavior was not
replayed, and active production is not fixed by these local checks. V5 activation/pilot and real
producer accuracy remain separate, unapproved and outside this readiness.

The following 2773-test and earlier records are dated historical evidence. Their then-pending
amount/video findings are superseded by `release-readiness.md`; they do not verify the final tree.

## Historical release gate — BLOCKED after earlier local repairs, 2026-09-16

See [audit-fixes.md](audit-fixes.md) for consolidated current evidence. NULL-reader, public-copy
and estimate-metadata repairs are complete locally. Manager combined verification: 2766 tests /
18151 assertions / 144.10 seconds; Laravel build and Remotion lint/TypeScript/build exit 0.
The intermediate sealed replay and query diagnosis are complete. Further review proved two
anchor defects (legacy no-discount evidence and missing overlap-end events). Both repairs now pass
46-test focused and 316-test related runs. The short-term qualifier repair passes 183 tests;
export cleanup and bounded video repair/12-PNG QA are complete. See `audit-fixes.md`.
Final manager gate passed: **2773 tests / 18253 assertions / 138.45 seconds**, Laravel build and
latest Remotion lint/TypeScript/build. Logs: `/tmp/voltikka-audit-final-{full,build,remotion-lint,remotion-build}.log`.
The final same-sealed-database replay has 375 rows per consumption, identical cold/warm business
rows, 367 listed and 28 changed displayed states. Broken anchors are resolved; remaining premium
and unknown-restart amounts are not accepted as improvements. See `audit-fixes.md` for exact
replay files, deltas and performance limits. The corrected `audit-fixes-reviewed-comparison.json`
uses the same final candidate and has 28 displayed-state changes and 2 benefit transport changes
at each consumption. The cent-comparison correction passes 14 smoke cases and retains raw amounts.
Manager Pint passed all 131 changed/untracked Laravel PHP files (`/tmp/pi-bg/job-15523-182.log`). Economic acceptance and remaining policy/estimator disclosure review stay open. Bounded video
QA is not real-feed, phone/compression or full-video verification. This is not deployment or V5 activation approval. The original failed
preflight and all earlier acceptance results below remain factual historical evidence.

## Historical manager acceptance — completed local implementation

The manager accepts the completed source-backed financial integration and local PHP/public-output
behavior after the schema-19 boundary. Review covered actual service proof gating, normal target/
anchor wiring, offer helpers, strict public transport, source-language additions and financial test
cases. This is LOCAL acceptance, not production acceptance, deployment or V5 producer activation.

### Fresh combined gate after schema 19

These manager-run results were checked from the local logs, not rerun by this documentation unit.
Commands ran from `laravel/`.

| Check | Result | Log |
| --- | --- | --- |
| `php artisan test` | 2754 passed; 17909 assertions; 135.13 seconds | `/tmp/voltikka-energy-complete-full.log` |
| `npm run build` | Passed; 836 ms; existing old caniuse warning | `/tmp/voltikka-energy-complete-build.log` |
| `vendor/bin/pint --test` on ALL 122 changed/untracked PHP files | Passed | `/tmp/voltikka-energy-complete-pint.log` |

The manager also checked Git diff whitespace, all 19 changed AGENTS/CLAUDE pairs, three task JSON
files, unchanged V4/V19 assets and canonical config: all passed. Final manager status snapshot:
`/tmp/voltikka-energy-complete-status.txt`. Prior bounded-stage results below remain historical.

### Scope and remaining release checks

All four current service paths use exact V5 proof before parser opt-in. Normal evidence and
premiums are connected; actual-only short-term transport and `energy_price_guaranteed` are complete.
Real Cheap first-month proof works. Oomi, Voima, Iin, Tyyni and Hehku remain Unknown for material
source limits. A later announced 11 holds 11 without a valid new reference, never old 9. This is
not a claim that the source grammar supports every sentence.

**Remotion toolchain verification is complete; visual verification remains pending.** After user
approval, the manager ran `npm ci --no-audit --no-fund` in `remotion/` (389 packages), then
`npm run lint` (ESLint and TypeScript passed) and `npm run build` (bundle passed at `remotion/build`).
Logs: `/tmp/voltikka-remotion-install.log`, `/tmp/voltikka-remotion-lint.log`, and
`/tmp/voltikka-remotion-build.log`. SHA-256 verification against
`/tmp/voltikka-remotion-manifests-before.sha256` confirmed unchanged package manifests. npm blocked
esbuild's optional postinstall; both checks passed without script approval. No application code
changed or production access occurred. No visual render was done. This documentation unit checked
the logs, not rerun the commands. The earlier offline locked-zod failure and syntax-only checks
remain historical evidence in `video-output-check.md`, not a current dependency blocker.

Production is unchanged; no commit/push occurred. Defaults remain V4/v19/v17/parser-v1. Deploying
this staged V4 tree does not activate V5 production. The separately approved fresh production-data preflight is now complete but FAILED the release
gate; see above. The coordinated five-key current-profile switch, V5 pilot, fresh activation tests
and reinterpretation still require separate approval. No V5 producer or price coverage was measured. Historical stays pinned; annual-v2 and beta
are unchanged. Schema 19 regenerates calculated-cost caches, not statistics/history.
See `release-plan.md` for approvals and compatible rollback limits. No production readiness claim
is made by this local acceptance.

## Historical bounded-stage record — superseded status

The following record retains the prior scope, results and then-open work. Its pending/inactive
statements do not describe the current implementation.

## Earlier acceptance

The manager accepts the bounded local source review, source hardening, paired kernel corrections
and inactive normal-episode evidence. This is **not full-policy completion or activation**.
The user approved local source-backed fixed-price and adjustable-discount semantics. The user did
not approve inferred guarantees from phase dates, normal amounts or incomplete terms.

## Accepted scope

- Name and marketing/contact context bypasses are closed. Adjustable applicability is stable;
  observation dates are separate and do not imply price guarantees or repricing dates.
- The paired kernel checks expiry, current-parent proof, calculation windows and promotion
  coverage. An ordinary future repricing cannot silently restore an old normal rate.
- Fully guaranteed actual prices remain available when the normal comparison is unavailable.
  New actual-only SHORT-TERM serialization deliberately throws under schema 18. Schema 19
  transport must support it before a service can emit it.
- New V5 normal facts require exact source proof (normal 9, not billed 4). Valid legacy ordinary
  actual maps can also contribute; legacy promotions acquire no new normal facts. Trusted lineage,
  fee continuity and true-gap guards apply. Bare raw prices cannot prove normal semantics.
  An unchanged valid pointed publication has no midnight cutoff.
- `CurrentNormalCandidateExtractor` supplies energy evidence only. Its zero-fee placeholder must
  never be billed. It accepts proved `EstimateRequired` current quotes and rejects fixed NORMAL
  donor spans, including future spans. Fixed ACTUAL promotions over adjustable normal rates are
  permitted. The extractor and normal-evidence mode remain unconnected to the current service
  and premium loader.

## Combined verification supplied by the manager

These fresh checks ran on this worktree before this documentation update. They were not rerun
by the documentation executor. Commands ran from `laravel/` unless stated otherwise.

| Check | Result | Log |
| --- | --- | --- |
| `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config php artisan test` | 2707 passed; 17217 assertions; 128.90 seconds | `/tmp/voltikka-energy-stage-full.log` |
| `npm run build` | Passed; 844 ms | `/tmp/voltikka-energy-stage-build.log` |
| `vendor/bin/pint --test` on all 104 changed/untracked PHP files | Passed | `/tmp/voltikka-energy-stage-pint.log` |

The build reported a non-blocking warning: caniuse-lite data is nine months old. No update or
install ran. `git diff --check` passed. All three relevant task JSON files parsed. All 14 changed
AGENTS/CLAUDE pairs passed. A broader scan stopped at the pre-existing missing
`laravel/app/Services/SpotForecasts/CLAUDE.md`; HEAD has AGENTS only. It was left untouched.
This is not a full-repository mirror pass.

V4 schema and V19 prompt have no diff. Defaults remain schema-v4 / prompt-v19 / validator-v17 /
parser-v1; calculated-cost schema remains 18. Beta and annual-v2 config are unchanged. There is
no service parser activation.

## Remaining work and limits

All six historical full-context examples in [real-source-wording-review.md](real-source-wording-review.md)
failed grammar admission. Some contain explicit but qualified or differently expressed guarantees;
others lack sufficient terms. This blocks practical coverage and producer activation claims.
The source grammar is not broadly complete. Do not remove qualifications to make fixtures pass.

[Current integration plan](current-integration-plan.md) remains the work plan, not an activation
record. Its combined local gate is now complete only for the bounded scope above. Remaining work:

- Real full-context language coverage and proof-bound current parser/service/premium integration.
- Own/peer normal references, real fee supply, all current service paths, Reset and tariff/VAT/Hybrid
  cases, expiry and query-count tests. Unknown tails after changing normal references remain unsupported.
- Schema 19 typed public transport, strict round trips, separate actual/comparison certainty,
  signed net benefits, public surfaces and controlled Finnish copy.
- Full-policy regression acceptance, fresh production-data impact and release/cache review.
  Reinterpretation, activation and deployment require separate authorization.

This documentation update changes no code, config or tests. It runs no test suite, build, network,
production operation, commit or push. Older staged decisions and verification remain historical
records; their pending-review and foundation-only statements are superseded by this bounded status.
