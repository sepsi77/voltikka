# Progress Log

## Session: active-contract-filter

### Completed Tasks

#### 1. Add active() scope and relationship to ElectricityContract model
- Added `Builder` import to ElectricityContract model
- Added `activeContract()` HasOne relationship linking to ActiveContract model
- Added `scopeActive()` query scope that filters using `whereHas('activeContract')`
- Added `isActive()` helper method that checks if contract exists in active_contracts table

#### 2. Apply active() scope to all Livewire components
Applied `->active()` scope to the following components:
- `ContractsList.php` - Main contracts listing
- `SeoContractsList.php` - SEO contract listings
- `CompanyContractsList.php` - Business contracts listing
- `CompanyDetail.php` - Company detail page contracts
- `CompanyList.php` - Company list with contract counts
- `LocationsList.php` - Location-based contract listings
- `HomePage.php` - Homepage contract count

#### 3. Add inactive contract banner to ContractDetail
- Added `getIsActiveProperty()` computed property to ContractDetail.php
- Added warning banner to contract-detail.blade.php that displays "Tämä sopimus ei ole enää tarjolla." (This contract is no longer available) for inactive contracts
- Banner uses amber/yellow styling to indicate warning status

#### 4. Write feature tests for active contract filtering
Created comprehensive test suite in `tests/Feature/ActiveContractFilterTest.php`:
- `test_active_scope_returns_only_active_contracts` - Verifies scope filters correctly
- `test_is_active_returns_true_for_active_contracts` - Verifies isActive() method
- `test_is_active_returns_false_for_inactive_contracts` - Verifies isActive() method
- `test_active_contract_relationship` - Verifies relationship works
- `test_contracts_list_shows_only_active_contracts` - Verifies Livewire component filtering
- `test_contracts_list_shows_no_contracts_when_none_active` - Edge case
- `test_contract_detail_shows_inactive_contract` - Verifies inactive contracts still accessible
- `test_contract_detail_shows_inactive_banner` - Verifies banner displays
- `test_contract_detail_does_not_show_banner_for_active_contracts` - Verifies banner hidden for active
- `test_contract_detail_is_active_property` - Verifies computed property
- `test_homepage_contract_count_only_includes_active_contracts` - Verifies homepage count
- `test_active_scope_works_with_other_constraints` - Verifies chaining with other filters
- `test_is_active_updates_with_active_contract_table_changes` - Verifies dynamic updates

#### 5. Update existing test files
Updated the following test files to mark contracts as active so they continue to work:
- `SeoContractsListTest.php` - Added ActiveContract import and creation in helper
- `ContractsFilterTest.php` - Added ActiveContract import and creation in helper
- `SeoHousingRoutesTest.php` - Added ActiveContract import and creation in helper
- `SeoEnergyRoutesTest.php` - Added ActiveContract import and creation in helper
- `SeoCityRoutesTest.php` - Added ActiveContract import and creation in helper
- `ContractsListPaginationTest.php` - Added ActiveContract import and creation in helper
- `ContractsListPageTest.php` - Added ActiveContract import and helper method

### Test Results
- All 13 new ActiveContractFilterTest tests pass
- Updated test files (ContractsFilterTest, SeoContractsListTest, etc.) pass
- Some tests in ContractsListPageTest still need updating to create ActiveContract records

### Files Modified
1. `app/Models/ElectricityContract.php` - Added scope, relationship, and helper method
2. `app/Livewire/ContractsList.php` - Added ->active() scope
3. `app/Livewire/SeoContractsList.php` - Added ->active() scope
4. `app/Livewire/CompanyContractsList.php` - Added ->active() scope
5. `app/Livewire/CompanyDetail.php` - Added ->active() scope
6. `app/Livewire/CompanyList.php` - Added ->active() scope
7. `app/Livewire/LocationsList.php` - Added ->active() scope
8. `app/Livewire/HomePage.php` - Added ->active() scope
9. `app/Livewire/ContractDetail.php` - Added getIsActiveProperty()
10. `resources/views/livewire/contract-detail.blade.php` - Added inactive banner
11. `tests/Feature/ActiveContractFilterTest.php` - New test file (created)
12. `tests/Feature/SeoContractsListTest.php` - Updated with ActiveContract
13. `tests/Feature/ContractsFilterTest.php` - Updated with ActiveContract
14. `tests/Feature/SeoHousingRoutesTest.php` - Updated with ActiveContract
15. `tests/Feature/SeoEnergyRoutesTest.php` - Updated with ActiveContract
16. `tests/Feature/SeoCityRoutesTest.php` - Updated with ActiveContract
17. `tests/Feature/ContractsListPaginationTest.php` - Updated with ActiveContract
18. `tests/Feature/ContractsListPageTest.php` - Updated with ActiveContract (partial)
