# Progress Log

## Session: seo-url-restructure

### Task 1: Analyze current route structure and create migration plan

**Status:** Completed
**Completed:** 2026-01-21

#### Current Route Analysis

##### Current Routes (from `laravel/routes/web.php`)

| Current URL | Component | Route Name | Description |
|-------------|-----------|------------|-------------|
| `/` | ContractsList | (none) | Main contracts listing |
| `/laskuri` | ConsumptionCalculator | calculator | Consumption calculator |
| `/sopimus/{contractId}` | ContractDetail | contract.detail | Individual contract view |
| `/paikkakunnat/{location?}` | LocationsList | locations | Browse by municipality |
| `/yritys/{companySlug}` | CompanyDetail | company.detail | Company profile |
| `/spot-price` | SpotPrice | spot-price | Spot price display |
| `/sitemap.xml` | SitemapService | sitemap | XML sitemap |
| `/sahkosopimus/omakotitalo` | SeoContractsList | seo.housing.omakotitalo | SEO: detached house |
| `/sahkosopimus/kerrostalo` | SeoContractsList | seo.housing.kerrostalo | SEO: apartment |
| `/sahkosopimus/rivitalo` | SeoContractsList | seo.housing.rivitalo | SEO: row house |
| `/sahkosopimus/tuulisahko` | SeoContractsList | seo.energy.tuulisahko | SEO: wind power |
| `/sahkosopimus/aurinkosahko` | SeoContractsList | seo.energy.aurinkosahko | SEO: solar power |
| `/sahkosopimus/vihrea-sahko` | SeoContractsList | seo.energy.vihrea-sahko | SEO: green energy |
| `/sahkosopimus/{city}` | SeoContractsList | seo.city | SEO: city-specific |

##### Proposed URL Migration Plan

| Old URL | New URL | 301 Redirect | Notes |
|---------|---------|--------------|-------|
| `/sopimus/{id}` | `/sahkosopimus/sopimus/{id}` | Yes | Contract detail pages |
| `/yritys/{slug}` | `/sahkosopimus/yritys/{slug}` | Yes | Company profile pages |
| `/paikkakunnat` | `/sahkosopimus/paikkakunnat` | Yes | Location listing page |
| `/paikkakunnat/{location}` | `/sahkosopimus/paikkakunnat/{location}` | Yes | Location detail pages |
| `/laskuri` | `/sahkosopimus/laskuri` | Yes | Calculator page |
| `/` | `/` | No | Keep homepage at root |
| `/spot-price` | `/spot-price` | No | Keep spot price at current location |
| `/sahkosopimus/*` | `/sahkosopimus/*` | No | Already correct |

---

### Task 2: Make location pages SEO-friendly

**Status:** Completed
**Completed:** 2026-01-21

#### Changes Made
- Changed municipality cards from `wire:click` buttons to proper `<a href>` links in `locations-list.blade.php`
- Updated "back to locations" button to use proper link instead of `wire:click`
- Removed unused `selectMunicipality()` and `clearSelection()` methods from `LocationsList.php`
- Added `getMunicipalitySlugProperty()` helper method

---

### Tasks 3-6: Move routes under /sahkosopimus/

**Status:** Completed
**Completed:** 2026-01-21

#### Changes Made to `routes/web.php`
- Moved `/laskuri` → `/sahkosopimus/laskuri`
- Moved `/sopimus/{contractId}` → `/sahkosopimus/sopimus/{contractId}`
- Moved `/yritys/{companySlug}` → `/sahkosopimus/yritys/{companySlug}`
- Moved `/paikkakunnat/{location?}` → `/sahkosopimus/paikkakunnat/{location?}`
- Added 301 redirects from all old URLs to new URLs

---

### Task 7: Update internal links

**Status:** Completed
**Completed:** 2026-01-21

#### Changes Made
- Updated navigation links in `layouts/app.blade.php`:
  - `/laskuri` → `{{ route('calculator') }}`
  - `/paikkakunnat` → `{{ route('locations') }}`
  - Updated `request()->is()` checks for active state detection
- Updated internal link in `seo-contracts-list.blade.php`:
  - `/paikkakunnat` → `{{ route('locations') }}`

---

### Task 8: Verify contract card usage

**Status:** Completed
**Completed:** 2026-01-21

#### Verification
Confirmed that LocationsList uses the same `<x-contract-card>` component as other listings:
- LocationsList: `:showRank="false"`, `:showEmissions="false"`, `:showEnergyBadges="true"`, `:showSpotBadge="false"`
- SeoContractsList: `:showRank="false"`, `:showEmissions="true"`, `:showEnergyBadges="true"`, `:showSpotBadge="true"`
- ContractsList: `:consumption`, `:prices`, `:showRank="false"`, `:showEmissions="true"`, etc.

All use the same component with appropriate props for each context.

---

### Task 9: Update sitemap

**Status:** Completed
**Completed:** 2026-01-21

#### Changes Made to `SitemapService.php`
- Updated `/paikkakunnat` → `/sahkosopimus/paikkakunnat`
- Added `/sahkosopimus/laskuri` to sitemap
- Added new `getMunicipalityUrls()` method to include all municipality pages under `/sahkosopimus/paikkakunnat/{slug}`
- Updated test in `SitemapTest.php` to expect new URL structure

#### Test Results
All 29 sitemap tests pass.

---

## Summary of Changes

### Files Modified
1. `laravel/routes/web.php` - Route restructuring with 301 redirects
2. `laravel/resources/views/layouts/app.blade.php` - Navigation links
3. `laravel/resources/views/livewire/locations-list.blade.php` - SEO-friendly links
4. `laravel/resources/views/livewire/seo-contracts-list.blade.php` - Internal links
5. `laravel/app/Livewire/LocationsList.php` - Removed wire:click methods
6. `laravel/app/Services/SitemapService.php` - URL generation
7. `laravel/tests/Feature/SitemapTest.php` - Test updates

### New URL Structure
| Old URL | New URL |
|---------|---------|
| `/` | `/` (unchanged) |
| `/spot-price` | `/spot-price` (unchanged) |
| `/laskuri` | `/sahkosopimus/laskuri` |
| `/sopimus/{id}` | `/sahkosopimus/sopimus/{id}` |
| `/yritys/{slug}` | `/sahkosopimus/yritys/{slug}` |
| `/paikkakunnat` | `/sahkosopimus/paikkakunnat` |
| `/paikkakunnat/{location}` | `/sahkosopimus/paikkakunnat/{location}` |
| `/sahkosopimus/*` | `/sahkosopimus/*` (unchanged) |

### 301 Redirects Added
All old URLs now redirect to their new locations with 301 status for SEO preservation.

