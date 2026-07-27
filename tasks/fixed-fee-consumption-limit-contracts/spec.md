# Fixed-fee consumption-limit contracts

Investigate and correct Vaasan Sähkö Kuukausipaketti contracts that appear as a variable 16.60 c/kWh energy price plus monthly fee.

Verified product semantics from the latest stored seller evidence:
- A fixed monthly fee includes a specified kWh allowance each calendar month.
- Consumption above that monthly allowance costs 16.60 c/kWh.
- The allowance resets monthly; unused kWh do not form an annual pool.
- The upstream `NFirstKwh` value is 12 times the monthly allowance. It is package metadata, not a promotion or strict annual consumption limit.
- The stored 0–100,000 kWh consumption range is a broad availability range, not the package allowance.
- Contracts must be shown as packages with monthly fee, included kWh/month, and excess-use rate.
- Cost calculations must use canonical package pricing and fail closed when package facts are incomplete.
