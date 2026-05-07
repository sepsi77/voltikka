# Sentry observability setup

Add Sentry to the Laravel 11 application using the user-provided Sentry DSN and Laravel instructions.

Scope:
- Install `sentry/sentry-laravel`.
- Configure Laravel exception handling in `bootstrap/app.php`.
- Publish/configure Sentry config and env defaults.
- Add Sentry logs channel to `config/logging.php`.
- Verify with available tests/commands where possible.

Notes:
- Do not commit local secrets beyond the user-provided DSN if it is added to `.env` by the Sentry publish command; prefer documenting required env vars in `.env.example`.
- Excimer may not be installable inside the agent environment; document production installation if needed.
