# City incident platform evidence

User supplied Sentry 150173902 / 143155060, Lapinjarvi and Mikkeli timeouts at 2026-09-30 23:09:07 / 23:09:10 UTC. Same reported container and jscrawler user agent. This is 02:09 Helsinki, before the 06:00 contract import.

Read-only Railway CLI calls used explicit Voltikka production IDs, caller `skill:use-railway@1.2.2` and stable session `voltikka-city-timeouts-150173902`. No production requests or mutations were made.

## Resources, 23:00–23:20 UTC

- App CPU average 1.575 vCPU, maximum 27.097; reported limit 32 vCPU.
- App memory maximum 3,442.2 MB; reported limit 32,768 MB.
- Raw 30-second samples: CPU 16.005 vCPU at 23:08:00, 27.097 at 23:09:00. The 23:08:30 sample is zero; do not interpolate this sparse series into a constant plateau.
- HTTP summary: 871 requests; 486 2xx, 8 3xx, 377 4xx, zero 5xx. The lack of HTTP 5xx does not refute PHP timeouts: many clients disconnected before a response.
- Raw CPU artifact: `/tmp/voltikka-city-timeouts-cpu.json`.

## Complete bounded HTTP interval, 23:07–23:11 UTC

First read used a 300-row cap and hit it. It was replaced with a 1,000-row bounded read, which returned 835 rows, below the cap. Raw private local artifact `/tmp/voltikka-city-timeouts-http.jsonl` can contain client IP/request metadata; do not copy it into the repository or chat. Only aggregate and public-path diagnostics are retained here.

- Requests by minute: 23:07: 77; 23:08: 345; 23:09: 367; 23:10: 46.
- User-agent groups: 822 jscrawler, 13 other. A user agent is not a verified identity.
- Route families: 478 city, 138 contract detail, 219 other.
- City requests covered 308 distinct public pathnames; 162 pathnames were requested at least twice; maximum nine requests for one pathname.
- City status breakdown: 172 HTTP 200 (median duration 1,992 ms), 3 HTTP 404 (median 2,343 ms), 303 HTTP 499 (median 4,990 ms).
- Status: 454 HTTP 200, 45 HTTP 404, 331 HTTP 499, 5 redirects.
- All 331 HTTP 499 records state that the client closed the request before the server could send a response.
- Total-duration raw values range 4–8,480 ms; median 2,367 ms.

Exact example routes:

| Route | HTTP timestamp UTC | Status | Duration ms |
|---|---|---|---|
| Lapinjarvi | 23:08:37.176471637 | 499 | 4,990 |
| Lapinjarvi | 23:08:42.178387799 | 499 | 4,987 |
| Mikkeli | 23:08:44.897393706 | 499 | 4,991 |
| Mikkeli | 23:08:49.897942598 | 499 | 4,984 |

## Interpretation and limits

A crawler burst, widespread five-second cancellation and a coincident 27-vCPU spike strongly support concurrent expensive work. The exact Sentry traces are not joined to Railway HTTP request IDs, so individual request-to-fatal correlation is not proved. No claim is made that all city requests performed full-market builds, that all cancellations left PHP work running, or that the configured CPU limit was exhausted. The shared pricing path and local-section preparation require measurement. Local two/four/eight-request checks do not reproduce a 367-request/minute production burst or production runtime/MySQL conditions.
