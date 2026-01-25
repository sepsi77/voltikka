# Progress Log

## 2026-01-25 - City Page URL Unification Complete

### Summary
Successfully unified city page URLs from `/sahkosopimus/{city}` to `/sahkosopimus/paikkakunnat/{city}` pattern with 301 redirects from old URLs.

### Changes Made

1. **Routes (routes/web.php)**
   - Split `/sahkosopimus/paikkakunnat/{location?}` into two routes:
     - `/sahkosopimus/paikkakunnat` → LocationsList (municipality browser)
     - `/sahkosopimus/paikkakunnat/{location}` → SeoContractsList (city pages)
   - Changed old city route to 301 redirect: `/sahkosopimus/{city}` → `/sahkosopimus/paikkakunnat/{city}`
   - Updated old paikkakunnat redirect to use new city URL pattern

2. **SeoContractsList Component**
   - Added `$location` parameter to mount() method
   - Maps `$location` to internal `$city` property
   - Updated canonical URL generation to use `/sahkosopimus/paikkakunnat/{city}`

3. **LocationsList Component**
   - Removed `$location` parameter and `$selectedMunicipality` handling
   - Simplified to only handle municipality browser functionality
   - Removed contract display (now handled by SeoContractsList for city pages)

4. **Views**
   - Updated locations-list.blade.php to use new city URLs
   - Removed individual city view section
   - Updated footer city links in app.blade.php

5. **SitemapService**
   - Updated getCityUrls() to generate `/sahkosopimus/paikkakunnat/{slug}`
   - Removed redundant getMunicipalityUrls() method

6. **Tests**
   - Updated all city route tests to use new URL pattern
   - Added 301 redirect tests
   - Added tests for route conflict prevention
   - Added municipality test data for locative form tests

### Test Results
- All 36 SeoCityRoutesTest tests pass
- All 65 combined SEO/city tests pass
- Pre-existing SpotPriceComponentTest failures are unrelated (database transaction issue)

### URL Changes Summary
| Old URL | New URL | Status |
|---------|---------|--------|
| /sahkosopimus/helsinki | /sahkosopimus/paikkakunnat/helsinki | 301 redirect |
| /sahkosopimus/paikkakunnat | /sahkosopimus/paikkakunnat | Same |
| /sahkosopimus/omakotitalo | /sahkosopimus/omakotitalo | Unchanged |
| /sahkosopimus/tuulisahko | /sahkosopimus/tuulisahko | Unchanged |
