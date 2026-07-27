# Company detail search-intent copy

Update the company detail price and spot-electricity sections so they answer their target search intent directly.

## Requirements

- The price section must answer `[company] hinta` and `[company] sähkön hinta`: explain what the shown price is, how it varies by contract type and consumption, and how the company compares with the market median.
- The spot section must answer `[company] pörssisähkö`: state whether the company sells spot contracts and show whether its supplier-controlled charges are competitive.
- Compare current Spot margins and monthly base fees with same-basis market medians when current compatible statistics exist.
- Use canonical pricing/current canonical statistics when canonical mode is active. Do not silently compare current canonical contract facts with historical observed benchmarks.
- Keep feature-off behavior consistent with observed current statistics.
- Keep copy natural Finnish and do not make unsupported claims.

## Status

Implemented and verified in `CompanyDetailSectionsTest`.
