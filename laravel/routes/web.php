<?php

use App\Livewire\CheapestContracts;
use App\Livewire\CompanyContractsList;
use App\Livewire\CompanyDetail;
use App\Livewire\CompanyList;
use App\Livewire\ConsumptionCalculator;
use App\Livewire\ContractDetail;
use App\Livewire\ContractsList;
use App\Livewire\HomePage;
use App\Livewire\LocationsList;
use App\Livewire\SahkosopimusIndex;
use App\Livewire\SeoContractsList;
use App\Livewire\SolarCalculator;
use App\Livewire\SpotPrice;
use App\Services\SitemapService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

// Main pages (keep at root level)
Route::get('/', HomePage::class);
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

// Solar panel section
Route::get('/aurinkopaneelit/laskuri', SolarCalculator::class)->name('solar.calculator');
Route::redirect('/aurinkopaneelit', '/aurinkopaneelit/laskuri', 302);

// Heat pump section
Route::get('/lampopumput/laskuri', \App\Livewire\HeatPumpCalculator::class)->name('heatpump.calculator');
Route::redirect('/lampopumput', '/lampopumput/laskuri', 302);

// Contract detail
Route::get('/sahkosopimus/sopimus/{contractId}', ContractDetail::class)
    ->name('contract.detail');

// Old company detail URL - redirect to new location
// (Route added in redirects section below)

// Location pages
// Municipality browser (no param) - lists all municipalities for browsing
Route::get('/sahkosopimus/paikkakunnat', LocationsList::class)->name('locations');

// City pages with local flavor - individual city contract listings
Route::get('/sahkosopimus/paikkakunnat/{location}', SeoContractsList::class)
    ->name('seo.city')
    ->where('location', '[a-z0-9-]+');

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

// Cheapest contracts page (must come BEFORE city catch-all)
Route::get('/sahkosopimus/halvin-sahkosopimus', CheapestContracts::class)
    ->name('cheapest.contracts');

// Company contracts page (must come BEFORE city catch-all)
Route::get('/sahkosopimus/yritykselle', CompanyContractsList::class)
    ->name('company.contracts');

// SEO Promotion/Offer Route (must come BEFORE city catch-all)
Route::get('/sahkosopimus/sahkotarjous', SeoContractsList::class)
    ->name('seo.offer.sahkotarjous')
    ->defaults('offerType', 'promotion');

// Company list page (all companies)
Route::get('/sahkosopimus/sahkoyhtiot', CompanyList::class)
    ->name('companies.list');

// Company detail page (canonical URL)
Route::get('/sahkosopimus/sahkoyhtiot/{companySlug}', CompanyDetail::class)
    ->name('company.detail');

// Main comparison index page (must come BEFORE city catch-all)
Route::get('/sahkosopimus', SahkosopimusIndex::class)
    ->name('sahkosopimus.index');

// SEO City Routes - 301 redirect from old URLs to new /paikkakunnat/ pattern
// (must come AFTER specific routes to avoid overriding them)
Route::get('/sahkosopimus/{city}', function ($city) {
    return redirect("/sahkosopimus/paikkakunnat/{$city}", 301);
})->where('city', '[a-z0-9-]+');

// =============================================================================
// 301 Redirects from old URLs to new URLs (for SEO preservation)
// =============================================================================

// Redirect old /laskuri to new location
Route::redirect('/laskuri', '/sahkosopimus/laskuri', 301);

// Redirect old /sopimus/{id} to new location
Route::get('/sopimus/{contractId}', function ($contractId) {
    return redirect()->route('contract.detail', ['contractId' => $contractId], 301);
});

// Redirect old /yritys/{slug} to new location under /sahkosopimus/sahkoyhtiot/
Route::get('/yritys/{companySlug}', function ($companySlug) {
    return redirect()->route('company.detail', ['companySlug' => $companySlug], 301);
});

// Redirect old /sahkosopimus/yritys/{slug} to new location under /sahkosopimus/sahkoyhtiot/
Route::get('/sahkosopimus/yritys/{companySlug}', function ($companySlug) {
    return redirect()->route('company.detail', ['companySlug' => $companySlug], 301);
});

// Redirect old /paikkakunnat to new location
Route::get('/paikkakunnat/{location?}', function ($location = null) {
    if ($location) {
        return redirect("/sahkosopimus/paikkakunnat/{$location}", 301);
    }
    return redirect()->route('locations', [], 301);
});
