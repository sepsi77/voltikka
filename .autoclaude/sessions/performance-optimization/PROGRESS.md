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

## 2026-01-22: Replace Tailwind CDN with compiled CSS via Vite

**Task:** setup-vite-tailwind
**Status:** Completed

### Background

The site was using `cdn.tailwindcss.com` as a fallback when the Vite build manifest didn't exist. The Vite/Tailwind configuration was already in place but hadn't been built.

### Configuration Already Present

- `tailwind.config.js` - Complete with custom coral/slate color palette
- `postcss.config.js` - Configured with tailwindcss and autoprefixer
- `resources/css/app.css` - Has @tailwind directives and CSS custom properties
- `vite.config.js` - Configured for Laravel with CSS and JS entrypoints
- `package.json` - Dependencies already listed

### Actions Taken

1. **Installed npm dependencies** - `npm install` added 50 packages
2. **Ran Vite build** - `npm run build` compiled assets

### Build Output

```
public/build/manifest.json             0.27 kB │ gzip:  0.15 kB
public/build/assets/app-MxJ4VHYK.css  57.32 kB │ gzip:  9.91 kB
public/build/assets/app-CAiCLEjY.js   36.35 kB │ gzip: 14.71 kB
```

### Expected Impact

- **Before:** Site loaded `cdn.tailwindcss.com` script (~120KB unminified) + JIT compilation at runtime
- **After:** Site loads pre-compiled CSS (57KB, ~10KB gzipped) directly
- **Benefits:**
  - Faster page load (no runtime compilation)
  - Smaller file size (only used utilities compiled)
  - No external CDN dependency
  - Production-ready asset optimization

### Tests

All 147 tests pass.

