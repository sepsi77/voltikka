# Decisions

- The Sentry exceptions are attack probes, not application defects. They stay reported in Sentry,
  because they are the only signal of attack activity. No `dontReport` change.
- Livewire 4.4.2 already blocked the gadget chain: update hydration takes synthesizer meta only from
  the checksum-verified snapshot, and `SecurityPolicy::validateClass()` denylists classes. The
  update to 4.4.7 is routine hardening, not an emergency fix.
- A dry run of `laravel/framework:^12.0` resolves with every current package (12.70.0). Laravel 13
  is blocked by the installed laravel/tinker 2.x and spatie/laravel-backup 9.x constraints; both
  need a major upgrade too.
