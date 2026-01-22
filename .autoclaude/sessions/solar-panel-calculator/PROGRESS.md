# Progress Log

## 2026-01-22

### Task: phase1-config - Add Digitransit configuration

**Status:** Completed

**Changes made:**
1. Added Digitransit service configuration to `laravel/config/services.php`:
   - `api_key` - reads from `DIGITRANSIT_API_KEY` environment variable
   - `base_url` - set to `https://api.digitransit.fi/geocoding/v1`

2. Added environment variable to `laravel/.env.example`:
   - `DIGITRANSIT_API_KEY=` with comment explaining where to get the key

**Files modified:**
- `laravel/config/services.php`
- `laravel/.env.example`

---

### Task: phase1-dto - Create GeocodingResult DTO

**Status:** Completed

**Changes made:**
Created `laravel/app/Services/DTO/GeocodingResult.php` with:
- Readonly properties: `label` (string), `lat` (float), `lon` (float)
- Static factory method `fromDigitransitFeature()` that parses Digitransit GeoJSON features
- Handles the GeoJSON [lon, lat] coordinate order correctly

**Files created:**
- `laravel/app/Services/DTO/GeocodingResult.php`

---

### Task: phase1-service - Create DigitransitGeocodingService

**Status:** Completed

**Changes made:**
Created `laravel/app/Services/DigitransitGeocodingService.php` with:
- `search(string $query)` method that returns an array of `GeocodingResult` DTOs
- Calls Digitransit API at `https://api.digitransit.fi/geocoding/v1/autocomplete`
- Sends `digitransit-subscription-key` header with API key from config
- Request parameters: `text={query}`, `layers=address`, `lang=fi`
- Caches results for 7 days with key `digitransit:geocode:{md5(query)}`
- Retry logic: 3 attempts with 500ms delay on server errors

**Files created:**
- `laravel/app/Services/DigitransitGeocodingService.php`

---

### Task: phase1-request - Create GeocodeRequest form request

**Status:** Completed

**Changes made:**
Created `laravel/app/Http/Requests/GeocodeRequest.php` with:
- Validates `q` parameter: required, string, min:2, max:200

**Files created:**
- `laravel/app/Http/Requests/GeocodeRequest.php`

---

### Task: phase1-controller - Create SolarController with geocode endpoint

**Status:** Completed

**Changes made:**
1. Created `laravel/app/Http/Controllers/Api/SolarController.php` with:
   - Constructor injection of `DigitransitGeocodingService`
   - `geocode(GeocodeRequest $request)` method that returns JSON `{data: [{label, lat, lon}, ...]}`

2. Added route to `laravel/routes/api.php`:
   - `GET /api/solar/geocode` pointing to `SolarController::geocode`

**Files created:**
- `laravel/app/Http/Controllers/Api/SolarController.php`

**Files modified:**
- `laravel/routes/api.php`

---

### Task: phase1-rate-limiter - Add rate limiter for geocode API

**Status:** Completed

**Changes made:**
1. Added rate limiter in `laravel/app/Providers/AppServiceProvider.php`:
   - `RateLimiter::for('solar-geocode', ...)` limiting to 60 requests per minute per IP

2. Applied rate limiter to geocode route in `laravel/routes/api.php`:
   - Added `->middleware('throttle:solar-geocode')` to the route

**Files modified:**
- `laravel/app/Providers/AppServiceProvider.php`
- `laravel/routes/api.php`

---

### Task: phase1-tests - Write tests for Phase 1

**Status:** Completed

**Changes made:**
1. Created `laravel/tests/Unit/DigitransitGeocodingServiceTest.php` with tests for:
   - Successful geocoding returns results
   - Empty results handling
   - Correct request parameters (text, layers, lang, header)
   - Exception on API error
   - Caching works correctly
   - Different cache keys for different queries
   - Retry logic on server errors

2. Created `laravel/tests/Feature/SolarGeocodeApiTest.php` with tests for:
   - Valid requests return addresses
   - Empty results return empty array
   - Missing 'q' parameter returns 422
   - Query too short (< 2 chars) returns 422
   - Query too long (> 200 chars) returns 422
   - Valid queries at min/max length accepted
   - Rate limiting works (61st request returns 429)

**Test results:** All 15 tests pass (103 assertions)

**Files created:**
- `laravel/tests/Unit/DigitransitGeocodingServiceTest.php`
- `laravel/tests/Feature/SolarGeocodeApiTest.php`

---

