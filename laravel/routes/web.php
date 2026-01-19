<?php

use App\Livewire\CompanyDetail;
use App\Livewire\ContractDetail;
use App\Livewire\ContractsList;
use App\Livewire\LocationsList;
use App\Livewire\SeoContractsList;
use App\Livewire\SpotPrice;
use Illuminate\Support\Facades\Route;

Route::get('/', ContractsList::class);
Route::get('/sopimus/{contractId}', ContractDetail::class)->name('contract.detail');
Route::get('/paikkakunnat/{location?}', LocationsList::class)->name('locations');
Route::get('/yritys/{companySlug}', CompanyDetail::class)->name('company.detail');
Route::get('/spot-price', SpotPrice::class)->name('spot-price');

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

// SEO City Routes (must come AFTER specific routes to avoid overriding them)
Route::get('/sahkosopimus/{city}', SeoContractsList::class)
    ->name('seo.city')
    ->where('city', '[a-z0-9-]+');
