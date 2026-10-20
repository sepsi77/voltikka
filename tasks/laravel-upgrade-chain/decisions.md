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
