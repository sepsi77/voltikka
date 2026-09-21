# Bounded morning consumers

Implement local independent five-minute retail and forecast consumers, from 07:15 and 07:30 through 12:00 Europe/Helsinki. Preserve all freshness gates and forecast statistics-order recovery. Use durable per-date claims in existing freshness checkpoints, shared manual writer exclusion, terminal uncertain execution, and one deadline alert. Scheduled scope is current-day default only. Dry runs remain immutable. Record explicit running producer status and fence EEX final writes by owner. Do not change evaluation or add producer retries, migrations, deployment, commits, or production operations.

Verify command, claim, schedule, producer, and regression behavior. Keep the existing source-validated-energy-rules task files untouched.
