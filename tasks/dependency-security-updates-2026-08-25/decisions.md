# Decisions

## Update scope

The update stays within the current `composer.json` constraints. The Laravel Framework constraint stays at `^11.31`. A Laravel 12 or 13 migration needs a separate compatibility review and is outside this update.

Composer 2.9 first stopped the update because all available Laravel 11 releases have active advisories. The completed update used `composer update --with-all-dependencies --no-security-blocking --no-interaction`. This option let Composer update the lock file without hiding the audit result. No advisory was added to an ignore list.

The lock file changed 94 package versions. It added no package and removed no package. The requested package changes are:

| Package | Before | After |
| --- | --- | --- |
| `laravel/framework` | `v11.47.0` | `v11.56.0` |
| `filament/filament` | `v5.7.5` | `v5.7.6` |
| `livewire/livewire` | `v4.3.5` | `v4.4.2` |
| `sentry/sentry-laravel` | `4.25.1` | `4.27.0` |
| `guzzlehttp/guzzle` | `7.10.0` | `7.15.5` |
| `league/commonmark` | `2.8.0` | `2.10.0` |
| `mtdowling/jmespath.php` | `2.8.0` | `2.9.2` |

## Locked security audit

`composer audit --locked --format=json` exited with status 1. It found three advisories in one affected package. It found no abandoned package.

| Package | Advisory | Severity | Affected versions |
| --- | --- | --- | --- |
| `laravel/framework` | `PKSA-m5cs-t1y6-qpcs` — Temporary Signed URL Path Confusion | medium | `<12.61.1` or Laravel 13 before `13.12.0` |
| `laravel/framework` | `PKSA-3r5d-mb8f-1qw9` — CRLF injection in the default email rule | high | `<12.60.0` or Laravel 13 through `13.9.0` |
| `laravel/framework` | `PKSA-mdq4-51ck-6kdq` — CRLF injection in the default email rule | unknown | all Laravel 11 releases, Laravel 12 before `12.60.0`, and affected older or Laravel 13 releases |

Laravel `v11.56.0` is the newest release allowed by the current major constraint, and all Laravel 11 releases match these affected ranges. The fixes need Laravel 12 or a newer major release. These advisories remain because this task expressly excludes a Laravel major migration.

## Verification

- The first full run with the updated lock failed with 25 failures and 2,116 passes. A comparison run with the old lock had 24 failures and 2,117 passes. This isolated one new `AnalyticsEventTest` UTC timestamp failure.
- After the UTC compatibility fix, the final full updated suite has the same baseline as the old lock: 24 failures, 2,117 passes, and 8,546 assertions in 85.40 seconds. The remaining failures are in `ArticleSpotElectricityStatisticsQueryTest` (4), `CompanyDetailSectionsTest` (16), `ContractListingEligibilityTest` (1), `ContractsListPageTest` (1), `HomePageContractTrendTest` (1), and `PricingBucketFilterTest` (1). They are not dependency-update regressions.
- `npm run build`: passed. Vite transformed 60 modules and completed in 673 ms. It gave a non-fatal warning that the Browserslist data is eight months old.

No deployment, production change, commit, or push was done.

## Laravel and Carbon UTC compatibility fix

Laravel 11.56.0 and Carbon 3.13.2 changed how the generic Eloquent datetime cast hydrates the raw `contract_order_clicks.occurred_at` value. The database value `2026-08-05 12:00:00` was read as Europe/Helsinki `+03:00`, although the handler wrote UTC and production rows contain UTC wall time.

`ContractOrderClick` now uses an explicit Eloquent `Attribute` for `occurred_at`. It parses database strings as immutable UTC Carbon values. It also converts assigned date values to UTC database strings. The generic `immutable_datetime` cast was removed only for this column. The `created_at` cast is unchanged. New writes stay in UTC to keep the same meaning as existing rows and the Filament `Aika (UTC)` label.

Verification after the fix:

- `php artisan test tests/Feature/AnalyticsEventTest.php`: passed, 9 tests and 82 assertions.
- `php artisan test tests/Feature/ContractOrderClickAdminTest.php`: passed, 9 tests and 122 assertions.
