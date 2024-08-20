<?php

use App\Http\Controllers\Databackd\Planning\BudgetVarianceController;
use Illuminate\Support\Facades\Route;

// Loaded by RouteServiceProvider::mapDatabackdRoutes() with the "account" prefix and the web/auth middleware.
Route::prefix('databackd/planning/variance')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_planning')->name('databackd.planning.variance.')->group(function () {
    Route::get('export', [BudgetVarianceController::class, 'export'])->name('export');
    Route::get('/', [BudgetVarianceController::class, 'index'])->name('index');
});
