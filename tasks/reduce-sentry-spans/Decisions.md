# Decisions

- Sentry errors/logs and performance spans are controlled separately. Exception capture uses `SENTRY_LARAVEL_DSN` plus Laravel integration; performance spans are controlled by `SENTRY_TRACES_SAMPLE_RATE` and tracing options.
- To preserve errors/logs while reducing span quota usage, set `SENTRY_TRACES_SAMPLE_RATE=0.0` and `SENTRY_PROFILES_SAMPLE_RATE=0.0` by default/documentation. This stops sampled transactions/spans/profiles without removing Sentry error reporting.
- Local `php artisan config:show sentry` confirmed overriding those two environment values reports trace/profile rates as `0` while `sample_rate` remains `1` and `enable_logs` remains unchanged.
