# Decisions

- The Sentry stack frame points at Excimer profile serialization, not application code. With production workers capped at 128 MB, profiling long-running console/queue transactions can accumulate enough samples to fail while `Profile::getTrace()` serializes the log.
- Added a Sentry `profiles_sampler` that disables profiling for console and queue transactions by default. Web profiling still uses `SENTRY_PROFILES_SAMPLE_RATE`; short CLI/queue diagnostic runs can opt in with `SENTRY_PROFILE_CONSOLE_ENABLED=true` or `SENTRY_PROFILE_QUEUE_ENABLED=true`.
- Reduced the documented/example profile sample rate from 1.0 to 0.1 to avoid recreating the same pressure in new environments.
- Also reduced the queued contract statistics warmer's own memory footprint: `ContractPriceStatistics::getDailyStatsProperty()` now selects only used columns and keeps an explicit per-instance collection cache. This matters because the warmer instantiates the Livewire component directly and scans the statistics table many times while preparing one cache payload.
