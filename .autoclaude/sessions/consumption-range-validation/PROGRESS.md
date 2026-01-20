# Session Progress: consumption-range-validation

## Session Goal
Ensure consumption presets respect contract min/max kWh limits. Hide or disable consumption choices that fall outside a contract's allowed range.

## Completed Tasks

### Task 1: Add helper method to ElectricityContract model
- Added `isConsumptionInRange(int|float $consumption): bool` method to ElectricityContract model
- Added `hasConsumptionLimits(): bool` helper method
- Method checks if a given consumption value falls within the contract's `consumption_limitation_min_x_kwh_per_y` and `consumption_limitation_max_x_kwh_per_y` bounds
- If either limit is null, treats it as no restriction on that side
- Added 8 unit tests covering all scenarios:
  - No limits set
  - Min limit only
  - Max limit only
  - Both limits
  - Zero min limit edge case
  - hasConsumptionLimits tests

### Task 2: Filter consumption presets in ContractDetail component
- Changed `$presets` from public property to computed property via `getPresetsProperty()`
- Filters presets to only include values within the contract's consumption range
- Added `clampConsumption()` helper method to enforce limits
- Updated `mount()` to adjust default consumption if outside range
- Updated `setConsumption()` to clamp values within allowed range
- Added 5 feature tests:
  - Preset filtering with min limit
  - Preset filtering with max limit
  - Preset filtering with both limits
  - Default consumption adjustment
  - setConsumption respects limits

### Task 3: Add UI feedback for consumption constraints
- Added consumption limit notice in contract-detail.blade.php
- Displays amber-colored info box when contract has limits
- Shows formatted limits (min, max, or range)
- Uses Finnish text for good UX
- Added test for consumption limits notice display

### Task 4: Consider consumption range in ContractsList filtering
- Added filter in `getContractsProperty()` to exclude contracts where selected consumption is outside their allowed range
- Uses the `isConsumptionInRange()` helper method
- Added 3 feature tests:
  - Filter by min consumption limit
  - Filter by max consumption limit
  - Filter by both limits

## Test Results
- All 20 ElectricityContractTest tests pass
- All 32 ContractDetailPageTest tests pass
- All 53 ContractsListPageTest tests pass
- All 67 consumption-related tests pass

## Files Modified
- `app/Models/ElectricityContract.php` - Added isConsumptionInRange() and hasConsumptionLimits() methods
- `app/Livewire/ContractDetail.php` - Added preset filtering, consumption clamping
- `resources/views/livewire/contract-detail.blade.php` - Added consumption limits notice UI
- `app/Livewire/ContractsList.php` - Added consumption range filter
- `tests/Unit/ElectricityContractTest.php` - Added 8 new tests
- `tests/Feature/ContractDetailPageTest.php` - Added 6 new tests
- `tests/Feature/ContractsListPageTest.php` - Added 3 new tests
