# Decisions

- Use a direct authenticated Filament route and a streamed response. This avoids queued-export tables and keeps memory use bounded.
- Export all rows, not only the current table page.
- Read the database column list at export time so the CSV includes every current and future table column.
- Add the download as a non-mutating page-header action. Keep table record and toolbar actions empty.

## Verification

- `php artisan test tests/Feature/ContractOrderClickAdminTest.php`
- `php artisan route:list --name=filament.admin.contract-order-clicks.export`
- Laravel Pint on all changed PHP files
