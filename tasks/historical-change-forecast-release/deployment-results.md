# Deployment results — 2026-09-13

This record uses verification results supplied by the manager. The documentation agent did not run production operations.

## Approved release

- The approved push to `main` released commit `5beaf6fd50b2141362b52931200f0f5963edd67a`.
- Railway deployment `4705b450-297d-41ad-80c9-928a8e2a7523` reached `SUCCESS`. The manager verified this exact deployment ID with `scripts/railway-poll-deployment.sh`.
- Project: `6d8cae01-1006-409f-8108-1d51f1abc676`.
- Production environment: `9245cef8-41d0-486e-862f-193726511dba`.
- App service: `700d0624-fa96-4266-876c-e37640d220ea`.
- The deployment log contains the automatic nullable migration.
- `https://voltikka.fi/sahkosopimus/sahkon-hintaennuste` returned HTTP 200. Its title was `Sähkön hintaennuste: määräaikaisten hintanäkymä | Voltikka`. The old lock heading was absent.

## Read-only production forecast check

The nine-row dry run for issue date `2026-09-13`, horizon 30 days, succeeded without freshness recovery. Each fit had 174 accepted pairs: 156 observed and 18 canonical. Start dates ran from January 21 through August 13; target dates ran from February 20 through September 12.

| Term | Median move (c/kWh) | Outlook | History coverage |
| --- | ---: | --- | --- |
| 6 months | +0.5949 | Rising | Low |
| 12 months | +0.2754 | Rising | Low |
| 24 months | +0.1929 | Rising | Low |

Production contains older history than the saved export, which starts on April 8. The approved all-available-history policy uses that older evidence. Thus these counts differ from the isolated export replay. The earlier replay and pre-deployment verification remain valid records of their own inputs; they are not production results.

The dry run with `--require-freshness` deferred with only this message:

> Contract statistics started before the current interpretation was published.

The successful nine-row calculation does not prove that the freshness gate passed.

## Initial generation approval request (before execution)

No manual forecast generation or forecast writes were run as part of these checks. Deployment approval does not authorize initial generation.

The manager will request separate explicit approval for this command in the production context above:

```bash
php artisan forecasting:run-fixed-contracts --as-of=2026-09-13 --horizon=30 --require-freshness
```

This command may refresh September 13 contract statistics before it writes forecasts. Approval must cover both effects. If the issue date changes, inspect the new date and request approval for that exact command. After approved generation, read back the saved model, basis, means, pair counts and null diagnostics as required by `release-plan.md`.

## Approved initial generation and verification — complete

The user subsequently approved initial production generation. The manager ran Railway SSH with the explicit project, production environment and app service IDs above, and this exact command:

```bash
php /app/artisan forecasting:run-fixed-contracts --as-of=2026-09-13 --horizon=30 --require-freshness
```

The command succeeded: **Saved 9 forecasts, skipped 0**. No recovery message was printed. This result does not confirm that statistics were refreshed.

A corrected quoted read-only query verified all nine saved rows: model `fixed_term_historical_change_v1`, basis `canonical_calculation`, target `2026-10-13`, 174 pairs per fit, and NULL hedge diagnostics. The first SSH `php -r` read-back attempt failed on shell quoting before execution; the corrected query succeeded.

| Term | Saved median (c/kWh) | Median move (c/kWh) | Outlook | History coverage |
| --- | ---: | ---: | --- | --- |
| 6 months | 15.1243 | +0.5949 | Rising | Low |
| 12 months | 12.2163 | +0.2754 | Rising | Low |
| 24 months | 10.7529 | +0.1929 | Rising | Low |

The live forecast page returned HTTP 200 and displayed `15,12`, `12,22` and `10,75`, with target date `13.10.2026`, qualified rising outlooks and visible uncertainty.

Initial generation and verification are complete. Existing daily generation at 07:30 and evaluation at 07:45 remain unchanged. This record is local only; no additional push is approved.
