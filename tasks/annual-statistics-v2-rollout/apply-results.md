# Historical v2 application completed — 2026-09-12

## Result

The approved historical application is complete on Railway **Voltikka / production / voltikka**, using deployed commit `f6518498c2b7598c87bd79e62855c18afba63c16` and calculated-cost schema 17.

- 233 evidence dates: January 21 through September 11. February 12 has no evidence date.
- 213,755 stored v2 annual rows and 7,618 v2 annual aggregates.
- All date-level row counts match the corrected preview exactly.
- All protected baseline hashes match: retained v1 annual rows/aggregates, historical non-v2 statistics, historical snapshots, and historical price components. September 11 v1 and its snapshot joins remain intact.
- At this historical-completion checkpoint, public `annual_cost_as_of_v1` remained active. No activation, manual current overwrite, or further code deployment had occurred. The later approved activation is recorded in `activation-results.md`.

## Execution and recovery

January–April, June, August, and September have complete review/apply/batch markers and post-batch protected fingerprint checks. Two SSH failures did not stop the active remote PHP process. Neither caused a duplicate apply:

- May: wait for exact remote process exit, then verify all stored May dates and independently recalculate/check every non-timestamp field for May 31.
- July: wait for exact remote process exit, then recover all 31 review/apply markers and the final PHP batch marker from the server log. The disconnected parent shell did not append its exit marker. Post-July fingerprints passed.

The final two batches streamed through foreground GNU tee and retained server logs. Job `job-55742-162` completed in 11m32s with both PHP and tee exit 0 and matching protected fingerprints.

## Independent final checks

- `job-55742-167`: MySQL read-only verification at **09:37:57 Europe/Helsinki**. All 233 dates passed aggregate identities, contributor counts, reviewed medians, row-derived medians, estimate-method counts, and exact stored lost-identity sets. The final September 11 date was recalculated; its complete non-timestamp annual and aggregate fields match the writer preparation. All 213,755 rows and 7,618 aggregates are accounted for.
- The first full-range diagnostic exceeded the SSH argument limit before PHP ran. The corrected diagnostic projects the same fields used by `av2Review`, without weakening comparisons; its command is 116,179 bytes. No database write occurred in either diagnostic.
- `job-55742-164`: current September 12 v2 validation passed: 789 rows, 272 snapshot contracts, 30 matching aggregates, zero missing/mismatched provenance, zero current observation/publication pointer mismatches, and zero other issues. Reference counts are 268 / 262 / 259 at 2,000 / 5,000 / 18,000 kWh. The 27 null snapshot masks remain excluded.
- `job-55742-166`: candidate public statistics preparation passed with **process-local v2 selection only**, array cache, and a read-only database transaction. Annual data loaded v2 only. Annual and unit endpoints both resolve to September 12, with no retained-date fallback; seven consumption rows render. Peak memory was 68.5 MiB under a 128 MiB limit. This did not change the public environment or durable cache.
- Public `/sahkosopimus/tilastot` returned HTTP 200 while v1 remained active.

## Evidence files

- Final protected facts: `/tmp/annual-v2-final-2026-09-01-2026-09-11.log.protected.json`
- Historical verification: `/tmp/annual-v2-final-history-verification.json`, `/tmp/annual-v2-final-history-verification-compact.php`, `.log`
- Current verification: `/tmp/annual-v2-final-current-verification.php`, `.json`, `.log`
- Candidate reader: `/tmp/annual-v2-candidate-reader-check.php`, `.json`, `.log`
- Final public HTML: `/tmp/annual-v2-post-apply-public-v1.html`
- May/July recovery details and all job IDs: `decisions.md`

The preview checks do not claim a hash of every available candidate row or every input. The backup statement remains operator-confirmed restore access plus agent-checked object metadata, not an independently tested restore.

## Later activation — approved and executed

The user subsequently approved this exact Railway MCP `railway_set_variables` operation:

- Project `6d8cae01-1006-409f-8108-1d51f1abc676`
- Environment `9245cef8-41d0-486e-862f-193726511dba`
- Service `700d0624-fa96-4266-876c-e37640d220ea`
- Variables: `{"CONTRACT_STATISTICS_ANNUAL_METHOD_VERSION":"annual_cost_as_of_v2"}`
- `skip_deploys: false`

Expected effect: redeploy the app with v2 selected for public annual-statistics readers. It does not rerun historical calculations or change the canonical list-pricing flags. Retained v1 stays available for a separately approved switchback; it is dated retained data, not restarted current v1 calculation.

Fresh current data passed before the operation. Deployment `bcb5a9e7-2b88-4841-a97c-08708d990eb9` succeeded, and cached public v2, protected hashes, core readers, and unit/index isolation passed. The later CSV timeout was resolved by the separately approved release `722582c` and successful deployment `6ce82497-7275-4402-babd-dcbad23127e1`: the complete public export passes with 28,819 rows in 6.811 seconds. See `activation-results.md`. Do not issue an automatic rollback or further production mutation without approval.
