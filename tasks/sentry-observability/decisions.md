# Decisions

- Start with Sentry for exception tracking, traces/profiling, and optional log forwarding because it is the highest-value low-ops observability addition for this Railway-hosted Laravel app.
- `bootstrap/app.php` uses `Sentry\Laravel\Integration::handles($exceptions)` so unhandled Laravel exceptions are captured by Sentry.
- The log channel is named `sentry_logs` to match the current Sentry Laravel SDK driver. Production can opt into log forwarding by setting `SENTRY_ENABLE_LOGS=true` and `LOG_STACK=single,sentry_logs`.
- `.env.example` leaves `SENTRY_LARAVEL_DSN` empty and log forwarding disabled by default; actual DSN and sampling values should be configured in Railway environment variables.
- Excimer is added to the production Docker image with `pecl install excimer` and `docker-php-ext-enable excimer`; it was not installed in the local agent PHP runtime.
