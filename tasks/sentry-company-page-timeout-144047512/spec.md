# Fix company-page timeout under concurrent cold requests

Status: completed on 2026-09-01.

Investigate and fix Sentry issues VOLTIKKA-23 and VOLTIKKA-24 (issue IDs 144047512 and 144047568) and the related Aug 31 VOLTIKKA-18 event (issue ID 129678126), where concurrent production requests for company detail pages exceeded PHP's 30-second limit.

## Requirements

- Use the Sentry traces and read-only Railway evidence to identify the load pattern.
- Prevent company-page cache misses from repeating the same global fingerprint scans and the same company comparison build concurrently.
- Preserve the current company market-comparison data rules and honest unavailable state.
- Add focused regression tests for shared fingerprint reuse and cold-cache stampede control.
- Run focused tests and `git diff --check`.
- Do not mutate or deploy production without explicit user confirmation.
