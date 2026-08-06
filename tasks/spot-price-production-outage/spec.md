# Production Spot price outage

Investigate why the production header and Spot price page do not have current official Spot data. Identify the cause with read-only production checks. Correct the Finnish unavailable-state copy in both header render paths. Limit the hourly Spot import's orphan overlap lock to 60 minutes, add explicit ENTSO-E connection and request timeouts, and add focused regression tests. Do not mutate or deploy production without explicit confirmation.

## Follow-up freshness check

Add an independent read-only `spot:check-freshness` command. It must compare the latest official FI UTC hour with the current Helsinki hour start. It must write one safe Laravel error log and fail when data is missing or stale. Schedule it at minute 10 of every Helsinki hour on one server, with dedicated output and no overlap mutex. Add focused command and schedule tests.

## Global scheduled workflow failure reporting

Use central Laravel scheduler lifecycle listeners to report every non-zero task exit and thrown task exception through the normal error log. Also report skipped tasks only when `withoutOverlapping()` is active. Keep context safe and structured: task display summary, cron expression, timezone, and applicable exit code/runtime or exception class. Never log command output or exception messages.
