# Decisions

## 2026-08-08

- The connection was established quickly and OpenRouter returned HTTP 200 after about 2.06 seconds, but the response body did not complete before Voltikka's deliberate 100-second historical-call timeout. This is an upstream response-duration timeout, not DNS, TLS, Railway egress, or application database failure.
- The historical client deliberately makes one HTTP attempt per queue execution. The job rethrows transport failures so Laravel can use its three bounded queue attempts. The Sentry breadcrumbs show Laravel deleted and reinserted the database job, which is retry behavior rather than a lost job.
- Read-only production inspection found interpretation 5116 in `processing` state with no LLM output and a transport/runtime error. Its queue job exists with one used attempt, is unreserved, and is available for retry. The retry is behind the other historical backfill work because a failed database-queue job is released back into the queue.
- At inspection time, the historical store had 2,318 validated rows, 4,009 pending rows, 7 processing rows, and 200 failed rows. Only 6 rows had a transport/runtime error. The historical queue had 788 unreserved jobs grouped as 781 first-attempt jobs and 7 retry jobs. This does not show a broad OpenRouter outage or a broken retry path.
- After the investigation, the user accepted a coordinated timeout change. Historical OpenRouter calls now have a 300-second HTTP timeout and one HTTP attempt. The historical job timeout is 1,000 seconds. Thus, one initial call and two repair calls can finish in the job envelope.
- The shared Supervisor worker timeout is 1,020 seconds. The database queue `retry_after` default and `.env.example` value are 1,050 seconds. Code also enforces 1,050 as the minimum. This gives the required order: `3 * 300 < 1000 < 1020 < 1050`.
- Read-only production inspection found an explicit old `DB_QUEUE_RETRY_AFTER=450` value. The code minimum makes the effective value 1,050 after deployment without a production variable change. A lower environment value cannot release the 1,000-second job while it still runs.
- The current OpenRouter timeout and retry policy did not change. Other job-level timeouts did not change. The cache warmer job timeout stays 300 seconds and uses the shared 1,050-second database retry window.
- The focused policy test reads the real root `supervisord.conf`. It verifies the exact values, the timeout order, one HTTP attempt, and the safety floor against an old 450-second environment value. All five `AnalyzeHistoricalContractEpisodeTest` tests passed with 40 assertions.
- Production investigation and this implementation were read-only for production. No queue retry, database write, deployment, or production configuration change was made.
