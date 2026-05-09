# Sentry 118652485: local tinker parse error

Investigate Sentry issue 118652485 where a local `php artisan tinker --execute` smoke command failed with a PsySH parse error (`unexpected T_NAME_FULLY_QUALIFIED`) while testing the `seo-contracts-list` Livewire component.

Goal: determine whether this is an application bug or a malformed local verification command, and update code/docs/tests only if needed.
