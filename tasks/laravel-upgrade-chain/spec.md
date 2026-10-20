# Laravel upgrade chain: 11 -> 12 -> 13

Laravel 11 security support ended in March 2026. On 2026-10-20 `composer audit` showed four
laravel/framework advisories with fixes only in 12.x/13.x (debug-page XSS, temporary signed URL
path confusion, CRLF injection in the default email rule).

Goal: run on Laravel 13 (security support until about early 2028).

Method: two separate steps. Each step has its own branch, full test suite, production asset build
and its own deployment approval. Laravel 12 alone has security support only until about
February 2027, so it is an intermediate step, not the target.

## Step 1: Laravel 12 (branch `upgrade/laravel-12`)
- composer.json `laravel/framework` ^12.0; dependency resolution needs no other constraint change.
- Laravel 12 breaking changes checked against the code (see decisions.md).

## Step 2: Laravel 13
- Also needs `laravel/tinker` 3.x and `spatie/laravel-backup` 10.x (installed versions block 13).
- After deployment, prove the backup package with a manual production backup run. That run is a
  production mutation and needs explicit user approval.
