# Decisions

## Step 1: Laravel 12 breaking-change review (2026-10-20)

`composer require laravel/framework:^12.0 -W` resolved to 12.70.0 with no other constraint
change. The Laravel 12 upgrade guide items were checked against the code:

- Carbon 3: already installed (3.13.2 before, 3.15.0 after).
- `HasUuids` UUIDv7 default: no model uses `HasUuids`.
- `local` disk default root: `config/filesystems.php` defines `local` explicitly.
- Concurrency result keys, `new Blueprint(...)`, `mergeIfMissing` dot notation, `Str::is` multiline,
  `image` rule without SVG: no use in `app/`.
- Schema introspection now includes all schemas: only `Schema::hasTable` in migrations; the
  development database synchronizer uses raw PDO (`SHOW FULL TABLES`, `sqlite_master`).
- Duplicate route names: none.

Local PHP is 8.5; production runs PHP 8.4 (FrankenPHP image). Both are supported by Laravel 12/13.

## Step 1: failures found by the test suite and their fixes

The first Laravel 12 run had 44 failures in three groups:

- 41 SolarCalculator tests (and two Preferred Sources tests that render that page): Laravel 12
  added the Blade directive `@context`. The inline JSON-LD line `"@context": "https://schema.org"` in
  `resources/views/livewire/solar-calculator.blade.php` compiled to an unclosed `if`, so the page
  threw a parse error. Fix: escape it as `"@@context"`. It was the only unescaped `@context` in the
  views; the rendered page still emits valid FAQPage JSON-LD with `@context`. Inline JSON-LD in
  Blade must escape `@context` from now on.
- Two tests build `new MySqlGrammar` to compile MySQL SQL without a connection. Laravel 12 grammars
  take the connection in the constructor: `new MySqlGrammar($connection)`. Test-only change.
- `LayoutAssetLoadingTest`: Laravel 12 Vite writes `<link rel="modulepreload" as="script" ...>`.
  `as="script"` is the default destination of a module preload and causes no duplicate download, so
  the test accepts the optional attribute. The forbidden pattern (plain `rel="preload"` with
  `as="script"`) is still asserted.

After the fixes: 3,060 tests pass, `npm run build` passes, `composer audit` finds no advisories.

## Step 1 addendum: published Livewire assets

The browser check showed "The published Livewire assets are out of date". `public/vendor/livewire/`
is committed and dated from August, older than the installed Livewire PHP package; production had
the same mismatch. Republished for Livewire 4.4.7 and added `vendor:publish --tag=livewire:assets`
to `post-update-cmd`. See the "Asset loading" section of `laravel/AGENTS.md`.

## Step 2: Laravel 13 (branch `upgrade/laravel-13`, 2026-10-20)

`laravel/framework` ^13.0 (13.38.0), `laravel/tinker` ^3.0 (3.0.2), `spatie/laravel-backup` ^10.0
(10.3.3, with db-dumper 4.1.1), `intervention/image-laravel` ^4.1 (Intervention Image 4.4.0). The
lock pulls Symfony 8, Guzzle 8 and brick/math 1.0. Symfony 8 needs PHP >= 8.4.1, so composer.json
`php` is `^8.4`; production runs `dunglas/frankenphp:1-php8.4` (8.4.26).

Failures found by the test suite (80) and their fixes:

- CSRF middleware rename. The Laravel 13 web group uses `PreventRequestForgery` instead of
  `ValidateCsrfToken`. The stateless public routes removed only the old class, so the new one stayed
  and failed with "Session store not set on request" (every stateless page returned 500). Routes now
  remove `PreventRequestForgery`; `bootstrap/app.php` calls `preventRequestForgery(except:)`, and the
  Filament panel uses `PreventRequestForgery` instead of the deprecated `VerifyCsrfToken` alias.
  The new middleware also accepts a request whose browser-set `Sec-Fetch-Site` is `same-origin`
  before it checks the token; that header cannot be set by page scripts.
- Image container key. Laravel 13 has its own image layer that binds `image` and needs Intervention
  Image v4; `intervention/image-laravel` 1.x bound the same key for v3, and the framework binding won.
  Upgraded to image-laravel 4.x (binding `intervention.image`) and ported `logos:optimize` and its
  test to the v4 API: `decodeBinary`, `createImage`, `encode(new WebpEncoder(...))`.

Checked without a code change:

- `cache.serializable_classes` is not set, so cache unserialization behaves as before (null = all
  classes allowed). Restricting it is a possible later hardening, not part of this upgrade.
- laravel-backup 10 still applies both dump options from `config/database.php`: the generated command
  has `--single-transaction`, and `skip-ssl` is written into the `[client]` section of the
  credentials file that mysqldump reads.

Verification: 3,060 tests pass; `npm run build`; `composer audit` clean; `schedule:list` and
`backup:list` boot. Local server: all main routes return 200 (404 for a missing page); cookie and
Cache-Control headers equal production for every checked route; browser checks of the comparison
filters, solar presets, bill comparison, statistics charts, contract detail and admin login pass
with no console errors. The two existing Pint findings in `routes/web.php` and
`OptimizeLogos.php` are also on `main` and were left unchanged.
