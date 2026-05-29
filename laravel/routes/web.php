<?php

use App\Livewire\AboutPage;
use App\Livewire\CheapestContracts;
use App\Livewire\CompanyDetail;
use App\Livewire\CompanyList;
use App\Livewire\ConsumptionCalculator;
use App\Livewire\ContractDetail;
use App\Http\Controllers\ContractPriceStatisticsCsvController;
use App\Http\Controllers\SpotPriceCsvController;
use App\Livewire\ContractPriceStatistics;
use App\Livewire\FixedContractPriceForecast;
use App\Livewire\ContractsList;
use App\Livewire\HomePage;
use App\Livewire\LocationsList;
use App\Livewire\SahkosopimusIndex;
use App\Livewire\SeoContractsList;
use App\Livewire\SolarCalculator;
use App\Livewire\SpotPrice;
use App\Services\SitemapService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Main pages (keep at root level)
Route::get('/', HomePage::class);
Route::get('/tietoa', AboutPage::class)->name('about');
Route::get('/spot-price', SpotPrice::class)
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
    ])
    ->name('spot-price');
Route::get('/spot-price.csv', SpotPriceCsvController::class)
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
    ])
    ->name('spot-price.csv');

// Sitemap (cached for 7 days)
Route::get('/sitemap.xml', function (SitemapService $sitemapService) {
    $xml = Cache::remember(SitemapService::CACHE_KEY, 604800, function () use ($sitemapService) {
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
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
    ])
    ->name('contract.detail');

// Old company detail URL - redirect to new location
// (Route added in redirects section below)

$publicListingWithoutMiddleware = [
    StartSession::class,
    ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
];

Route::withoutMiddleware($publicListingWithoutMiddleware)
    ->group(function () {
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
        Route::get('/sahkosopimus/kvartaalisahko', SeoContractsList::class)
            ->name('seo.pricing.kvartaalisahko')
            ->defaults('pricingType', 'Quarterly');
        Route::get('/sahkosopimus/aikasahko', SeoContractsList::class)
            ->name('seo.pricing.aikasahko')
            ->defaults('pricingType', 'TimeOfUse');
        Route::get('/sahkosopimus/kausisahko', SeoContractsList::class)
            ->name('seo.pricing.kausisahko')
            ->defaults('pricingType', 'Seasonal');
        Route::get('/sahkosopimus/joustosahko', SeoContractsList::class)
            ->name('seo.pricing.joustosahko')
            ->defaults('pricingType', 'Hybrid');
        Route::get('/sahkosopimus/yleissahko', SeoContractsList::class)
            ->name('seo.pricing.yleissahko')
            ->defaults('pricingType', 'GeneralElectricity');

        // Price statistics page (must come BEFORE city catch-all)
        Route::get('/sahkosopimus/tilastot', ContractPriceStatistics::class)
            ->name('contract.price-statistics');

        // CSV download for the statistics page (CC BY 4.0)
        Route::get('/sahkosopimus/tilastot.csv', ContractPriceStatisticsCsvController::class)
            ->name('contract.price-statistics.csv');

        // Fixed-term contract price forecast page (must come BEFORE city catch-all)
        Route::get('/sahkosopimus/sahkon-hintaennuste', FixedContractPriceForecast::class)
            ->name('contract.price-forecast');

        // Cheapest contracts page (must come BEFORE city catch-all)
        Route::get('/sahkosopimus/halvin-sahkosopimus', CheapestContracts::class)
            ->name('cheapest.contracts');

        // Company contracts page (must come BEFORE city catch-all)
        Route::get('/sahkosopimus/yritykselle', SeoContractsList::class)
            ->name('company.contracts')
            ->defaults('targetGroup', 'Company');

        // SEO Contract Duration Routes
        Route::get('/sahkosopimus/maaraaikainen', SeoContractsList::class)
            ->name('seo.duration.maaraaikainen')
            ->defaults('contractDuration', 'FixedTerm');
        Route::get('/sahkosopimus/toistaiseksi', SeoContractsList::class)
            ->name('seo.duration.toistaiseksi')
            ->defaults('contractDuration', 'OpenEnded');

        // SEO Energy Source Routes (additional)
        Route::get('/sahkosopimus/fossiiliton', SeoContractsList::class)
            ->name('seo.energy.fossiiliton')
            ->defaults('energySource', 'fossiiliton');
        Route::get('/sahkosopimus/uusiutuva-sahko', SeoContractsList::class)
            ->name('seo.energy.uusiutuva-sahko')
            ->defaults('energySource', 'uusiutuva-sahko');
        Route::get('/sahkosopimus/ydinvoima', SeoContractsList::class)
            ->name('seo.energy.ydinvoima')
            ->defaults('energySource', 'ydinvoima');

        // SEO Consumption Level Routes
        Route::get('/sahkosopimus/kulutus/2000-kwh', SeoContractsList::class)
            ->name('seo.consumption.2000')
            ->defaults('consumptionLevel', '2000');
        Route::get('/sahkosopimus/kulutus/5000-kwh', SeoContractsList::class)
            ->name('seo.consumption.5000')
            ->defaults('consumptionLevel', '5000');
        Route::get('/sahkosopimus/kulutus/10000-kwh', SeoContractsList::class)
            ->name('seo.consumption.10000')
            ->defaults('consumptionLevel', '10000');
        Route::get('/sahkosopimus/kulutus/18000-kwh', SeoContractsList::class)
            ->name('seo.consumption.18000')
            ->defaults('consumptionLevel', '18000');
        Route::get('/sahkosopimus/kulutus/20000-kwh', SeoContractsList::class)
            ->name('seo.consumption.20000')
            ->defaults('consumptionLevel', '20000');

        // SEO Promotion/Offer Route (must come BEFORE city catch-all)
        Route::get('/sahkosopimus/sahkotarjous', SeoContractsList::class)
            ->name('seo.offer.sahkotarjous')
            ->defaults('offerType', 'promotion');

        // Company list page (all companies)
        Route::get('/sahkosopimus/sahkoyhtiot', CompanyList::class)
            ->name('companies.list');
    });

// Company detail page (canonical URL)
Route::get('/sahkosopimus/sahkoyhtiot/{companySlug}', CompanyDetail::class)
    ->name('company.detail');

// SEO Article: Kannattaako pörssisähkö
Route::get('/sahkosopimus/kannattaako-porssisahko', \App\Livewire\ArticleSpotElectricity::class)
    ->name('article.spot-electricity');

// SEO Article: Kannattaako määräaikainen sähkösopimus
Route::get('/sahkosopimus/kannattaako-maaraaikainen', \App\Livewire\ArticleFixedTermContract::class)
    ->name('article.fixed-term-contract');

// Main comparison index page (must come BEFORE city catch-all)
Route::get('/sahkosopimus', SahkosopimusIndex::class)
    ->withoutMiddleware($publicListingWithoutMiddleware)
    ->name('sahkosopimus.index');

// SEO City Routes - 301 redirect from old URLs to new /paikkakunnat/ pattern
// (must come AFTER specific routes to avoid overriding them)
Route::get('/sahkosopimus/{city}', function ($city) {
    return redirect("/sahkosopimus/paikkakunnat/{$city}", 301);
})->where('city', '[a-z0-9-]+');

// =============================================================================
// 301 Redirects from old URLs to new URLs (for SEO preservation)
// =============================================================================

// Redirect removed kiintea-hinta page to main comparison page
Route::redirect('/sahkosopimus/kiintea-hinta', '/sahkosopimus', 301);

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

// =============================================================================
// Temporary test route for Contract Type Comparison widget (remove after testing)
// =============================================================================
Route::get('/test-comparison', \App\Livewire\ContractTypeComparison::class)
    ->name('test.comparison');
