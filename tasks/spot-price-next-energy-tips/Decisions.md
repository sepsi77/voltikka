# Decisions

- Treat the current clock hour as non-actionable for appliance recommendations. Users cannot reliably start a full one-hour or multi-hour task at the top of an hour once that hour is already underway.
- Keep the existing short data cache; the stale recommendation issue is caused by time-window filtering, not by long-lived cached recommendation output.
- The “Säästät X €” amount means estimated savings compared with the most expensive valid slot in the same permitted window for that appliance. The card now shows the comparison basis below the savings amount.
