<?php

use App\Livewire\ContractDetail;
use App\Livewire\ContractsList;
use Illuminate\Support\Facades\Route;

Route::get('/', ContractsList::class);
Route::get('/sopimus/{contractId}', ContractDetail::class)->name('contract.detail');
