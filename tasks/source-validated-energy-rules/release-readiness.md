# Deployment readiness — renewed local gate, 2026-09-16

## Current status and authority

**USER DEPLOYMENT APPROVAL RECEIVED; DEPLOYMENT NOT YET PERFORMED.** The user replied
**“Approved”** to the manager's request to commit the reviewed changes on `main` and run
`git push origin main`. This authorizes the normal Git auto-deploy to Voltikka/production/voltikka,
which changes live comparison prices, and read-only deployment/live checks. Technical readiness
and its limits below are unchanged. Release SHA, deployment ID and live results remain pending
manager execution. See `production-release.md` for the exact target and exclusions.

This approval supersedes only the earlier pending commit/push/deployment authority statements.
It does not authorize V5 producer activation or reinterpretation, manual imports, cache flushes,
history writes, restarts, rollbacks or video posting. The dated readiness record below is preserved
as historical evidence, including its pre-approval authority statements and status snapshot.

## Historical status and authority — before user approval

**DEFAULT-V4 CODE IS TECHNICALLY READY FOR USER DEPLOYMENT APPROVAL.** All final technical checks passed. The manager accepts this bounded release scope. No runtime blockers remain. Runtime repairs and the CardPresenter premium metadata fixture repair are complete; the final combined PHP gate passed. Production has not received these fixes.

The manager accepts the reviewed 27 numeric changes as conforming to the approved comparison policy, not as empirically better forecasts. The manager also accepts the reviewed video template/code, including brief crossfade text overlap. Neither decision authorizes commit, push, deployment, V5 activation, provider calls, production writes, active SQLite replacement, historical rebuild or video posting.

This record supersedes the pending amount, replay, hash, estimator and video review statements in earlier records. Original failed reports and dated test results remain historical evidence. All results below are supplied by the manager or linked unit reports, not new test or production runs by the documentation executor.

## Independent financial review

Independent Python review `57b61` verified **81 totals: 27 contracts × 2000/5000/18000 kWh**, with maximum difference **2.14e-11 EUR**. It checked **37 anchors and 39 source rates/fees**: **19 market-premium, 7 own-reference and 1 Fixed6** routes. Donor comparability, VAT, chronology, fees and real benefits match the approved policy. No tuning was done.

The Lammaisten July 24 unknown restart remains correct evidence handling. The maximum 5000-kWh change, **+509.40684199 EUR**, is explained proxy uncertainty, not demonstrated forecast improvement. Evidence: `/tmp/energy-independent-verification.json`, `/tmp/energy-independent-anchors.json` and the associated independent scripts.

## Fresh separate read-only evidence

- Export `/tmp/voltikka-energy-release-20260916-b`: complete at **16:14:41 UTC**, **375 active / 2193 scoped contracts**, **1744 Spot averages / 28365 Spot hours**.
- Sealed database SHA-256: `e8d8caa455e391024207df8d0ac38db63c83bc848ae358a89333a1860370625d`.
- Production remains source `917de212fdc4ef4862903268c3d33ba5cd886e73`, deployment `13bfd4fa-c06f-4a27-b7a7-7e935823d903`, status `SUCCESS`.
- Cached config read without the Laravel kernel is unchanged: V4/v19/v17, parser-v1, canonical/reset enabled, beta 1, annual-v2. No V5 data. Config evidence: `/tmp/voltikka-energy-release-production-config.json`.
- The original `-a` export, snapshot and reports remain unchanged. No active SQLite replacement or historical rebuild occurred.

## Final fresh transport and comparison evidence

Files under the new export: `transport-baseline.json`, `transport-candidate.json`, `release-comparison.json`. Report CLI `--require-candidate-transport` exited **0**.

Every candidate full payload, including excluded outcomes, passed the actual selected-tree public reader **before prose removal**: **375/375 in all four phases** (5000 cold/warm, 2000 and 18000). `candidate_transport_ready=true`; cold/warm business rows are identical. The baseline has 374 valid payloads and one pre-existing Kerava reader failure.

Candidate: **366 listed**, baseline: **368 listed**. There are **29 changed total/state records** at each consumption: the independently reviewed 27 numeric changes, Hehku's insufficient promotion exclusion, and Kerava `bdfd4u`. Kerava has fixed energy **12.8 c/kWh** and Spot margin **0.55 c/kWh** in the same billed phase. Current now excludes this ambiguous mechanism instead of overwriting fixed energy with Spot. Factual period pricing returns `NoPricing`; Historical is unchanged. Strictly proved disjoint Hybrid→Spot phases remain valid.

The manager compared **all financial totals** with the independently reviewed older candidate. Only the new Kerava exclusion differs, at all three consumptions. The two benefit transport changes remain Vaasa (real-term **5.90 EUR** unchanged; annual equivalent **11.80 EUR**) and Hehku (unavailable). Aalto **5.95 EUR** and SUPERDIILI **17.80 EUR** are unchanged.

## Engine measurements and limits

