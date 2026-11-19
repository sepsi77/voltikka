# Staged external API imports — spec review 2026-11-19T12-19

Initial range: `3ed8b55..cdc9365` (commits `41da647`, `cdc9365`) · Reviewers run: refactor, laravel, pricing, test, platform (Pi, `openai-codex/gpt-6.1-sol`) · Skipped: frontend (no views, CSS, JS or SEO output), copy (no public Finnish text)
Verification range: `cdc9365..743f1c1` (laravel, pricing, test, platform); focused write-path check `743f1c1..a906fac` (laravel, pricing)
Final assessed HEAD: `a906fac` plus the LAR-201/LAR-202 fix commit after it
Later source changes: the LAR-201/LAR-202 fix (`FetchContracts.php`, `FetchSpotForecast.php`, `FetchEexFutures.php`, their tests and AGENTS.md). Verified by manager code inspection, the targeted suites (161 passed), the full Laravel suite and pytest; each new test fails against a broken copy. No further reviewer pass (bounded rounds); the external PR review covers it.

## Spec items

S1–S21 and D1–D8 as numbered in `spec.md`. Out of scope: S17 (shipped in PR #30) and D7 (production check after deploy).

## Verdict: ACCEPT

All must-fix findings, the verification residuals and the focused-check findings are fixed with tests that fail without them. What remains is one recorded deferral (paged-source parser replay), one rare corrupt-file checkpoint case and the production steps in D7.

## Spec rollup

27 items in scope: ✅ 26 · ⚠️ 1 · ❌ 0 · ➖ 0

| Item | State | Reason |
|---|---|---|
| S1 | ⚠️ | Raw bodies saved and replayable; paged sources (GIE, Syke, Fingrid, ENTSO-E) keep fetch-side parsing, so a page that fails to parse cannot be replayed after a parser fix (decision, see actions). |
| S2–S16, S18–S21 | ✅ | Per the reviewers' checklists after the fixes in `actions.md`. |
| D1–D6, D8 | ✅ | Real-chain feature tests per command (incl. EC46/SEAS5), pytest D4 with recorded-history replays. |

## Blocking findings

None open.

## Non-blocking findings

| ID(s) | Title | Sev/Lik | Class |
|---|---|---|---|
| LAR-006 (paged part) | Paged sources keep fetch-side parsing | Medium/Low | backlog (decision) |
| LAR-202 (trait part) | A payload refused by the reader before the store step leaves the fetch's `running` marker | Low/Low | backlog |

## Verified

- Payload format, SHA-256, atomic files, redaction (D3 for ENTSO-E, Fingrid, GIE), streaming reader.
- One store transaction per command, data-meaning times from the header (S5), S9 date refusals for contracts and EEX, superseded refusal for contracts and the forecast display, serialized under store locks.
- Checkpoint ownership: one replay takeover, failure paths only finish their own owner, the fetch owner is finalized when a split store fails before takeover.
- Temporal: two activities per staged step, no retry of a failed command (also S inputs), Issues carry step and payload reference, store schedule-to-close of one day and `payload_expired` refusal (S6), patched EEX/contract/S-forecast paths with replay tests of recorded histories.
- Full suites: Laravel 3310+ passed with the 44 known environment failures (no Vite build, no local SQLite file), pytest 94 passed.
