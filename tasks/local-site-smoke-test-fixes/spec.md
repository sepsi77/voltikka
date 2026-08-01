# Local site smoke-test fixes

## Goal

Fix the remaining defects found by browser smoke tests after the architecture changes and production-data sync.

## Scope

- Make the local development origin and public-storage logo path work at `http://127.0.0.1:8000`.
- Remove ineffective or duplicate Vite and Livewire preloads without breaking asset loading.
- Show clear validation when bill-comparison consumption is zero or otherwise below the accepted minimum.
- Keep the solar example heading consistent with the selected system size.
- Prevent broken external company-logo URLs from reaching public structured data, while keeping a safe visible fallback.
- Add focused regression tests and re-run browser smoke tests.

## Constraints

- Use the simplest existing patterns.
- Do not add request-time external logo health checks.
- Do not expose secrets or modify production.
- Keep canonical URLs correct in production; local fixes must not hard-code a production-host change.
