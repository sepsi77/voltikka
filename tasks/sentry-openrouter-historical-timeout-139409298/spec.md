# Spec

Fix Sentry issue 139409298 (`VOLTIKKA-1N`) by giving historical OpenRouter reasoning requests enough time to complete while keeping the full interpretation and queue execution bounded.

Requirements:
- Set the historical OpenRouter HTTP timeout to 300 seconds.
- Keep one HTTP attempt per historical model call. Queue retries remain the transport retry boundary.
- Allow one initial model call plus up to two repair calls inside a 1,000-second historical job timeout.
- Set the shared Supervisor worker timeout to 1,020 seconds and the database queue retry window to 1,050 seconds so jobs cannot be retried while still running.
- Keep current, non-historical interpretation request policy unchanged.
- Keep all other job-level timeouts unchanged.
- Add focused regression coverage for the complete timeout ordering.
- Update all nearby context documentation and the environment example.
- Do not mutate production or deploy without separate confirmation.
