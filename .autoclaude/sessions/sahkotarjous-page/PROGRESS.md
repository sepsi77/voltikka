# Progress Log

## 2026-01-25: Implemented sähkötarjous page

### Completed Tasks

1. **Added discount helper methods to ElectricityContract model**
   - Added `hasActiveDiscounts(): bool` method that checks if a contract has active discounts by:
     - Checking the `pricing_has_discounts` flag
     - Checking if any price component has `has_discount=true` with a non-expired `discount_discount_until_date`
   - Added `getActiveDiscountInfo(): ?array` method that returns discount details (value, is_percentage, n_first_months, until_date)
   - File: `app/Models/ElectricityContract.php`

2. **Added route for /sahkosopimus/sahkotarjous**
   - Added SEO promotion route before the city catch-all route
   - Route uses `SeoContractsList` component with `offerType='promotion'` default
   - File: `routes/web.php`

3. **Added offerType filter to SeoContractsList component**
   - Added `$offerType` property
   - Updated `mount()` to accept and assign offerType parameter
   - Added promotion filter logic in `getContractsProperty()` that filters contracts with active discounts
   - File: `app/Livewire/SeoContractsList.php`

4. **Added SEO metadata for sahkotarjous page**
   - Updated `generateSeoTitle()` - returns "Sähkötarjoukset ja alennukset | Voltikka"
   - Updated `generateMetaDescription()` - returns promotion-focused description
   - Updated `generateCanonicalUrl()` - returns /sahkosopimus/sahkotarjous
   - Updated `getPageHeadingProperty()` - returns "Sähkötarjoukset ja alennukset"
   - Updated `getSeoIntroTextProperty()` - returns intro text about promotions
   - Updated `getHasSeoFilterProperty()` - includes offerType check
   - File: `app/Livewire/SeoContractsList.php`

5. **Added promotion badge to contract card component**
   - Added badge display logic that checks `hasActiveDiscounts()`
   - Badge shows "X kk tarjous" if n_first_months is set, otherwise "Tarjous"
   - Uses amber/yellow gradient styling matching existing badge patterns
   - File: `resources/views/components/contract-card.blade.php`

6. **Added sahkotarjous page to sitemap**
   - Added `getOfferUrls()` method returning the sahkotarjous URL
   - Set changefreq='daily' and priority=0.85
   - Included in `getAllUrls()` method
   - File: `app/Services/SitemapService.php`

7. **Added promotion info to JSON-LD schema**
   - Updated `generateJsonLd()` to include discount info for contracts with active promotions
   - Adds `priceValidUntil` if until_date exists
   - Adds `description` with discount details (e.g., "-X% ensimmäiset Y kk")
   - File: `app/Livewire/SeoContractsList.php`

### Verification

- All PHP files pass syntax check (`php -l`)
- Route is registered: `GET|HEAD sahkosopimus/sahkotarjous → seo.offer.sahkotarjous`
- ElectricityContract unit tests pass
- Test suite has pre-existing transaction errors unrelated to these changes