| Phase | Engine time | SQL count | SQL time |
| --- | ---: | ---: | ---: |
| Baseline 5000 cold | 1571.92775 ms | 23 | 7.28 ms |
| Candidate 5000 cold | 3567.676417 ms | 33 | 55.13 ms |
| Candidate 5000 warm | 1889.475959 ms | 1 | 2.87 ms |
| Candidate 2000 | 1901.1075 ms | 1 | 3.07 ms |
| Candidate 18000 | 1901.260875 ms | 1 | 3.20 ms |

Candidate cold peak: **50,855,936 bytes**. These are local outcome-engine measurements, not HTTP, production or cache-hit latency. Strict transport validation is outside each phase's engine timer. Fresh `ANALYZE` resolves the earlier approximately 50-second SQLite statistics artifact; it does not establish production performance.

## Completed release constraints

- `estimator-release-checks.md`, including manager corrections: Current finite/prior-vintage/date guards, effective hold beta 0, full selected-profile Supplier seasonal weight, and an actually billed model-floor flag. Flag serialization is repaired. Historical is preserved. The reset switch stays reset-only; legitimate negative Spot remains allowed.
- Known-short ambiguous `Below6`/`Between711` fail closed. Fee-only short terms need in-term energy proof; future energy cannot fill that gap. Kerava's same-phase ambiguity is excluded without banning disjoint fixed→Spot timelines.
- `premium-public-privacy.md`, including addendum: public output omits private donors/provenance, and the strict reader rejects cached private/unknown premium fields. `strict-transport-release.md` defines the full-payload gate used above.
- `seo-release-copy.md` and `long-term-qualifier-release.md`: FixedPrice/exact SQL remains, but it proves no constant whole-contract price. Long-term/default-card copy makes no such promise. Source facts and supported guarantee notices remain.
- `retained-assets.md`: retain old CSS for cached HTML. Later changed JS/imports need their own retention. `/.cache/` is ignored, not deleted. No package changes.

## Full synthetic video and final UI gate

`video-release-fixes.md` records `/tmp/voltikka-weekly-release-fixes-final`: all **750 frames / 25 seconds**, H.264 **1080×1920**, decoded; all fonts loaded; zero near-white blank-detector hits; zero text overflow findings in **360 settled frames**. Chrome **360×640** playback ended with **0 reported dropped frames**. Financial qualifiers are **36 source pixels**. The agent verified source hashes.

The manager read the actual source, boundary sheets 255/615/690 and settled Hybrid frame 505. Brief crossfade text overlap is accepted as a transition, **not zero overlap**. The title has no frame-zero reset. No real-feed, provider/logo, social-compression, physical-phone or posting approval is claimed. These explicit limits do not block this reviewed template/code release; no actual video post is authorized.

Manager UI job **228 passed**: JavaScript **12 tests**, Laravel build, Remotion lint/TypeScript/build. Retained CSS source/output are **110597 bytes**, SHA-256 `e27279f29da59bdfc18093c71ee05e6d08d237bd673e9b618a58f4f9e5335528`. Logs: `/tmp/voltikka-release-final-{js,assets,remotion-lint,remotion-build}.log`.

## Final combined gate and historical pending approval

Manager job **235 passed**, with network denied:

- `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-release-no-config php artisan test`: **2812 tests / 21214 assertions / 106.56 seconds**. Log: `/tmp/voltikka-release-final-php.log`.
- Preflight smoke and export smoke: **PASS**. Logs: `/tmp/voltikka-release-final-{preflight-smoke,export-smoke}.log`.
- The final qualifier fixture check passed **232 tests / 1134 assertions**, with financial assertions unchanged; see `long-term-qualifier-release.md`.

UI job 228 logs confirm **12 JS tests / 0 failures**, Laravel build **785 ms** (`app-CXFUrNKw.css`, **115.46 kB**), and Remotion ESLint/TypeScript/build **PASS**. Retained old CSS bytes match. Only nonblocking Browserslist and Node deprecation warnings remain. No runtime code writers remain.

Final manager check reference: **job 237**. Pint passed for **140 changed/new Laravel PHP files** and **all five preflight PHP files**; `git diff --check` passed. The manager then verified **22 changed/new AGENTS/CLAUDE pairs** as byte-equal, both task JSON files as valid, and whitespace as clean. All **84 loaded pricing-source hashes** from the final replay match the current files. Both sealed SQLite hashes remain unchanged (`-a`: `2e40935…`; `-b`: `e8d8caa…`). V4 schema/prompt bytes match HEAD; the current producer config prefix is unchanged. Historical pins are intentional. No dependencies, migrations or console schedule changed. Branch is `main`, the index is empty, and the manager's status snapshot has **173 entries**. No commit or push occurred.

The manager accepts technical readiness for the default-V4 code with no remaining technical conditions. No full HTTP, live-cache or ranking observation was replayed. Local engine/transport evidence does not prove deployed behavior.

Commit/push/deployment still require separate explicit user approval; none occurred. V5 coordinated five-key activation, pilot/reinterpretation, real producer accuracy and production tasks remain separate, unapproved and outside this default-V4 readiness. See `release-plan.md` for profile and rollback limits. No historical rebuild, active SQLite replacement or actual video posting is authorized.
