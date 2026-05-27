# Decisions

- Recent commit `5b54e9f` moved the header spot price from Livewire render to a lazy API shell, but the extracted `HeaderSpotPriceService` only queried quarter-hour prices. Restored the previous hourly fallback so the badge becomes active whenever the current hourly spot row exists.
- Recent commit `33b5f07` split navigation into two hover dropdowns. The desktop dropdown panels used `mt-1`, creating a physical pointer gap between trigger and menu; removed that gap so the Alpine hover parent does not close before secondary items can be clicked.
- Added focused service tests for quarter preference and hourly fallback.
