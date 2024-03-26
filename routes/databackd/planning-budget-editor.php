<?php

use App\Http\Controllers\Databackd\Planning\BudgetEditorController;
use Illuminate\Support\Facades\Route;

// Loaded by RouteServiceProvider::mapDatabackdRoutes() with the "account" prefix and the web/auth middleware.
Route::prefix('databackd/planning/budget-editor')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_planning')->name('databackd.planning.budget-editor.')->group(function () {
    Route::get('{budget}/edit', [BudgetEditorController::class, 'edit'])->whereNumber('budget')->name('edit');
    Route::post('{budget}/save', [BudgetEditorController::class, 'save'])->whereNumber('budget')->name('save');
    Route::put('{budget}/cell', [BudgetEditorController::class, 'cell'])->whereNumber('budget')->name('cell');
});
