# Decisions

- This Sentry event is not an application/runtime failure in `SeoContractsList`; it is a local verification command malformed before execution by PsySH.
- Reproduced the exact parse error with `php artisan tinker --execute='use Livewire\\\\Livewire;'`. A single-quoted shell string with `\\\\` passes two literal namespace separators to PHP (`Livewire\\Livewire`), which is invalid syntax and triggers `unexpected T_NAME_FULLY_QUALIFIED`.
- Confirmed the correct form succeeds: `php artisan tinker --execute='use Livewire\\Livewire; echo Livewire::class;'`.
- Targeted Livewire calculator tests for the underlying SEO component behavior pass, so no component code change is needed.
- Added a Sentry verification note to `laravel/AGENTS.md` to avoid double-escaping namespace separators in single-quoted `tinker --execute` commands.
