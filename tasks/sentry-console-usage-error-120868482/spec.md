# Sentry 120868482: local Artisan option error

Investigate Sentry issue 120868482 where a local `php artisan config:show sentry --only=ignore_exceptions` verification command failed with Symfony Console `RuntimeException` because Laravel 11's `config:show` command does not define an `--only` option.

Goal: determine relation to local verification-command noise and suppress non-application console usage errors from Sentry if appropriate.
