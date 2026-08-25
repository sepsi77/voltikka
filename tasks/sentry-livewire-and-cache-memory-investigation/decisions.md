# Decisions

## Selected-consumption annual-row hydration

The statistics page uses annual-cost rows only for its selected consumption. It still uses all unit-statistics rows. Therefore, `ContractPriceStatistics::getDailyStatsProperty()` now applies the consumption filter only to the active annual-method branch. The active-method, endpoint, and pricing-basis rules do not change.

Production failed at the 128 MB PHP limit when the warmer hydrated 12,429 Eloquent rows. In the local data, the 5,000 kWh filter reduced the relevant load from 11,631 rows to 7,825 rows. It reduced query memory from approximately 104 MB to 91 MB. This fix removes rows that the page does not use. The full Sentry and memory investigation stays open.

## Queue-worker recycle

The warmer's first attempt exhausted 128 MB at approximately 03:09 UTC on 23, 24, and 25 August. Supervisor restarted the worker. Each retry became available 1,050 seconds later and succeeded. The retries took approximately 3.1 seconds on 23 and 24 August, and 130 ms on 25 August. This repeated result shows that memory retained by the long-lived worker is part of the cause.

The shared worker now uses `--max-time=3600`. Laravel checks this limit between jobs, so it does not stop the current job. The 1,020-second timeout, queue order, and three tries do not change. Supervisor starts a fresh worker after the old worker exits. This change limits memory retention without changing the job retry envelope.

## Livewire attack requests

VOLTIKKA-17 and VOLTIKKA-20 are related hostile requests. Production recorded 47 locked-property failures at 03:58 UTC and 28 notification collection type failures at 03:59 UTC from two adjacent source IP addresses. The notification request contains a gadget chain and a command that tries to write `/app/cox.txt`. It matches public scanning for Livewire CVE-2025-54068.

Production uses Livewire 4.3.5. CVE-2025-54068 affects Livewire versions before 3.6.4, and Livewire 4.3.5 contains the later class-validation and hydration controls. The notification request stopped when Filament received an integer instead of a notification array. The nested attacker metadata was not hydrated. The second request stopped at Filament's `#[Locked]` property before a state change.

The same production deployment has run since 22 August. `/app/cox.txt` and the other likely marker paths are absent. No unexpected application file was created in the attack window. The supplied command has no cleanup step. These facts give strong evidence that the supplied requests did not execute their command. A valid public login-page snapshot does not show that the application key leaked.

VOLTIKKA-18 is not related to these requests. It happened in the separate queue-worker process approximately 50 minutes earlier.

## Dependency audit

The locked Filament 5.7.5 and Livewire 4.3.5 versions have no Composer advisory for these two probes. Livewire 4.3.5 is outside the CVE-2025-54068 affected range. A full `composer audit --locked` is not clean: it reports advisories in 15 packages, including runtime packages. This is a separate dependency-maintenance issue and not the cause of the three Sentry issues. Filament 5.7.6, Livewire 4.4.2, and Sentry Laravel 4.27.0 are available as direct compatible updates; Laravel needs a planned major-version review because the application constraint stays on Laravel 11.
