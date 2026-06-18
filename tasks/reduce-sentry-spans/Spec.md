# Reduce Sentry span volume

Goal: reduce or stop Sentry performance span ingestion while preserving exception capture and optional Sentry log forwarding.

Approach:
- Prefer disabling performance tracing/profiling by setting trace/profile sample rates to `0.0`.
- Do not disable the Sentry DSN, exception handling, or `sentry_logs` channel.
- Document production guidance so Railway variables can be updated explicitly after confirmation.
