# Fix header spot-price refresh and unavailable state

## Problem

The header initially renders a gray loading shell and fetches `/api/header-spot-price` once. A transient request failure or a response produced while the current quarter/hour row is missing can leave the widget gray for the lifetime of the page. The fetched partial contains `wire:poll.60s`, but HTML inserted with `innerHTML` is not a mounted Livewire component, so it does not provide reliable polling.

Negative and zero numeric prices are not inherently rejected by `HeaderSpotPriceService`; they must continue to render as valid active prices.

## Scope

- Make the plain-JavaScript header loader retry temporary failures and refresh the price periodically.
- Ensure both desktop and mobile header instances stay synchronized without unnecessary duplicate requests.
- Remove or replace the misleading non-functional `wire:poll` behavior.
- Clearly distinguish loading, temporarily unavailable, and valid-price states.
- Preserve quarter-price preference with hourly fallback.
- Add regression coverage for negative, zero, missing, and fallback prices.

## Acceptance criteria

1. A current negative price and exactly zero price render with their signed numeric values rather than the gray unavailable state.
2. A failed initial request is retried with a bounded delay/backoff and does not leave the shell permanently stuck on “Ladataan…”.
3. A successful response is refreshed approximately every 60 seconds while the page remains open.
4. A temporarily unavailable response is checked again later without requiring navigation or a page reload.
5. Desktop and mobile containers display the same response and do not each initiate redundant network requests.
6. Refresh timers/listeners do not multiply after Livewire navigation or repeated initialization.
7. The API keeps bounded cache semantics and does not expose errors or secrets.
8. Automated tests cover negative and zero service/API rendering, quarter preference, hourly fallback, and unavailable output. Add browser/JavaScript coverage where practical for retry and refresh behavior.

## Primary files

- `laravel/app/Services/HeaderSpotPriceService.php`
- `laravel/resources/views/layouts/app.blade.php`
- `laravel/resources/views/components/header-spot-price-shell.blade.php`
- `laravel/resources/views/livewire/header-spot-price.blade.php`
- `laravel/routes/api.php`
- `laravel/tests/Feature/HeaderSpotPriceServiceTest.php`
