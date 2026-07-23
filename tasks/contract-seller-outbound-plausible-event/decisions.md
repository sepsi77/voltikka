# Decisions

- Reuse the existing `Contract Order Clicked` event name to preserve analytics continuity.
- Send contract metadata under Plausible's required `props` option and use Blade's `@js` encoding for safe JavaScript values. The CTA already named the event, but its raw string ID produced invalid JavaScript and its metadata was not nested as Plausible options after the tracking helper API changed.
- Added a contract-detail feature regression that pins the event name and seller/contract properties.
- Verification: `php artisan test tests/Feature/ContractDetailPageTest.php` (51 passed, 110 assertions).
