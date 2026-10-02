# Manager verification — initial arithmetic correction

This record covers the initial two-class correction, before the later user-approved retained-cache policy. Final integrated evidence is in `cache-policy-final-verification.md`: 2,953 PHP tests / 24,174 assertions, 12 JavaScript tests, build, formatting and mirror checks pass. Public GET is now cache-only; invalidation records replacement demand while serving retained prices. The original stage evidence below is retained unchanged in scope, not a description of the final cache policy.

The manager reviewed the actual two-class diff and the new regression test. No pricing method, schema, estimator, evidence-selection rule or cache safety boundary changes. Nearest context mirrors are symlinks. `git diff --check` passes. Unrelated pre-existing root/Laravel contexts and annual-history task changes remain outside this task.

## Gates

- Canonical unit and factual bill surfaces: 316 tests / 6,574 assertions pass. Manager checked `/tmp/voltikka-sentry-150596689-pricing-tests.log`.
- Cache safety and request memoization: 27 tests / 147 assertions pass. Manager read the complete `/tmp/voltikka-sentry-150596689-cache-tests.log`.
- Focused Pint passed (executor report); no frontend asset files changed.
- New exact timeline/fraction tests cover January 31, February 29, Helsinki DST, relative and dated phase splits, ties, uncovered intervals, signed durations, null/negative billing denominators, split conservation and date-call counts.

## Full local metric equality

`/tmp/voltikka-150596689-baseline/capture.php` explicitly loads HEAD copies of the two changed classes for baseline runs; current runs load their worktree versions. All other classes are identical. The manager inspected this script: SQLite mode=ro and query_only, isolated array cache, disabled Sentry/log transport, prevented stray Laravel HTTP requests, fixed September 22 comparison date. The local 378-contract dataset is older than the incident and PHP is 8.5.11, not production 8.4.25.

Only the volatile `calculated_at` timestamp is omitted from comparison. All financial, typed evidence, membership/exclusion and metric payload fields retain strict PHP equality at both 2,000 and 20,000 kWh. An initial comparison and three paired repeats passed for both consumptions. Complete metric hashes remain stable:

- 2,000: `0a26018ee928d20430500ee2db3db176b1a33393a9ac5903f87cabf7b5bf6173`
- 20,000: `3b8a01fcdee4c92c910c248b880080b5571c7a1a61cc365b95707a8c8fdd95e7`

## Paired timing

Three fresh-process baseline/current pairs per consumption, with array cache empty each time. All retain 39 SQL queries. Full log: `/tmp/voltikka-sentry-150596689-paired-benchmark.log`.

| kWh | Pass | Baseline wall s | Current wall s | Baseline SQL s | Current SQL s |
|---|---|---|---|---|---|
| 2,000 | 1 | 5.0886 | 2.5603 | 1.0659 | 0.2446 |
| 2,000 | 2 | 4.4076 | 2.5195 | 0.3501 | 0.2276 |
| 2,000 | 3 | 4.9185 | 3.2378 | 0.4666 | 0.6842 |
| 20,000 | 1 | 4.6040 | 2.8544 | 0.5911 | 0.5083 |
| 20,000 | 2 | 4.9099 | 2.7372 | 0.8799 | 0.4100 |
| 20,000 | 3 | 4.4475 | 2.8837 | 0.2721 | 0.5828 |

Median wall time: 2,000, 4.9185→2.5603 s (47.9% reduction); 20,000, 4.6040→2.8544 s (38.0%). After subtracting measured SQL time, median remaining wall time falls about 43% and 42% respectively. Remaining wall time is not a direct CPU measurement. Local timing is not a production latency guarantee.

## Status and limits

Accept the bounded performance-only correction locally. Exact pricing parity means no cache schema bump, annual method switch or historical rebuild is needed for this correction. It is a mitigation, not a proved complete incident fix. Production timeout remains unreproduced; invalidation writer attribution, production-request profiling and after-release verification remain open. No change to publication invalidations or cold-build concurrency was made. Production access in this task was limited to read-only platform summaries and bounded empty log queries documented in investigation.md. No production mutation, commit or push occurred. A release requires explicit approval and the normal Git release checks.
