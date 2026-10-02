# Investigation evidence

## Local read-only calculation

Research agent used local SQLite in read-only mode with query_only, array cache and temporary instrumentation outside the repository. No network, production access or database writes. Local snapshot: 378 active contracts, observations through September 21, futures through September 18. Comparison date September 22, both pricing flags enabled. Local PHP 8.5 is not production PHP 8.4.

Fresh-process full metrics builds took 5.026 s at 2,000 kWh and 5.001 s at 20,000 kWh. 39 SQL queries took about 1.03 s. Inclusive instrumented times overlap: 1,869 timeline builds about 2.4 s; two episode-resolution calls about 2.35 s; 378 annual calls about 1.84 s; premium loader about 1.25 s. Premium selection: 19 calls about 0.0025 s; 1,060 evidenceKey calls about 0.0014 s; public premium serialization about 0.0002 s. Later consumption passes on the same service took about 2.1 s.

This refutes premium serialization as the main local bottleneck. It does not reproduce the production timeout or prove production cause.

## Production platform reads

Railway CLI used explicit Voltikka production IDs, required caller and stable session `voltikka-sentry-150596689`. Read-only summary interval: 2026-10-01 03:00–03:20 UTC.

- App CPU average 0.458 vCPU, maximum 2.245; reported limit 32 vCPU.
- App memory maximum 2,409.6 MB; reported limit 32,768 MB.
- MySQL CPU average 0.071 vCPU, maximum 0.268; reported limit 32 vCPU.
- MySQL memory maximum 3,883.3 MB; reported limit 32,768 MB.
- HTTP summary: 97 requests, 71 2xx, 7 3xx, 12 4xx, 7 5xx (7.22%). CLI latency summary gave the same 255 ms value for all percentiles; do not use this to characterize timeout latency.
- Deployment summary identified successful deployment c1a933ac-8e20-415f-a48f-d18690953d56, created September 22.
- Bounded app and MySQL runtime log reads for this interval returned no lines. This is missing diagnostic evidence, not proof of no errors.

These coarse platform summaries do not show configured CPU or memory limit exhaustion. They cannot establish per-request CPU time, shared-host performance, query stalls, cache invalidation writer identity or scheduler execution.

## Cache churn

The supplied first event uses v752 at 03:06:17; the second uses v774 at 03:11:58. Difference: 22 versions in 341 seconds. The code schedules the full import at 06:00 Europe/Helsinki (03:00 UTC on October 1). Every successful current interpretation publication invalidates the shared generation immediately; EEX completion also invalidates it. This is consistent with morning publication churn, not verified production writer attribution. Keep source-publication safety checks unchanged.
