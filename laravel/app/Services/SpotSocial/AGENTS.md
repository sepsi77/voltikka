# Daily spot social publication

This directory owns readiness and the durable publication claim for `social:publish-daily-spot`.

## Rules

- `spot:fetch` only imports spot data, calculates averages, and warms caches. It must not invoke this publication flow.
- A publication identity is the Helsinki `content_date` shown in the video.
- Readiness requires the exact UTC hourly sequence between Helsinki midnights for both `content_date` and the next date. This gives 23, 24, or 25 rows per date when daylight saving time changes.
- The first production attempt uses `insertOrIgnore()` and a locked row. A unique `content_date` prevents two first claims.
- A normal command call never retries a `processing` or `failed` row. An explicit `--retry --date=YYYY-MM-DD` can claim a failed row or a processing row that is at least 30 minutes old.
- A published row can never be claimed again.
- Attempt completion is a conditional update against both `status=processing` and the claim model's `attempt_count`. A stale attempt cannot change a newer processing or completed attempt.
- Dry-run, skip-post, and draft modes do not read or write the publication ledger.
- The first claim stores `data_as_of`. All retries reuse this timestamp for Laravel video data, copy context, and the Remotion API request.
- A PostFast response with one or more created posts is published. Disconnected platforms are stored as partial-success metadata and are not retried automatically.
- Local video deletion happens only after confirmed PostFast success. It is best-effort cleanup: a deletion failure emits a warning and does not change the publication result.
- External at-most-once publication cannot be guaranteed after a provider timeout. Some posts can exist even when Voltikka receives no result. The command records failure, does not retry automatically, and tells the operator to inspect PostFast before an explicit retry.

## Primary files

- `SpotSocialPublicationService.php`
- `../../Models/SpotSocialPublication.php`
- `../../Console/Commands/PublishDailySpot.php`
- `../../../database/migrations/2026_07_29_000001_create_spot_social_publications_table.php`
