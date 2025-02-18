<?php

use App\Http\Controllers\Databackd\Planning\ForecastController;
use Illuminate\Support\Facades\Route;

// Loaded by RouteServiceProvider::mapDatabackdRoutes() with the "account" prefix and the web/auth middleware.
Route::prefix('databackd/planning/forecasts')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_planning')->name('databackd.planning.forecasts.')->group(function () {
    Route::get('data', [ForecastController::class, 'data'])->name('data');
    Route::get('saved', [ForecastController::class, 'saved'])->name('saved');
    Route::get('/', [ForecastController::class, 'index'])->name('index');
    Route::post('/', [ForecastController::class, 'store'])->name('store');
    Route::get('{id}', [ForecastController::class, 'show'])->whereNumber('id')->name('show');
    Route::delete('{id}', [ForecastController::class, 'destroy'])->whereNumber('id')->name('destroy');
});
