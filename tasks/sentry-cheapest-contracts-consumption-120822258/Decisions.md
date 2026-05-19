# Decisions

- Root cause is a stale/partially hydrated Livewire request reaching `CheapestContracts` while inherited SEO listing code reads `$this->consumption`; if that URL-bound public property is absent from the snapshot, Livewire's magic getter throws `PropertyNotFoundException`.
- Added `ContractsList::selectedConsumptionValue()` as a defensive reader. It uses `isset($this->consumption)` to avoid Livewire `__get`, restores the default 5 000 kWh when missing/non-numeric, and returns an integer for calculations/cache keys.
- Updated `SeoContractsList` contract calculation/cache-key code and `CheapestContracts` intro/render data to use the defensive reader, preserving normal behavior while making stale Livewire updates safe.
