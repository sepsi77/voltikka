<?php

use App\Livewire\CompanyDetail;
use App\Livewire\ConsumptionCalculator;
use App\Livewire\ContractDetail;
use App\Livewire\ContractsList;
use App\Livewire\LocationsList;
use App\Livewire\SeoContractsList;
use App\Livewire\SpotPrice;
use App\Services\SitemapService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

// Main pages (keep at root level)
Route::get('/', ContractsList::class);
Route::get('/spot-price', SpotPrice::class)->name('spot-price');

// Sitemap (cached for 7 days)
Route::get('/sitemap.xml', function (SitemapService $sitemapService) {
    $xml = Cache::remember('sitemap_xml', 604800, function () use ($sitemapService) {
        return $sitemapService->generateXml();
    });

    return response($xml, 200, [
        'Content-Type' => 'text/xml; charset=UTF-8',
        'Cache-Control' => 'public, max-age=604800',
    ]);
})->name('sitemap');

// =============================================================================
// All electricity contract related pages under /sahkosopimus/
// =============================================================================

// Calculator
Route::get('/sahkosopimus/laskuri', ConsumptionCalculator::class)->name('calculator');

// Contract detail
Route::get('/sahkosopimus/sopimus/{contractId}', ContractDetail::class)->name('contract.detail');

// Company detail
Route::get('/sahkosopimus/yritys/{companySlug}', CompanyDetail::class)->name('company.detail');

// Location pages
Route::get('/sahkosopimus/paikkakunnat/{location?}', LocationsList::class)->name('locations');

// SEO Housing Type Routes
Route::get('/sahkosopimus/omakotitalo', SeoContractsList::class)
    ->name('seo.housing.omakotitalo')
    ->defaults('housingType', 'omakotitalo');
Route::get('/sahkosopimus/kerrostalo', SeoContractsList::class)
    ->name('seo.housing.kerrostalo')
    ->defaults('housingType', 'kerrostalo');
Route::get('/sahkosopimus/rivitalo', SeoContractsList::class)
    ->name('seo.housing.rivitalo')
    ->defaults('housingType', 'rivitalo');

// SEO Energy Source Routes
Route::get('/sahkosopimus/tuulisahko', SeoContractsList::class)
    ->name('seo.energy.tuulisahko')
    ->defaults('energySource', 'tuulisahko');
Route::get('/sahkosopimus/aurinkosahko', SeoContractsList::class)
    ->name('seo.energy.aurinkosahko')
    ->defaults('energySource', 'aurinkosahko');
Route::get('/sahkosopimus/vihrea-sahko', SeoContractsList::class)
    ->name('seo.energy.vihrea-sahko')
    ->defaults('energySource', 'vihrea-sahko');

// SEO Pricing Type Routes
Route::get('/sahkosopimus/porssisahko', SeoContractsList::class)
    ->name('seo.pricing.porssisahko')
    ->defaults('pricingType', 'Spot');
Route::get('/sahkosopimus/kiintea-hinta', SeoContractsList::class)
    ->name('seo.pricing.kiintea-hinta')
    ->defaults('pricingType', 'FixedPrice');

// SEO City Routes (must come AFTER specific routes to avoid overriding them)
Route::get('/sahkosopimus/{city}', SeoContractsList::class)
    ->name('seo.city')
    ->where('city', '[a-z0-9-]+');

// =============================================================================
// 301 Redirects from old URLs to new URLs (for SEO preservation)
// =============================================================================

// Redirect old /laskuri to new location
Route::redirect('/laskuri', '/sahkosopimus/laskuri', 301);

// Redirect old /sopimus/{id} to new location
Route::get('/sopimus/{contractId}', function ($contractId) {
    return redirect()->route('contract.detail', ['contractId' => $contractId], 301);
});

// Redirect old /yritys/{slug} to new location
Route::get('/yritys/{companySlug}', function ($companySlug) {
    return redirect()->route('company.detail', ['companySlug' => $companySlug], 301);
});

// Redirect old /paikkakunnat to new location
Route::get('/paikkakunnat/{location?}', function ($location = null) {
    if ($location) {
        return redirect()->route('locations', ['location' => $location], 301);
    }
    return redirect()->route('locations', [], 301);
});
