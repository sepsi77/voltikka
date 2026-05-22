# EEX futures EOD collection

Collect electricity futures end-of-day settlement data once per day so Voltikka starts accumulating its own futures pricing dataset.

Requirements:
- Fetch data from EEX chart EOD endpoint with `Referer: https://www.eex.com/` header.
- Persist futures end-of-day price points into the database.
- Account for the upstream API returning at most roughly 45 days of historical data.
- Collect more than Finland; include at least Nordics and Baltics where supported by the API.
- Provide an Artisan command suitable for daily scheduling.
