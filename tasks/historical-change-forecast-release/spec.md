# Historical-change forecast release

Implement `fixed_term_historical_change_v1` locally. For each 6/12/24-month term, quantile p20/median/p80 and horizon, add the expanding equal-weight mean of completed same-basis retail changes to the exact-date current price. Both endpoints must exist, with target strictly before issue. Use all eligible history, with at least max(20, configured minimum) unique start dates. Default horizon 30 days; stored-precision direction threshold ±0.15.

Unit statistics only. Latest ID owns a date before finite-value checks. Canonical presence through issue establishes each term's boundary, including invalid values. Retain observed prefix and canonical continuation, but reject seam-crossing pairs. No fallback after the boundary. Current input uses PricingMode. No futures, hedge, annual statistics, learned coefficients, rolling windows, new flags, dependencies or parameter table. Zero and negative finite prices are valid.

Keep legacy evaluation APIs and stored rows. New obsolete financial diagnostics and intervals are null. Add a value-preserving nullable migration with no destructive down. Only this model may generate; reject other pins before freshness recovery. Forecast freshness loses EEX requirements; retail-premium freshness keeps them. Keep publication/statistics recovery and schedules unchanged.

Keep qualified outlooks, visible uncertainty, history-count confidence and current layout. Explain equal weights, separate fits, overlapping periods and turning-point lag. Do not sort crossing quantiles; show a notice and preserve article distribution guards.

Verify with targeted/full tests, Pint, lint, build and isolated SQLite-memory export replay against an independent raw-array mean. Do not change frozen research, production or normal databases. No commit/push/deploy is authorized.
