# Private Filament administration

This subtree contains the private `/admin` panel. Read `../../AGENTS.md` and `../Services/Analytics/AGENTS.md` first.

## Access

The panel uses the existing `App\Models\User` model. `User::canAccessPanel()` permits only the `admin` panel and only when `users.is_admin` is true. The database default is false. A valid email and password do not give access to a non-admin user.

The panel has login and logout. It has no public registration route. Do not create an admin user in a migration, seeder, deployment command, or normal release process.

Admin provisioning is an explicit operational data change:

1. Get approval for the target environment.
2. Run `php artisan make:filament-user` in that environment. Use its hidden password prompt.
3. Run `php artisan tinker` in the same environment.
4. Find the exact user by email, set `$user->is_admin = true`, and call `$user->save()`.
5. Confirm access, then end the shell session.

A production user create, role grant, or role removal is a production mutation. It always needs separate confirmation. Never put a password on a command line or in shell history.

## Contract-order-click resource

`Resources/ContractOrderClicks/ContractOrderClickResource.php` has only an index page. Its create, edit, delete, force-delete, restore, replicate, and reorder authorization methods all return false.

`Tables/ContractOrderClicksTable.php` has no record actions or toolbar actions. It is newest first and uses database pagination. Native pagination shows the count for the selected result set. Search covers company, contract name, and contract ID. Native filters cover date range, company, contract, source, medium, campaign, and CTA location. String filters use exact text input instead of loading every distinct value from the indefinitely retained event table.

Do not add create, edit, view-with-mutation, delete, bulk delete, restore, relation-manager mutation, import, or inline editable actions to this analytics resource. Analytics rows are evidence and are read-only in the panel.

## Assets and dependencies

The application uses Filament 5 with Livewire 4. Composer runs `php artisan filament:upgrade` after autoload generation. The production Docker build runs Composer inside the image, so it publishes the required Filament files to `public/css/filament`, `public/js/filament`, and `public/fonts/filament` during the build. These generated package assets are not source files and are not tracked in this repository. Do not hand-edit them or copy files from `vendor` into source control.
