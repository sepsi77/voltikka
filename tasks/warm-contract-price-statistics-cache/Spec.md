# Warm contract price statistics cache

Goal: after contract price statistics or spot price data updates, warm `/sahkosopimus/tilastot?kulutus=5000` prepared view-data cache in the background so public visitors do not pay the slow cache-miss rebuild cost.

Requirements:
- Use asynchronous/background cache warming where possible.
- Keep existing cache invalidation/fingerprinting semantics.
- Warm at least the default page state: weekly period, 5000 kWh.
- Wire warming after contract statistics recalculation and spot-price average updates because both affect the page cache key.
