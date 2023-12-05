<?php

use App\Http\Controllers\Databackd\Planning\BudgetController;
use Illuminate\Support\Facades\Route;

// Loaded by RouteServiceProvider::mapDatabackdRoutes() with the "account" prefix and the web/auth middleware.
Route::prefix('databackd/planning/budgets')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_planning')->name('databackd.planning.budgets.')->group(function () {
    Route::get('create', [BudgetController::class, 'create'])->name('create');
    Route::get('/', [BudgetController::class, 'index'])->name('index');
    Route::post('/', [BudgetController::class, 'store'])->name('store');
    Route::get('{budget}', [BudgetController::class, 'show'])->whereNumber('budget')->name('show');
    Route::post('{budget}/duplicate', [BudgetController::class, 'duplicate'])->whereNumber('budget')->name('duplicate');
    Route::post('{budget}/approve', [BudgetController::class, 'approve'])->whereNumber('budget')->name('approve');
    Route::delete('{budget}', [BudgetController::class, 'destroy'])->whereNumber('budget')->name('destroy');
});
