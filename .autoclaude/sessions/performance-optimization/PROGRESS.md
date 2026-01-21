# Progress Log

## 2026-01-21: Add lazy loading to images

**Task:** add-lazy-loading
**Status:** Completed

### Changes Made

Added `loading="lazy"` attribute to all company logo images across the following files:

1. **`resources/views/components/contract-card.blade.php`** (line 124)
   - Logo in contract cards used throughout the main contracts list

2. **`resources/views/components/featured-contract-card.blade.php`** (line 76)
   - Logo in featured/top contract card

3. **`resources/views/livewire/company-detail.blade.php`** (line 59)
   - Logo in company detail hero section

4. **`resources/views/livewire/contract-detail.blade.php`** (line 23)
   - Logo in contract detail hero section

5. **`resources/views/livewire/company-list.blade.php`** (lines 198, 254)
   - Logos in "100% renewable" company section
   - Logos in "All companies" listing section

### Expected Impact

- **Before:** All 298 images loaded immediately on page load
- **After:** Only images above the fold load immediately; below-fold images load as user scrolls
- **Expected improvement:** Significant reduction in initial page load time and data transfer

### Tests

All 147 tests pass (570 deprecation warnings unrelated to this change - PDO SSL and PHPUnit annotation syntax).

