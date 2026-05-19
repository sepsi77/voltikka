# Decisions

- This issue is related to Sentry 118652485: both are local verification-command failures captured by Sentry before application code runs.
- Root cause is the attempted command `php artisan config:show sentry --only=ignore_exceptions`; Laravel 11's `config:show` command does not support `--only`, so Symfony Console rejected the option during input binding.
- Added `Symfony\Component\Console\Exception\RuntimeException` to Sentry `ignore_exceptions` alongside the PsySH parse error ignore so invalid local Artisan invocations do not create application-error noise.
- Use `php artisan tinker --execute='var_export(config("sentry.ignore_exceptions"));'` or `php artisan config:show sentry` for future config verification instead of the unsupported `--only` flag.
