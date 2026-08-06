# Production Spot price outage

Investigate why the production header and Spot price page do not have current official Spot data. Identify the cause with read-only production checks. Correct the Finnish unavailable-state copy in both header render paths. Limit the hourly Spot import's orphan overlap lock to 60 minutes, add explicit ENTSO-E connection and request timeouts, and add focused regression tests. Do not mutate or deploy production without explicit confirmation.
