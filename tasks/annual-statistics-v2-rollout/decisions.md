# Production rollout state

## Completed release
- Commit: `b47eea569be5da19d2d0493ccd571d969fbc7972`, pushed with approved `git push origin main`.
- Deployment: `7082d71a-8c52-4abd-8c44-199005778b59`, exact commit matched with Railway MCP; repository poll script reported SUCCESS.
- Final release tests: 2,276 passed / 10,522 assertions, 91.38 seconds. Vite build passed. Logs: `/tmp/annual-pricing-release-tests.log`, `/tmp/annual-pricing-release-build.log`.
- Production About and statistics pages returned HTTP 200. New plain-language methodology and `#menetelma` were verified. Production generated CSS `app-Xoj990xo.css`, different from the local CSS hash; its manifest reference and HTTP asset were verified. JS `app-Pr_ebGQi.js` matches the local build. The local CSS-hash assertion failed and was replaced by verification against the actual production manifest; the deployment itself succeeded.

## Read-only production inspection
At Helsinki `2026-09-11T21:45:11+03:00`:
- Deployed commit matches the release; canonical pricing enabled; active public annual method `annual_cost_as_of_v1`.
- V1 annual rows: 196,256; v1 annual aggregates: 6,639. Both cover 2026-01-21 through 2026-09-11.
- Legacy annual aggregates: 7,710. Snapshots: 75,413; source components: 239,811.
- No v2 annual rows existed yet. Current collection will write v2 on its next run.
- Inspection used explicit Railway IDs, an asserted MySQL connection, a read-only repeatable-read transaction, array cache, and rollback. No secret values were printed.
- Evidence: `/tmp/annual-v2-production-inspect.php` and `.log`.

## First historical preview (complete; candidate not approved)
- Historical command permits past dates only. The reviewed read-only range is 2026-01-21 through 2026-09-10, 232 available evidence dates. The current 2026-09-11 date is deliberately outside it. Do not move the clock or bypass that guard.
- First full-range attempt hit its explicit remote 1,200-second time limit (exit 124). It completed 79 dates through 2026-04-10. The log ends with that date's median summary; no calculation error was printed. This was an incomplete preview, not a successful complete run, and it made no writes.
- First log: `/tmp/annual-v2-production-preview.log`; PHP script: `/tmp/annual-v2-production-preview.php`.
- Remaining dates were run sequentially in bounded batches: April 11–30, May, June, July, August, September 1–10. Each used a fresh read-only transaction and a 1,200-second remote timeout. No parallel calculation processes were used. All six batches completed.
- Background job `job-91747-101` (`annual-v2-remaining-readonly-batches`) completed all six remaining batches successfully in 34m10s. Runner: `/tmp/annual-v2-preview-remaining.py`. Each batch writes `/tmp/annual-v2-production-preview-<FROM>-<TO>.log`. Combined coverage is all 232 available dates, with no duplicates; February 12 has no evidence.
- All preview sessions assert the exact deployed SHA, v1 active method, canonical mode, and MySQL read-only setting. They call the existing command without `--apply`. No historical apply or public activation has run.
- CLI telemetry session: `voltikka-annual-pricing-release-20260912-1`, caller `skill:use-railway@1.2.2`.

## Review findings and required next steps
- See `preview-b47eea5.md` for full counts, bounded outlier/loss review, and source logs. No v2 rows have been written; public v1 remains active.
- The outlier review confirmed a quarterly seasonal-reference mismatch: the last month's index represents a whole-quarter seller price. Prepare a narrow day-weighted quarter reference correction, keep monthly behavior and beta unchanged, and increment the deployed calculation-cache schema to 17. The user was told historical apply is paused while the correction is prepared.
- Implementation agent `1353a134-69c8-432` completed the narrow correction and `seasonal-reference-fix.md`. Parent reviewed the actual helper and regressions. Full suite: 2,283 passed / 10,830 assertions (91.16 seconds); production asset build and `git diff --check` passed. Logs: `/tmp/annual-v2-quarter-reference-tests.log` and `/tmp/annual-v2-quarter-reference-build.log`.
- A read-only local check gives a revised June 1 quarterly/5,000-kWh median of €724.74, versus €1,034.85 in the first production candidate and €469.70 in stored v1. Local and production results are not interchangeable; the corrected production range still needs a fresh preview.
- The user approved the second code release: commit and push `main` with `git push origin main` to Railway Voltikka / production / voltikka, deploying the quarter-reference fix and cache schema 17. This keeps public v1 active and does not authorize a historical apply or method switch. A fresh complete production preview follows successful deployment.
- After release approval and successful deployment, rerun a complete preview. Old output cannot serve as the corrected apply manifest. Re-check the real Helsinki date, latest v1/v2 dates, and current coverage rather than assuming September 11 is still today.

## General review requirements
- Merge only completed date summaries, ensure no duplicate or omitted available evidence dates, and report partial coverage if any batch fails.
- Check new/lost contract-consumption identities, aggregate coverage, unavailable reasons, and significant deltas. The first portion includes a maximum matched aggregate median increase of about €1,799 on 2026-04-08; this is across multiple consumption levels, not a 5,000-kWh claim. Inspect the segment and estimation basis before accepting it.
- The old local sample is not a substitute for the production preview. Its zero-placeholder Hybrid regressions are already fixed in the release.
- Resolve today's missing v2 coverage before activation. V1 currently includes September 11, while historical command execution on September 11 stops at September 10. Do not call a dated v2 endpoint today's comparison or overwrite v1 snapshot provenance merely to advance it.
- State exact project/environment/service, apply command and date range, expected changes, and backup/activation boundaries; wait for affirmative approval before production writes.