## Phase 2 - PVGIS Solar Production Estimation

### Task: phase2-dtos - Create PVGIS DTOs

**Status:** Completed

**Changes made:**
1. Created `laravel/app/Services/DTO/SolarEstimateRequest.php` with:
   - `lat`, `lon` (required float coordinates)
   - `system_kwp` (default 5.0 kWp)
   - `roof_tilt_deg`, `roof_aspect_deg` (nullable integers)
   - `shading_level` (enum: none/some/heavy, default 'none')

2. Created `laravel/app/Services/DTO/SolarEstimateResult.php` with:
   - `annual_kwh` (float)
   - `monthly_kwh` (array of 12 floats)
   - `assumptions` (array of calculation assumptions)
   - `toArray()` method for JSON serialization

**Test results:** 6 tests pass (21 assertions)

**Files created:**
- `laravel/app/Services/DTO/SolarEstimateRequest.php`
- `laravel/app/Services/DTO/SolarEstimateResult.php`
- `laravel/tests/Unit/SolarEstimateDtoTest.php`

---

### Task: phase2-pvgis-service - Create PvgisService

**Status:** Completed

**Changes made:**
Created `laravel/app/Services/PvgisService.php` with:
- Calls PVGIS API v5.3 at `https://re.jrc.ec.europa.eu/api/v5_3/PVcalc`
- Parameters: lat, lon, peakpower, loss, outputformat=json
- Supports `optimalangles=1` or custom `angle`/`aspect` parameters
- Loss calculation: base 14% + shading (none=0%, some=5%, heavy=12%)
- Parses response: `outputs.totals.fixed.E_y` for annual, `outputs.monthly.fixed[].E_m` for monthly
- Caches results for 30 days with key `pvgis:{md5(params)}`

**Test results:** 11 tests pass (21 assertions)

**Files created:**
- `laravel/app/Services/PvgisService.php`
- `laravel/tests/Unit/PvgisServiceTest.php`

---

### Task: phase2-calculator-service - Create SolarCalculatorService

**Status:** Completed

**Changes made:**
Created `laravel/app/Services/SolarCalculatorService.php` with:
- `calculate(SolarEstimateRequest): SolarEstimateResult` - orchestrates PVGIS API calls
- `roofAreaToSystemKwp(float): float` - converts roof area to kWp (0.20 kWp/m²)

**Test results:** 5 tests pass (8 assertions)

**Files created:**
- `laravel/app/Services/SolarCalculatorService.php`
- `laravel/tests/Unit/SolarCalculatorServiceTest.php`

---

### Task: phase2-estimate-request - Create SolarEstimateFormRequest

**Status:** Completed

**Changes made:**
Created `laravel/app/Http/Requests/SolarEstimateFormRequest.php` with validation rules:
- `lat`: required, numeric, between -90 and 90
- `lon`: required, numeric, between -180 and 180
- `system_kwp`: nullable, numeric, between 0.1 and 100
- `roof_tilt_deg`: nullable, integer, between 0 and 90
- `roof_aspect_deg`: nullable, integer, between -180 and 180
- `shading_level`: nullable, in:none,some,heavy

**Test results:** 24 tests pass (44 assertions)

**Files created:**
- `laravel/app/Http/Requests/SolarEstimateFormRequest.php`
- `laravel/tests/Unit/SolarEstimateRequestValidationTest.php`

---

### Task: phase2-controller-estimate - Add estimate endpoint to SolarController

**Status:** Completed

**Changes made:**
1. Added `estimate(SolarEstimateFormRequest)` method to `SolarController`
2. Added route: `POST /api/solar/estimate`
3. Returns JSON: `{data: {annual_kwh, monthly_kwh, assumptions}}`

**Files modified:**
- `laravel/app/Http/Controllers/Api/SolarController.php`
- `laravel/routes/api.php`

---

### Task: phase2-tests - Write tests for Phase 2

**Status:** Completed

**Test results:** All Phase 2 tests pass
- `SolarEstimateDtoTest`: 6 tests (21 assertions)
- `PvgisServiceTest`: 11 tests (21 assertions)
- `SolarCalculatorServiceTest`: 5 tests (8 assertions)
- `SolarEstimateRequestValidationTest`: 24 tests (44 assertions)
- `SolarEstimateApiTest`: 11 tests (30 assertions)

**Total Phase 2:** 57 tests (124 assertions)

**Files created:**
- `laravel/tests/Feature/SolarEstimateApiTest.php`
