# Dependency security update (2026-10-20)

Trigger: Sentry issues 142673968, 127565572 and 154614966. An automated scanner sent forged Livewire
requests to `/admin/login` on 2026-10-20 (locked-property update, a deserialization gadget chain
in a property update, a PHP upload through the Notifications component). All of them failed. The
Railway HTTP logs show no admin page access after login.

Scope: patch/minor updates inside the existing composer constraints only.

- livewire/livewire 4.4.2 -> 4.4.7
- filament/* 5.7.6 -> 5.10.2 (fixes CVE-2026-104181, MFA management without password reauthentication)
- symfony/http-foundation 7.4.17 -> 7.4.21 (fixes CVE-2026-108082, ReDoS)
- league/commonmark 2.10.0 -> 2.10.4, symfony/yaml 8.1.5 -> 8.1.9 (open advisories)

Out of scope: the Laravel 11 -> 12/13 upgrade. Laravel 11 security support ended in March 2026, and
the remaining `composer audit` findings are laravel/framework advisories without an 11.x fix.
