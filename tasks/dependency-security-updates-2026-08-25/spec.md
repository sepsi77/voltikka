# Dependency security updates

Update Composer dependencies to the newest compatible versions under the current Laravel 11 constraints. Run the security audit and application tests. Do not perform the separate Laravel major-version upgrade in this task.

## Result

The Composer update is complete. The lock file stays on Laravel 11 and updates 94 packages. The locked audit still reports three Laravel Framework advisories that have no Laravel 11 fix. The frontend build passed. The full test suite ran, but it did not pass. It had 25 failures and 2,116 passes. A run with the old lock file had 24 of the same failures. The dependency-related `AnalyticsEventTest` timestamp regression now has an explicit UTC model accessor. The full analytics event and Filament analytics test files pass.
