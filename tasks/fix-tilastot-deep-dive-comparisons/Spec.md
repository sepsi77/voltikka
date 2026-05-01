# Fix `/sahkosopimus/tilastot` deep-dive comparisons

The deep-dive quote blocks currently compare non-spot current c/kWh prices against the current spot c/kWh (`spot_total_energy_price`). This can create misleading claims on unusually cheap/expensive spot days, e.g. a fixed 6-month contract appearing 335% more expensive than pörssisähkö because spot is 1.77 c/kWh that day.

Change non-spot deep-dive quotable comparisons to use annual cost at the selected consumption level (`annual_cost`) instead of current c/kWh. Spot annual cost already uses the trailing-365-day spot average plus margin, so it is a better basis for contract-type cost comparisons.

The c/kWh stats and charts in the deep-dive should remain as-is; only the quotable “vs pörssisähkö” comparison should change.
