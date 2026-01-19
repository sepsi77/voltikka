<?php

use App\Http\Controllers\Api\CalculationController;
use App\Http\Controllers\Api\ContractController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

// Contract routes
Route::get('/contracts', [ContractController::class, 'index']);
Route::get('/contracts/{id}', [ContractController::class, 'show']);

// Calculation routes
Route::post('/calculate-price', [CalculationController::class, 'calculatePrice']);
Route::post('/estimate-consumption', [CalculationController::class, 'estimateConsumption']);
