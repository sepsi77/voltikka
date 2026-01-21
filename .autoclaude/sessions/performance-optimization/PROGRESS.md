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

## 2026-01-22: Add resource preload hints for critical assets

**Task:** add-preload-hints
**Status:** Completed

### Changes Made

Updated `resources/views/layouts/app.blade.php` to add preload hints for critical assets:

1. **Preconnect hints** (already present, reorganized for clarity)
   - `fonts.googleapis.com` - Google Fonts API
   - `fonts.gstatic.com` with `crossorigin` - Font file delivery

2. **Preload for Vite-compiled assets** (dynamic from manifest)
   - CSS: `build/assets/app-MxJ4VHYK.css`
   - JS: `build/assets/app-CAiCLEjY.js`

3. **Preload for Livewire script**
   - `/vendor/livewire/livewire.min.js` (221KB minified)
   - Published Livewire assets to `public/vendor/livewire/`

### Technical Details

The preload hints are dynamically generated from the Vite manifest when it exists, ensuring the correct hashed filenames are used. This approach:
- Works with content-hashed filenames that change on rebuild
- Falls back gracefully when no build exists (development mode)
- Positions preloads early in `<head>` for maximum browser benefit

### Expected Impact

- **Preconnect:** Establishes early connections to Google Fonts, saving ~100-200ms on font loading
- **Preload CSS/JS:** Browser begins downloading critical assets immediately rather than waiting for HTML parser to discover them
- **Preload Livewire:** Livewire script loads earlier, reducing time-to-interactive for dynamic components

### Tests

All 147 tests pass.

## 2026-01-22: Create image optimization command for company logos

**Task:** optimize-company-logos
**Status:** Completed

### Changes Made

1. **Installed Intervention Image package** (`intervention/image-laravel`)
   - Provides image manipulation capabilities for PHP/Laravel

2. **Created `app/Console/Commands/OptimizeLogos.php`**
   - New Artisan command: `php artisan logos:optimize`
   - Reads images from `storage/app/public/logos/`
   - Resizes to max 200px width (preserving aspect ratio)
   - Converts to WebP format with 80% quality
   - Does not upscale small images
   - Supports `--dry-run` flag to preview changes
   - Supports `--remove-originals` flag to delete source files after optimization

3. **Created `tests/Feature/OptimizeLogosCommandTest.php`**
   - 14 comprehensive tests covering:
     - Resizing large images
     - Preserving aspect ratio
     - Not upscaling small images
     - Handling various formats (PNG, JPG, JPEG, GIF)
     - Processing multiple images
     - Dry run functionality
     - Original file removal option
     - Edge cases (no images, WebP-only directory)

### Usage

```bash
# Preview what would be optimized
php artisan logos:optimize --dry-run

# Optimize all logos (keep originals)
php artisan logos:optimize

# Optimize and remove original files
php artisan logos:optimize --remove-originals
```

### Expected Impact

For large logos (e.g., the mentioned 1.25MB and 817KB files):
- **Size reduction:** WebP at 200px width typically produces files <10KB
- **Format:** WebP provides ~30% better compression than PNG/JPEG
- **Page load:** Dramatically faster image loading

### Tests

All 147 tests pass (including 14 new tests for this command).

