# Preferred source action strip

Add the reusable Preferred Sources action strip directly after the hero or primary editorial header on every real public HTML page.

Completed scope:
- `x-page-action-strip` has natural Finnish title and description defaults.
- The component owns the normal Google action, the 8-second deeplink fallback, the `noscript` fallback, and the one-time external script load.
- The optional component slot follows the built-in Google action for future page tools.
- All 19 distinct real public Blade page templates contain one explicit server-rendered strip.
- The internal `contract-type-comparison` widget contains no strip.
- Hero or header bottom margins do not leave an empty gap before the strip.
- The Plausible event is `Google Preferred Source Clicked` with `placement=post_hero`.
- Source-policy and representative route tests guard sitewide coverage and one script load per response.

Verification includes focused feature tests, the source-policy test, the form blur policy test, the production asset build, `git diff --check`, and `git status --short`.
