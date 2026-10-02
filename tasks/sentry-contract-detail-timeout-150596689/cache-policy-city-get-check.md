# Manager full-kernel cached city GET check

## Method

Used a copy of the earlier read-only HTTP-kernel harness in `/tmp/voltikka-retained-get-check/`. Added an explicit private producer refresh before request timing, and then reset instrumentation so producer work was not counted as GET work. The Lapinjarvi GET followed that producer in the same process. Mikkeli ran in a fresh PHP process using the same isolated prepared file cache. The safe CLI bootstrap precedes request timing.

The harness preserves SQLite mode=ro plus query_only, temporary cache/lock/view/manifests/session paths, disabled Sentry/log network transport, `Http::preventStrayRequests()` and PHP allow_url_fopen=0. No production requests or writes were made. September 22 comparison date uses the older 378-contract local snapshot and PHP 8.5.11, not production PHP/MySQL. No browser/socket/assets are included. This is one check per city, not a distribution or production latency claim.

## Results

| City | HTTP | Kernel handle+terminate | SQL queries | SQL time | Local cards |
|---|---|---|---|---|---|
| Lapinjarvi | 200 | 0.211 s | 33 | 0.089 s | 58 |
| Mikkeli | 200 | 0.079 s | 16 | 0.013 s | 8 |

Both requests recorded:

- Zero `buildCachedMetrics`, `metricsForContracts`, `evaluate`, broad premium loader, supplier episode resolver, timeline builder or anchor-preparation calls.
- Zero futures, interpretation or immutable source snapshot SQL queries.
- SQLite query_only=1 and no recorded request errors.
- Complete local-card sections, not only the first ten visible browser cards.

Displayed price comparison against the earlier captures extracted ordered euro and c/kWh strings from visible HTML text (excluding script/style). Lapinjarvi had 266 old/new strings, Mikkeli 112, with exact ordered equality in both cases. This is rendered-value evidence, not a full-column provenance or full-HTML comparison. The separate shared-read tests cover retained financial/provenance payload equality.

Artifacts: `/tmp/voltikka-retained-get-check/request.php`, `instrument.php`, and `shared-cache/{lapinjarvi,mikkeli}.{json,html}`. Background command log: `/tmp/pi-bg/job-57171-41.log`. Old rendered-price captures: `/tmp/voltikka-city-http-bench/initial/lapinjarvi.html` and `/tmp/voltikka-city-http-bench/current-seq-1-mikkeli/mikkeli.html`.

Regression integration and final full-suite gate remain pending. This successful request check does not resolve the initially unexplained earlier concurrent HTTP 500s or prove production timeout elimination.
