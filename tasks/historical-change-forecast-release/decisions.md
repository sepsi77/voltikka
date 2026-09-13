# Decisions

- This is an explicit expanding-pair production variant. It differs from frozen research training identities. No old backtest score is attributed to this model.
- New forecasts store null gap/futures diagnostics; old rows remain intact. Nullable loosening has a no-op down to prevent invented or deleted history.
- Confidence counts accepted pairs in the current basis only; thresholds remain 120/365. It describes history coverage, not accuracy.
- Existing model/horizon cache identity changes invalidate forecast payloads without a separate copy cache bump.
- Verification found a calendar-date rerun bug in the old command under SQLite midnight casts. Generation now selects by whereDate and updates/creates explicitly. Completed forecasts cannot be overwritten even with --overwrite.
- Local work is complete: 82 targeted tests and 2,332 full-suite tests pass, with Pint, 26-file PHP lint, build and diff checks. See verification.md for exact commands and earlier failures.
- Isolated export replay matched 180 service fits against independent raw-array means, including all nine September 13 forecasts. Each September 13 fit has 98 pairs (80 observed, 18 canonical), low confidence and a rise outlook. All 171 chronological July 27–August 14 predictions had exact targets. Original forecasts and dry stages stayed byte-identical. The 24-month median direction result was worse than unchanged direction; no broad superiority claim is made.
- Frozen research and the existing uncommitted qualified-outlook work remain in place. Only generation is intentionally replaced.
- Manager supplied a read-only production preflight: local main HEAD `4401bf3733d93b8ebb1a551ef4325e43d41f8e74` matches successful Railway deployment `4e5c7270-7d9d-4162-a890-61b233b29976`, September 13 at 07:18 UTC. Model version, minimum history, direction threshold and default horizon environment pins are all absent. Code defaults should activate without a variable write. This agent did not access production. No production mutation is authorized.

## Post-deployment update — 2026-09-13

- Later explicit approval covered the main push of `5beaf6fd50b2141362b52931200f0f5963edd67a`. The manager verified exact Railway deployment `4705b450-297d-41ad-80c9-928a8e2a7523` as `SUCCESS`, the automatic nullable migration log and the public page. See `deployment-results.md` for target IDs and results. Earlier authorization and verification statements above describe the pre-deployment stage.
- The production nine-row dry run uses 174 pairs per fit (156 observed, 18 canonical), not the export's 98. Production has older history, and the approved all-available-history policy correctly includes it. Do not replace the historical export replay results with production counts.
- The required-freshness dry run deferred only because contract statistics started before the current interpretation was published. Initial manual generation remains pending separate explicit approval. The command with `--require-freshness` may refresh September 13 statistics before writing forecasts; approval must cover both effects. No manual generation or forecast writes were run during these checks. The manager will request approval.

## Initial generation completion — 2026-09-13

- The user subsequently approved initial production generation. The manager ran `php /app/artisan forecasting:run-fixed-contracts --as-of=2026-09-13 --horizon=30 --require-freshness` through Railway SSH with explicit Voltikka production app IDs. It succeeded: nine saved, zero skipped. This supersedes the pending status above.
- No recovery message was printed. Do not claim that a statistics refresh was confirmed.
- The corrected read-only query verified nine new-model rows, canonical basis, target October 13, 174 pairs per fit and NULL hedge diagnostics. The first SSH `php -r` query failed on shell quoting before execution. The corrected quoted query succeeded.
- Saved 6/12/24-month medians are 15.1243/12.2163/10.7529 c/kWh; moves are +0.5949/+0.2754/+0.1929. All have rising outlooks and low history coverage. The live page returned HTTP 200 and showed 15,12/12,22/10,75, target 13.10.2026, qualified rising copy and uncertainty. See `deployment-results.md`.
- Initial generation and verification are complete. Daily 07:30 generation and 07:45 evaluation remain unchanged. These documentation changes stay local; no additional push is approved. This documentation agent made no application changes or production calls.
