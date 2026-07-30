# Decisions

## Initial decisions

- Spot persistence and social publication are separate responsibilities.
- Use a durable date key for idempotency.
- Do not change social copy or video design in this task.
- No implementation decision is final until the current behavior is confirmed with tests.

## Confirmed prior behavior

- `spot:fetch` read the newest stored quarter-hour timestamp before import and the newest timestamp in the ENTSO-E response.
- After spot persistence, average calculation, and cache warming, a newer response timestamp made `spot:fetch` call `social:daily-video` directly through Artisan.
- The social command had no durable publication identity or attempt ledger. Repeated command calls could post the same Helsinki content date again.
- PostFast exceptions were caught inside the social command and did not make the command fail. A missing PostFast key also returned success after it skipped posting.
- The scheduler did not own an independent daily spot publication event. A comment stated that `spot:fetch` triggered publication when tomorrow data became available.

## Implemented design

- The independent command is `social:publish-daily-spot`. The old command name is not registered.
- The durable identity is one unique Helsinki `content_date` in `spot_social_publications`.
- Readiness compares stored FI hourly rows with the exact UTC sequence between Helsinki midnights for the content date and next date. It does not assume 24 hours.
- A first claim uses `insertOrIgnore()` in a transaction and then locks the date row. Normal calls do not retry. Explicit retry accepts failed rows and processing rows that are at least 30 minutes old.
- A first claim stores `data_as_of`. Retry uses the same timestamp for Laravel video data, fallback copy, prompt date context, the Remotion API query, and the content-date output file.
- `SPOT_SOCIAL_PUBLISHING_ENABLED` defaults to false. Dry-run and skip-post do not require it. Draft requires it because draft mode calls PostFast.
- Dry-run, skip-post, and draft do not use the ledger. A real PostFast result with at least one created post marks the date published. Skipped platforms are partial-success metadata.
- PostFast exceptions make the command fail and persist a bounded error. The error tells the operator to inspect PostFast before explicit retry because provider timeout results can be uncertain.
- The independent schedule runs hourly at minute 15 in Europe/Helsinki with overlap and single-server locks.

## Correctness follow-up

- `markPublished()` and `markFailed()` now update only the claimed `processing` attempt. They match the row ID and immutable claim `attempt_count`, and return whether the update won. A stale attempt cannot overwrite a newer attempt.
- The command warns when an attempt loses its completion update and leaves the newer ledger state unchanged.
- Local video deletion after a positive PostFast response is best-effort. Cleanup failure is warning-only and cannot convert the confirmed publication to failure.

## Verification

- Focused Laravel tests passed: 36 tests and 97 assertions.
- Remotion `npm run lint` passed.
- Remotion `npm run build` passed and wrote the local ignored bundle.
- Pint passed for all changed PHP files.
- `git diff --check` passed.
- The full Laravel suite ran 1,640 tests. It had one unrelated existing strict-float failure in `ContractDetailPresenterTest`: expected `60.0`, received `59.99999999999999`. The same test failed in isolation. The other 1,639 tests passed.
- The correctness follow-up focused set passed: 41 tests and 133 assertions across publication service, publication command, prompt formatter, and spot-fetch separation tests.
- Pint passed for the four PHP files changed by the correctness follow-up.
- `git diff --check` and separate no-index whitespace checks for the touched untracked files passed after the correctness follow-up.
